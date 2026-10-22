<?php

namespace Tests\Feature\Mobility;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use STS\Mobility\Dispatch\DispatchWorker;
use STS\Mobility\Routing\RouteProvider;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\User;
use Tests\TestCase;

/** Failure and recovery contracts for the bounded dispatch worker. */
final class MobilityDispatchFailureTest extends TestCase
{
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20 12:00:00');
        \Illuminate\Support\Carbon::setTestNow('2026-10-20 12:00:00');
        foreach (['MOBILITY_COHORT_REQUIRED' => 'true', 'MOBILITY_COLLEGE_CODE' => 'CAMPUS-BLR'] as $key => $value) {
            $this->previous[$key] = getenv($key);
            putenv($key.'='.$value);
        }
        config(['app.timezone' => 'Asia/Kolkata', 'mobility_routing.pricing_version' => 'dispatch-failure-v1', 'mobility_routing.car_minor_per_km' => 100, 'mobility_routing.synthetic_only' => true]);
        $this->app->bind(RouteProvider::class, fn (): RouteProvider => new class implements RouteProvider
        {
            public function route(array $pickup, array $dropoff, string $vehicleType): array
            {
                return ['distance_meters' => 2500, 'duration_seconds' => 600, 'provider' => 'dispatch-failure', 'profile' => $vehicleType];
            }
        });
        foreach ([[701, 1701, 'Failure A'], [702, 1702, 'Failure B']] as [$id, $node, $name]) {
            DB::table('nodes_geo')->insert(['id' => $node, 'name' => $name, 'lat' => 13.01 + ($id - 701) / 100, 'lng' => 77.51 + ($id - 701) / 100, 'type' => 'synthetic']);
            DB::table('mobility_pickup_points')->insert(['id' => $id, 'college_code' => 'CAMPUS-BLR', 'canonical_node_id' => $node, 'version' => 1, 'name' => $name, 'lat' => 13.01 + ($id - 701) / 100, 'lng' => 77.51 + ($id - 701) / 100, 'vehicle_modes' => json_encode(['car']), 'active' => true, 'verified_at' => now(), 'synthetic_only' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        foreach ($this->previous as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
        parent::tearDown();
    }

    public function test_stale_heartbeat_and_offline_acceptance_fail_without_orphans(): void
    {
        [$rider, $driver, $car] = $this->actors();
        $this->availability($driver, $car, 701);
        [$request, $offer] = $this->requestAndOffer($rider, $driver);
        DB::table('mobility_dispatch_availability')->where('driver_id', $driver->id)->update(['fresh_until' => now('UTC')->subSecond()]);
        $this->actingAsApiUser($driver)->postJson('/api/mobility/dispatch/offers/'.$offer.'/accept', [], ['Idempotency-Key' => 'failure-stale-accept'])->assertStatus(409)->assertJsonPath('error.code', 'DRIVER_UNAVAILABLE');
        $this->assertNoAssignment($request);

        DB::table('mobility_dispatch_availability')->where('driver_id', $driver->id)->update(['fresh_until' => now('UTC')->addMinute(), 'online' => false]);
        $this->actingAsApiUser($driver)->postJson('/api/mobility/dispatch/offers/'.$offer.'/accept', [], ['Idempotency-Key' => 'failure-offline-accept'])->assertStatus(409)->assertJsonPath('error.code', 'DRIVER_UNAVAILABLE');
        $this->assertNoAssignment($request);
    }

    public function test_membership_approval_fingerprint_and_catalog_changes_fail_closed(): void
    {
        $mutations = [
            'membership' => function (User $rider, User $driver, Car $car): void {
                DB::table('mobility_memberships')->where('user_id', $rider->id)->update(['status' => 'revoked']);
            },
            'approval' => function (User $rider, User $driver, Car $car): void {
                DB::table('mobility_host_applications')->where('user_id', $driver->id)->update(['status' => 'suspended']);
            },
            'fingerprint' => function (User $rider, User $driver, Car $car): void {
                $car->forceFill(['patente' => 'FAILURE-CHANGED'])->save();
            },
            'catalog' => function (User $rider, User $driver, Car $car): void {
                DB::table('mobility_pickup_points')->where('id', 701)->update(['active' => false]);
            },
            'cohort' => function (User $rider, User $driver, Car $car): void {
                DB::table('mobility_memberships')->where('user_id', $driver->id)->update(['college_code' => 'OTHER-COLLEGE']);
            },
        ];
        $expected = ['membership' => 'MEMBERSHIP_REQUIRED', 'approval' => 'DRIVER_VEHICLE_CHANGED', 'fingerprint' => 'DRIVER_VEHICLE_CHANGED', 'catalog' => 'MATCH_ROUTE_CHANGED', 'cohort' => 'MEMBERSHIP_REQUIRED'];
        foreach ($mutations as $name => $mutate) {
            [$rider, $driver, $car] = $this->actors();
            $this->availability($driver, $car, 701);
            [$request, $offer] = $this->requestAndOffer($rider, $driver, $name);
            $mutate($rider, $driver, $car);
            $this->actingAsApiUser($driver)->postJson('/api/mobility/dispatch/offers/'.$offer.'/accept', [], ['Idempotency-Key' => 'failure-'.$name])->assertStatus(in_array($name, ['membership', 'cohort'], true) ? 403 : 409)->assertJsonPath('error.code', $expected[$name]);
            $this->assertNoAssignment($request);
            if ($name === 'catalog') {
                DB::table('mobility_pickup_points')->where('id', 701)->update(['active' => true]);
            }
        }
    }

    public function test_deadline_acceptance_and_worker_expiry_are_terminal_and_replay_safe(): void
    {
        [$rider, $driver, $car] = $this->actors();
        $this->availability($driver, $car, 701);
        [$request, $offer] = $this->requestAndOffer($rider, $driver);
        DB::table('mobility_dispatch_offers')->where('id', $offer)->update(['expires_at' => now('UTC')]);
        DB::table('mobility_dispatch_requests')->where('id', $request)->update(['expires_at' => now('UTC'), 'next_attempt_at' => now('UTC')]);
        $this->actingAsApiUser($driver)->postJson('/api/mobility/dispatch/offers/'.$offer.'/accept', [], ['Idempotency-Key' => 'failure-deadline-accept'])->assertStatus(409)->assertJsonPath('error.code', 'OFFER_EXPIRED');
        app(DispatchWorker::class)->process(20);
        app(DispatchWorker::class)->process(20);
        $this->assertDatabaseHas('mobility_dispatch_offers', ['id' => $offer, 'state' => 'expired']);
        $this->assertDatabaseHas('mobility_dispatch_requests', ['id' => $request, 'state' => 'expired']);
        $this->assertNoAssignment($request);
    }

    public function test_cancel_and_accept_terminal_order_has_one_canonical_outcome_and_recovery_is_stable(): void
    {
        [$rider, $driver, $car] = $this->actors();
        $this->availability($driver, $car, 701);
        [$request, $offer] = $this->requestAndOffer($rider, $driver, 'cancel-first');
        $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests/'.$request.'/cancel', [], ['Idempotency-Key' => 'failure-cancel-first'])->assertOk()->assertJsonPath('state', 'cancelled');
        $this->actingAsApiUser($driver)->postJson('/api/mobility/dispatch/offers/'.$offer.'/accept', [], ['Idempotency-Key' => 'failure-cancel-accept'])->assertStatus(409);
        $this->assertNoAssignment($request);

        $this->availability($driver, $car, 701);
        [$request2, $offer2] = $this->requestAndOffer($rider, $driver, 'accept-first');
        $accepted = $this->actingAsApiUser($driver)->postJson('/api/mobility/dispatch/offers/'.$offer2.'/accept', [], ['Idempotency-Key' => 'failure-accept-first'])->assertOk()->json();
        $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests/'.$request2.'/cancel', [], ['Idempotency-Key' => 'failure-accept-cancel'])->assertOk()->assertJsonPath('state', 'cancelled');
        app(DispatchWorker::class)->process(20);
        app(DispatchWorker::class)->process(20);
        $this->assertDatabaseCount('mobility_dispatch_assignments', 1);
        $this->assertDatabaseHas('mobility_dispatch_assignments', ['id' => $accepted['id'], 'active_request_id' => null, 'active_driver_id' => null]);
    }

    private function actors(): array
    {
        $rider = $this->member('failure-rider-'.uniqid());
        $driver = $this->member('failure-driver-'.uniqid());
        $car = Car::factory()->withCatalog()->create(['user_id' => $driver->id]);
        DB::table('mobility_host_applications')->insert(['user_id' => $driver->id, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$rider, $driver, $car];
    }

    private function availability(User $driver, Car $car, int $point): void
    {
        $this->actingAsApiUser($driver)->putJson('/api/mobility/dispatch/availability', ['online' => true, 'car_id' => $car->id, 'pickup_point_id' => $point])->assertOk();
    }

    private function requestAndOffer(User $rider, User $driver, string $suffix = 'base'): array
    {
        $quote = $this->actingAsApiUser($rider)->postJson('/api/mobility/routes/quote', ['stop_ids' => [701, 702], 'vehicle_type' => 'car'])->assertCreated()->json('data.id');
        $request = $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $quote], ['Idempotency-Key' => 'failure-request-'.$suffix.'-'.uniqid()])->assertCreated()->json('id');
        app(DispatchWorker::class)->process(20);
        $offer = DB::table('mobility_dispatch_offers')->where('request_id', $request)->where('driver_id', $driver->id)->value('id');
        $this->assertNotNull($offer);

        return [$request, $offer];
    }

    private function assertNoAssignment(string $request): void
    {
        $assignments = DB::table('mobility_dispatch_assignments')->where('request_id', $request)->get(['trip_id', 'booking_id']);
        $this->assertCount(0, $assignments, 'dispatch assignment must not survive a denied accept');
        $offerDriverIds = DB::table('mobility_dispatch_offers')->where('request_id', $request)->pluck('driver_id');
        $createdAt = DB::table('mobility_dispatch_requests')->where('id', $request)->value('created_at');
        $orphanTrips = DB::table('trips')->whereIn('user_id', $offerDriverIds)->where('created_at', '>=', $createdAt)->pluck('id');
        $this->assertCount(0, $orphanTrips, 'no canonical trip may be created for a denied dispatch offer');
        $this->assertSame(0, DB::table('trip_passengers')->whereIn('trip_id', $orphanTrips)->count(), 'denied dispatch has no canonical booking');
    }

    private function member(string $label): User
    {
        $user = User::factory()->create(['active' => true, 'banned' => false, 'identity_validated' => true, 'nro_doc' => 'SYNTHETIC-'.$label, 'mobile_phone' => '0000000000']);
        DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }
}
