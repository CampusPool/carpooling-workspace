<?php

namespace Tests\Feature\Mobility;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use STS\Mobility\Dispatch\DispatchInputs;
use STS\Mobility\Dispatch\DispatchWorker;
use STS\Mobility\Routing\RouteProvider;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\User;
use Tests\TestCase;

final class MobilityDispatchServiceTest extends TestCase
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
        config(['app.timezone' => 'Asia/Kolkata', 'mobility_routing.pricing_version' => 'dispatch-test-v1', 'mobility_routing.car_minor_per_km' => 100, 'mobility_routing.synthetic_only' => true]);
        $this->app->bind(RouteProvider::class, fn (): RouteProvider => new class implements RouteProvider
        {
            public function route(array $pickup, array $dropoff, string $vehicleType): array
            {
                return ['distance_meters' => 1501, 'duration_seconds' => 120, 'provider' => 'dispatch-test', 'profile' => 'car'];
            }
        });
        foreach ([[501, 'Stop A'], [502, 'Stop B']] as [$id, $name]) {
            DB::table('nodes_geo')->insert(['id' => $id, 'name' => $name, 'lat' => 13.0 + ($id - 500) / 100, 'lng' => 77.5 + ($id - 500) / 100, 'type' => 'synthetic']);
        }
        foreach ([[101, 501, 'Stop A'], [102, 502, 'Stop B']] as [$id, $node, $name]) {
            DB::table('mobility_pickup_points')->insert(['id' => $id, 'college_code' => 'CAMPUS-BLR', 'canonical_node_id' => $node, 'version' => 1, 'name' => $name, 'lat' => 13.0 + ($node - 500) / 100, 'lng' => 77.5 + ($node - 500) / 100, 'vehicle_modes' => json_encode(['car']), 'active' => true, 'verified_at' => now(), 'synthetic_only' => true, 'created_at' => now(), 'updated_at' => now()]);
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

    public function test_request_worker_offer_accept_persists_one_canonical_assignment(): void
    {
        [$rider, $driverA, $driverB] = $this->actors();
        $quote = $this->quote($rider);
        $this->availability($driverA, 101);
        $this->availability($driverB, 101);
        $request = $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $quote], ['Idempotency-Key' => 'dispatch-request-001'])->assertCreated()->json('id');
        $this->assertSame(1, app(DispatchWorker::class)->process(20));
        $offers = $this->actingAsApiUser($driverA)->getJson('/api/mobility/dispatch/offers')->assertOk()->json('data');
        $this->assertCount(1, $offers);
        $this->assertArrayNotHasKey('rider_id', $offers[0]);
        $accepted = $this->actingAsApiUser($driverA)->postJson('/api/mobility/dispatch/offers/'.$offers[0]['id'].'/accept', [], ['Idempotency-Key' => 'dispatch-accept-001'])->assertOk();
        $this->assertDatabaseCount('mobility_dispatch_assignments', 1);
        $this->assertDatabaseHas('mobility_dispatch_requests', ['id' => $request, 'state' => 'assigned']);
        $this->assertDatabaseHas('mobility_dispatch_events', ['action' => 'offer.accepted']);
        $this->assertNotNull($accepted->json('trip_id'));
        $this->assertNotNull($accepted->json('booking_id'));
        $this->actingAsApiUser($driverB)->postJson('/api/mobility/dispatch/offers/'.$offers[0]['id'].'/accept', [], ['Idempotency-Key' => 'dispatch-accept-foreign'])->assertNotFound();
    }

    public function test_same_rider_key_replays_after_membership_revocation_and_unknown_body_is_rejected(): void
    {
        [$rider] = $this->actors();
        $quote = $this->quote($rider);
        $first = $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $quote], ['Idempotency-Key' => 'dispatch-request-002'])->assertCreated();
        DB::table('mobility_memberships')->where('user_id', $rider->id)->update(['status' => 'revoked']);
        $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $quote], ['Idempotency-Key' => 'dispatch-request-002'])->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests/'.$first->json('id').'/cancel', ['unexpected' => true], ['Idempotency-Key' => 'dispatch-cancel-002'])->assertStatus(422);
    }

    public function test_expired_offers_are_not_reoffered(): void
    {
        [$rider, $driver] = $this->actors();
        $quote = $this->quote($rider);
        $this->availability($driver, 101);
        $id = $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $quote], ['Idempotency-Key' => 'dispatch-request-003'])->assertCreated()->json('id');
        app(DispatchWorker::class)->process(20);
        $offer = DB::table('mobility_dispatch_offers')->where('request_id', $id)->first();
        DB::table('mobility_dispatch_offers')->where('id', $offer->id)->update(['expires_at' => now('UTC')->subSecond()]);
        DB::table('mobility_dispatch_requests')->where('id', $id)->update(['next_attempt_at' => now('UTC')->subSecond()]);
        app(DispatchWorker::class)->process(20);
        $this->assertDatabaseHas('mobility_dispatch_offers', ['id' => $offer->id, 'state' => 'expired']);
        $this->assertSame(1, DB::table('mobility_dispatch_offers')->where('request_id', $id)->count());
    }

    public function test_input_contract_remains_strict(): void
    {
        $this->expectException(\STS\Mobility\Dispatch\DispatchException::class);
        DispatchInputs::fields(['quote_id' => 'x', 'fare_minor' => 1], ['quote_id']);
    }

    public function test_nonsynthetic_snapshot_without_graph_identity_is_rejected_before_dispatch_request(): void
    {
        [$rider] = $this->actors();
        $quote = $this->quote($rider);
        $snapshot = json_decode(DB::table('mobility_route_quotes')->where('id', $quote)->value('snapshot'), true);
        $snapshot['synthetic_only'] = false;
        unset($snapshot['data_version']);
        DB::table('mobility_route_quotes')->where('id', $quote)->update(['snapshot' => json_encode($snapshot)]);
        $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $quote], ['Idempotency-Key' => 'missing-dispatch-graph'])->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_INVALID');
        $this->assertDatabaseCount('mobility_dispatch_requests', 0);
    }

    public function test_driver_cancel_reassignment_keeps_original_quote_and_retry_cannot_cancel_new_trip(): void
    {
        [$rider, $a, $b] = $this->actors();
        $this->availability($a, 101);
        $this->availability($b, 101);
        $quote = $this->quote($rider);
        $id = $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $quote], ['Idempotency-Key' => 'reassign-request-key'])->assertCreated()->json('id');
        app(DispatchWorker::class)->process();
        $offerA = DB::table('mobility_dispatch_offers')->where('request_id', $id)->where('driver_id', $a->id)->value('id');
        $first = $this->actingAsApiUser($a)->postJson('/api/mobility/dispatch/offers/'.$offerA.'/accept', [], ['Idempotency-Key' => 'reassign-accept-a'])->assertOk()->json();
        $cancel = $this->actingAsApiUser($a)->postJson('/api/mobility/dispatch/requests/'.$id.'/cancel', [], ['Idempotency-Key' => 'reassign-cancel-a'])->assertOk()->assertJsonPath('state', 'searching')->json();
        // B's first offer was revoked when A accepted. It may receive a new attempt,
        // while declined/timed-out drivers are never offered again.
        app(DispatchWorker::class)->process();
        $offerB = DB::table('mobility_dispatch_offers')->where('request_id', $id)->where('driver_id', $b->id)->where('state', 'pending')->value('id');
        $this->assertNotNull($offerB);
        $second = $this->actingAsApiUser($b)->postJson('/api/mobility/dispatch/offers/'.$offerB.'/accept', [], ['Idempotency-Key' => 'reassign-accept-b'])->assertOk()->json();
        $this->assertNotSame($first['trip_id'], $second['trip_id']);
        $this->actingAsApiUser($a)->postJson('/api/mobility/dispatch/requests/'.$id.'/cancel', [], ['Idempotency-Key' => 'reassign-cancel-a'])->assertOk()->assertExactJson($cancel);
        $this->assertDatabaseHas('mobility_trip_lifecycles', ['trip_id' => $second['trip_id'], 'state' => 'planned']);
        $this->assertDatabaseHas('mobility_route_quotes', ['id' => $quote, 'consumed_trip_id' => $first['trip_id']]);
        $this->assertSame(2, DB::table('mobility_dispatch_assignments')->where('request_id', $id)->count());
    }

    public function test_second_active_request_and_changed_payload_key_are_conflicts(): void
    {
        [$rider] = $this->actors();
        $one = $this->quote($rider);
        $two = $this->quote($rider);
        $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $one], ['Idempotency-Key' => 'active-request-one'])->assertCreated();
        $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $two], ['Idempotency-Key' => 'active-request-two'])->assertStatus(409)->assertJsonPath('error.code', 'RIDER_REQUEST_ACTIVE');
        $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $two], ['Idempotency-Key' => 'active-request-one'])->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount('mobility_dispatch_requests', 1);
    }

    public function test_failed_link_commit_rolls_back_factory_quote_and_assignment_together(): void
    {
        [$rider, $driver] = $this->actors();
        $this->availability($driver, 101);
        $quote = $this->quote($rider);
        $id = $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $quote], ['Idempotency-Key' => 'rollback-request-key'])->assertCreated()->json('id');
        app(DispatchWorker::class)->process();
        $offer = DB::table('mobility_dispatch_offers')->where('request_id', $id)->where('driver_id', $driver->id)->value('id');
        $fail = true;
        DB::listen(function ($query) use (&$fail): void {
            if ($fail && str_contains(strtolower($query->sql), 'insert into `mobility_dispatch_assignments`')) {
                $fail = false;
                throw new \RuntimeException('injected assignment commit failure');
            }
        });
        try {
            app(\STS\Mobility\Dispatch\DispatchService::class)->accept($driver->id, $offer, 'rollback-accept-key');
            $this->fail('Fault injection must abort acceptance');
        } catch (\RuntimeException $error) {
            $this->assertSame('injected assignment commit failure', $error->getMessage());
        }
        $this->assertSame(0, DB::table('trips')->where('user_id', $driver->id)->count());
        $this->assertDatabaseCount('mobility_dispatch_assignments', 0);
        $this->assertDatabaseHas('mobility_route_quotes', ['id' => $quote, 'consumed_trip_id' => null, 'dispatch_request_id' => $id]);
        $this->assertDatabaseHas('mobility_dispatch_offers', ['id' => $offer, 'state' => 'pending']);
    }

    public function test_canonical_cancel_reconciles_and_dispatch_guards_block_pool_mutation_and_wrong_fare(): void
    {
        [$rider, $driver] = $this->actors();
        $this->availability($driver, 101);
        $quote = $this->quote($rider);
        $id = $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $quote], ['Idempotency-Key' => 'guard-request-key'])->assertCreated()->json('id');
        app(DispatchWorker::class)->process();
        $offer = DB::table('mobility_dispatch_offers')->where('request_id', $id)->where('driver_id', $driver->id)->value('id');
        $created = $this->actingAsApiUser($driver)->postJson('/api/mobility/dispatch/offers/'.$offer.'/accept', [], ['Idempotency-Key' => 'guard-accept-key'])->assertOk()->json();
        $trip = \STS\Models\Trip::findOrFail($created['trip_id']);
        $booking = \STS\Models\Passenger::findOrFail($created['booking_id']);
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$trip->id.'/requests')->assertStatus(409);
        $this->actingAsApiUser($driver)->putJson('/api/trips/'.$trip->id, ['total_seats' => 5])->assertStatus(409);
        try {
            app(\STS\Mobility\Payments\PaymentService::class)->snapshotFare($trip, $booking, 999);
            $this->fail('Forged dispatch fare');
        } catch (\STS\Mobility\Payments\PaymentException $error) {
            $this->assertSame('FARE_MISMATCH', $error->codeName);
        }
        $fare = app(\STS\Mobility\Payments\PaymentService::class)->snapshotFare($trip, $booking, 151);
        $this->assertSame(151, (int) $fare->gross_amount);
        $this->actingAsApiUser($rider)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'cancel'], ['Idempotency-Key' => 'guard-canonical-cancel'])->assertOk();
        app(DispatchWorker::class)->process();
        $this->assertDatabaseHas('mobility_dispatch_requests', ['id' => $id, 'state' => 'closed', 'active_rider_id' => null]);
        $this->assertDatabaseHas('mobility_dispatch_availability', ['driver_id' => $driver->id, 'active_request_id' => null]);
        $this->assertDatabaseHas('mobility_payment_blocks', ['booking_id' => $booking->id, 'reason' => 'trip_cancelled']);
    }

    public function test_decline_is_durable_and_deadline_equality_denies_both_accept_and_decline(): void
    {
        [$rider, $a, $b] = $this->actors();
        $this->availability($a, 101);
        $this->availability($b, 101);
        $quote = $this->quote($rider);
        $id = $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $quote], ['Idempotency-Key' => 'decline-request-key'])->assertCreated()->json('id');
        app(DispatchWorker::class)->process();
        $offerA = DB::table('mobility_dispatch_offers')->where('request_id', $id)->where('driver_id', $a->id)->value('id');
        $offerB = DB::table('mobility_dispatch_offers')->where('request_id', $id)->where('driver_id', $b->id)->value('id');
        $this->actingAsApiUser($a)->postJson('/api/mobility/dispatch/offers/'.$offerA.'/decline', [], ['Idempotency-Key' => 'decline-action-key'])->assertOk()->assertJsonPath('state', 'declined');
        $this->actingAsApiUser($a)->postJson('/api/mobility/dispatch/offers/'.$offerA.'/decline', [], ['Idempotency-Key' => 'decline-action-key'])->assertOk()->assertJsonPath('state', 'declined');
        DB::table('mobility_dispatch_offers')->where('id', $offerB)->update(['expires_at' => now('UTC')]);
        $this->actingAsApiUser($b)->postJson('/api/mobility/dispatch/offers/'.$offerB.'/accept', [], ['Idempotency-Key' => 'deadline-accept-key'])->assertStatus(409)->assertJsonPath('error.code', 'OFFER_EXPIRED');
        $this->actingAsApiUser($b)->postJson('/api/mobility/dispatch/offers/'.$offerB.'/decline', [], ['Idempotency-Key' => 'deadline-decline-key'])->assertStatus(409)->assertJsonPath('error.code', 'OFFER_EXPIRED');
        $this->assertDatabaseCount('mobility_dispatch_assignments', 0);
    }

    public function test_sequential_requests_after_cancellation_do_not_share_legacy_timestamp_identity(): void
    {
        [$rider, $driver] = $this->actors();
        $service = app(\STS\Mobility\Dispatch\DispatchService::class);
        $trips = [];
        foreach ([1, 2] as $iteration) {
            $this->availability($driver, 101);
            $request = $service->createRequest($rider->id, 'timestamp-request-'.$iteration, ['quote_id' => $this->quote($rider)]);
            app(DispatchWorker::class)->process();
            $offer = DB::table('mobility_dispatch_offers')->where('request_id', $request['id'])->where('driver_id', $driver->id)->value('id');
            $assigned = $service->accept($driver->id, $offer, 'timestamp-accept-'.$iteration);
            $trips[] = $assigned['trip_id'];
            $service->cancel($rider->id, $request['id'], 'timestamp-cancel-'.$iteration);
        }
        $this->assertNotSame($trips[0], $trips[1]);
        $this->assertSame(2, DB::table('trips')->where('user_id', $driver->id)->count());
        $this->assertSame(2, DB::table('mobility_dispatch_assignments')->where('driver_id', $driver->id)->count());
        $this->assertSame(1, DB::table('trips')->whereIn('id', $trips)->distinct()->count('trip_date'));
    }

    public function test_cross_mode_driver_obligations_fence_both_directions_and_release_only_after_cancellation(): void
    {
        [$scheduledRider, $driver] = $this->actors();
        config(['mobility_routing.driver_reservation_buffer_seconds' => 600]);
        $carId = (int) DB::table('cars')->where('user_id', $driver->id)->value('id');
        $publish = function (int $minutes, string $key) use ($driver, $carId): int {
            $quote = $this->quote($driver);
            return (int) $this->actingAsApiUser($driver)->postJson('/api/mobility/pool-offers', ['quote_id' => $quote, 'car_id' => $carId, 'trip_date' => now('Asia/Kolkata')->addMinutes($minutes)->format('Y-m-d\TH:i'), 'total_seats' => 1], ['Idempotency-Key' => $key])->assertCreated()->json('data.id');
        };
        $scheduled = $publish(16, 'cross-scheduled');
        $this->actingAsApiUser($scheduledRider)->postJson('/api/trips/'.$scheduled.'/requests')->assertOk();
        $this->actingAsApiUser($driver)->postJson('/api/trips/'.$scheduled.'/requests/'.$scheduledRider->id.'/accept')->assertOk();
        $demandRider = $this->member('cross-demand');
        $this->availability($driver, 101);
        $request = $this->actingAsApiUser($demandRider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $this->quote($demandRider)], ['Idempotency-Key' => 'cross-demand-request'])->assertCreated()->json('id');
        app(DispatchWorker::class)->process();
        $offer = DB::table('mobility_dispatch_offers')->where('request_id', $request)->where('driver_id', $driver->id)->firstOrFail();
        $path = '/api/mobility/dispatch/offers/'.$offer->id.'/accept'; $headers = ['Idempotency-Key' => 'cross-demand-accept'];
        $this->actingAsApiUser($driver)->postJson($path, [], $headers)->assertStatus(409)->assertJsonPath('error.code', 'DRIVER_ROUTE_OVERLAP');
        $this->assertDatabaseCount('mobility_dispatch_assignments', 0);
        $this->actingAsApiUser($scheduledRider)->postJson('/api/trips/'.$scheduled.'/requests/'.$scheduledRider->id.'/cancel')->assertOk();
        $assigned = $this->actingAsApiUser($driver)->postJson($path, [], $headers)->assertOk();
        $this->actingAsApiUser($driver)->postJson($path, [], $headers)->assertOk()->assertExactJson($assigned->json());
        $future = $publish(2880, 'cross-future');
        $this->actingAsApiUser($scheduledRider)->postJson('/api/trips/'.$future.'/requests')->assertOk();
        $this->actingAsApiUser($driver)->postJson('/api/trips/'.$future.'/requests/'.$scheduledRider->id.'/accept')->assertStatus(409)->assertJsonPath('error.code', 'DRIVER_ACTIVE_TRIP');
        $this->actingAsApiUser($demandRider)->postJson('/api/mobility/dispatch/requests/'.$request.'/cancel', [], ['Idempotency-Key' => 'cross-demand-cancel'])->assertOk();
        $this->actingAsApiUser($driver)->postJson('/api/trips/'.$future.'/requests/'.$scheduledRider->id.'/accept')->assertOk();
    }

    public function test_safety_block_does_not_rewrite_already_accepted_catalog_dispatch_obligations(): void
    {
        [$rider, $driver] = $this->actors();
        $this->availability($driver, 101);
        $request = $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $this->quote($rider)], ['Idempotency-Key' => 'catalog-block-request'])->assertCreated()->json('id');
        app(DispatchWorker::class)->process();
        $offer = DB::table('mobility_dispatch_offers')->where('request_id', $request)->where('driver_id', $driver->id)->firstOrFail();
        $trip = $this->actingAsApiUser($driver)->postJson('/api/mobility/dispatch/offers/'.$offer->id.'/accept', [], ['Idempotency-Key' => 'catalog-block-accept'])->assertOk()->json('trip_id');
        $this->actingAsApiUser($rider)->postJson('/api/mobility/trips/'.$trip.'/blocks', ['target_user_id' => $driver->id, 'blocked' => true], ['Idempotency-Key' => 'catalog-block-after-accept'])->assertOk();
        $this->actingAsApiUser($driver)->postJson('/api/mobility/trips/'.$trip.'/transitions', ['action' => 'start'], ['Idempotency-Key' => 'catalog-block-start'])->assertOk();
        $this->assertDatabaseHas('mobility_trip_lifecycles', ['trip_id' => $trip, 'state' => 'in_progress']);
    }

    private function actors(): array
    {
        $rider = $this->member('dispatch-rider');
        $drivers = [];
        foreach (['dispatch-driver-a', 'dispatch-driver-b'] as $label) {
            $driver = $this->member($label);
            $car = Car::factory()->withCatalog()->create(['user_id' => $driver->id]);
            DB::table('mobility_host_applications')->insert(['user_id' => $driver->id, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $drivers[] = [$driver, $car];
        }

        return [$rider, $drivers[0][0], $drivers[1][0]];
    }

    private function quote(User $rider): string
    {
        return $this->actingAsApiUser($rider)->postJson('/api/mobility/routes/quote', ['stop_ids' => [101, 102], 'vehicle_type' => 'car'])->assertCreated()->json('data.id');
    }

    private function availability(User $driver, int $pickup): void
    {
        $car = DB::table('cars')->where('user_id', $driver->id)->first();
        $this->actingAsApiUser($driver)->putJson('/api/mobility/dispatch/availability', ['online' => true, 'car_id' => $car->id, 'pickup_point_id' => $pickup])->assertOk();
    }

    private function member(string $label): User
    {
        $user = User::factory()->create(['active' => true, 'banned' => false, 'identity_validated' => true, 'nro_doc' => 'SYNTHETIC-'.$label, 'mobile_phone' => '0000000000']);
        DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }
}
