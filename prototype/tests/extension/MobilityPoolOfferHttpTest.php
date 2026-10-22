<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Trust\CohortPolicy;
use STS\Mobility\Routing\OsrmRouteProvider;
use STS\Models\Car;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

/** Synthetic catalog/quote fixtures exercise the transaction boundary only. */
final class MobilityPoolOfferHttpTest extends TestCase
{
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['MOBILITY_COHORT_REQUIRED' => 'true', 'MOBILITY_COLLEGE_CODE' => 'CAMPUS-BLR'] as $key => $value) {
            $this->previous[$key] = getenv($key);
            putenv($key.'='.$value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
        parent::tearDown();
    }

    public function test_owner_mode_expiry_and_changed_stop_or_node_are_rejected(): void
    {
        [$host, $car] = $this->host('car');
        [$other, $otherCar] = $this->host('car');
        $quote = $this->quote($host, 'car');
        $payload = $this->payload($quote, $car);
        $this->actingAsApiUser($other)->postJson('/api/mobility/pool-offers', array_replace($payload, ['car_id' => $otherCar->id]), ['Idempotency-Key' => 'owner-key-1'])->assertNotFound();
        $car->forceFill(['description' => 'changed after approval'])->save();
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $payload, ['Idempotency-Key' => 'vehicle-key'])->assertForbidden()->assertJsonPath('error.code', 'HOST_APPROVAL_REQUIRED');
        [$host, $car] = $this->host('car');

        $quote = $this->quote($host, 'bike');
        $payload = $this->payload($quote, $car);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $payload, ['Idempotency-Key' => 'mode-key-1'])->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_VEHICLE_INVALID');

        $quote = $this->quote($host, 'car');
        $payload = $this->payload($quote, $car);
        DB::table('mobility_route_quotes')->where('id', $quote)->update(['expires_at' => now('UTC')->subMinute()]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $payload, ['Idempotency-Key' => 'expiry-key'])->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_EXPIRED');

        $fresh = $this->quote($host, 'car');
        DB::table('mobility_pickup_points')->where('id', 101)->update(['version' => 2]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $this->payload($fresh, $car), ['Idempotency-Key' => 'stop-key-1'])->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_STOPS_CHANGED');
        DB::table('mobility_pickup_points')->where('id', 101)->update(['version' => 1]);
        $fresh = $this->quote($host, 'car');
        DB::table('nodes_geo')->where('id', 501)->update(['lat' => 13.01]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $this->payload($fresh, $car), ['Idempotency-Key' => 'node-key-1'])->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_STOPS_CHANGED');
    }

    public function test_idempotency_replay_conflict_and_consumed_quote_are_deterministic(): void
    {
        [$host, $car] = $this->host('car');
        $quote = $this->quote($host, 'car');
        $payload = $this->payload($quote, $car);
        $first = $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $payload, ['Idempotency-Key' => 'same-key-1'])->assertCreated();
        $id = $first->json('data.id');
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $payload, ['Idempotency-Key' => 'same-key-1'])->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.existing', true);
        $conflict = $payload;
        $conflict['total_seats'] = 2;
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $conflict, ['Idempotency-Key' => 'same-key-1'])->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $payload, ['Idempotency-Key' => 'new-key-22'])->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_EXPIRED');
        $this->assertSame(1, DB::table('mobility_pool_publications')->where('quote_id', $quote)->count());
    }

    public function test_quote_fare_and_canonical_points_are_atomic_with_legacy_cap_enabled(): void
    {
        config(['carpoolear.module_max_price_enabled' => true]);
        [$host, $car] = $this->host('car');
        $quote = $this->quote($host, 'car', 777);
        $response = $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $this->payload($quote, $car), ['Idempotency-Key' => 'fare-key-1'])->assertCreated();
        $trip = Trip::findOrFail($response->json('data.id'));
        $this->assertSame(Trip::STATE_READY, $trip->state);
        $this->assertSame(777, (int) $trip->seat_price_cents);
        $this->assertSame(777, (int) $response->json('data.seat_price_minor'));
        $this->assertSame(2, DB::table('trips_points')->where('trip_id', $trip->id)->count());
        $this->assertSame(1, DB::table('trip_routes')->where('trip_id', $trip->id)->count());
        $this->assertNotNull(DB::table('mobility_route_quotes')->where('id', $quote)->value('consumed_trip_id'));
    }

    public function test_canonical_duplicate_rolls_back_trip_points_routes_publication_and_quote(): void
    {
        [$host, $car] = $this->host('car');
        $quote = $this->quote($host, 'car');
        $payload = $this->payload($quote, $car);
        Trip::factory()->create(['user_id' => $host->id, 'car_id' => $car->id, 'from_town' => 'Synthetic A', 'to_town' => 'Synthetic B', 'trip_date' => now('Asia/Kolkata')->addDays(3)->startOfMinute()->format('Y-m-d H:i:s'), 'is_passenger' => false]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $payload, ['Idempotency-Key' => 'rollback-key'])->assertStatus(409)->assertJsonPath('error.code', 'CANONICAL_DUPLICATE');
        $this->assertDatabaseMissing('mobility_pool_publications', ['quote_id' => $quote]);
        $this->assertNull(DB::table('mobility_route_quotes')->where('id', $quote)->value('consumed_trip_id'));
        $this->assertSame(0, DB::table('trips_points')->where('trip_id', 999999)->count());
    }

    public function test_new_trip_supports_rider_request_host_accept_and_cancel(): void
    {
        [$host, $car] = $this->host('car');
        $quote = $this->quote($host, 'car');
        $id = $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $this->payload($quote, $car), ['Idempotency-Key' => 'lifecycle-key'])->assertCreated()->json('data.id');
        $rider = $this->member();
        $this->actingAsApiUser($rider)->getJson('/api/mobility/pool-offers?service_date='.now('Asia/Kolkata')->addDays(3)->format('Y-m-d'))->assertOk()->assertJsonPath('data.0.id', $id);
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$id.'/requests')->assertOk();
        $this->actingAsApiUser($host)->postJson('/api/trips/'.$id.'/requests/'.$rider->id.'/accept')->assertOk();
        $this->assertDatabaseHas('trip_passengers', ['trip_id' => $id, 'user_id' => $rider->id, 'request_state' => Passenger::STATE_ACCEPTED]);
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$id.'/requests/'.$rider->id.'/cancel')->assertOk();
        $this->assertDatabaseHas('trip_passengers', ['trip_id' => $id, 'user_id' => $rider->id, 'request_state' => Passenger::STATE_CANCELED]);
    }

    public function test_failure_after_canonical_trip_write_rolls_everything_back_and_quote_can_be_reused(): void
    {
        [$host, $car] = $this->host('car');
        $quote = $this->quote($host, 'car');
        $payload = $this->payload($quote, $car);
        $before = [];
        foreach (['trips', 'trips_points', 'routes', 'trip_routes', 'mobility_pool_publications'] as $table) {
            $before[$table] = DB::table($table)->count();
        }
        \STS\Models\TripPoint::created(static fn () => throw new \RuntimeException('Synthetic failure after canonical point insertion'));
        try {
            $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $payload, ['Idempotency-Key' => 'partial-write-failure'])
                ->assertStatus(500)->assertJsonPath('error.code', 'OFFER_UNAVAILABLE');
        } finally {
            \STS\Models\TripPoint::flushEventListeners();
        }
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' rolled back');
        }
        $this->assertNull(DB::table('mobility_route_quotes')->where('id', $quote)->value('consumed_trip_id'));
        $this->assertFalse(\STS\Mobility\Routing\TrustedRouteContext::active());
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $payload, ['Idempotency-Key' => 'partial-write-failure'])->assertCreated();
    }

    public function test_quote_terms_are_immutable_through_legacy_updates_and_payload_injection(): void
    {
        [$host, $car] = $this->host('car');
        $quote = $this->quote($host, 'car');
        $payload = $this->payload($quote, $car);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $payload + ['distance' => 1], ['Idempotency-Key' => 'injected-metric'])
            ->assertUnprocessable();
        $id = $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $payload, ['Idempotency-Key' => 'immutable-terms'])->assertCreated()->json('data.id');
        foreach (['points' => [], 'trip_date' => '2099-01-01', 'seat_price_cents' => 1, 'distance' => 1, 'total_seats' => 5, 'car_id' => 9, 'enc_path' => 'injected', 'state' => 'paid', 'unknown' => true] as $field => $value) {
            $this->actingAsApiUser($host)->putJson('/api/trips/'.$id, [$field => $value])
                ->assertStatus(409)->assertJsonPath('error.code', 'QUOTED_OFFER_TERMS_IMMUTABLE');
        }
        $this->actingAsApiUser($host)->postJson('/api/trips/'.$id.'/changeSeats', ['increment' => 1])->assertStatus(409);
        $this->assertSame(321, (int) Trip::findOrFail($id)->seat_price_cents);
    }

    public function test_bike_capacity_invalid_dates_and_malformed_snapshots_fail_without_writes(): void
    {
        [$host, $car] = $this->host('bike');
        $quote = $this->quote($host, 'bike');
        $payload = $this->payload($quote, $car);
        foreach (['not-a-date', [], 9, '2026-02-30T11:00'] as $date) {
            $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', array_replace($payload, ['trip_date' => $date]), ['Idempotency-Key' => 'bad-date-value'])
                ->assertUnprocessable()->assertJsonPath('error.code', 'DEPARTURE_INVALID');
        }
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', array_replace($payload, ['total_seats' => 2]), ['Idempotency-Key' => 'bike-over-capacity'])
            ->assertUnprocessable();
        $snapshot = json_decode(DB::table('mobility_route_quotes')->where('id', $quote)->value('snapshot'), true);
        $snapshot['pickup_points'][1] = $snapshot['pickup_points'][0];
        $snapshot['points'][1] = $snapshot['points'][0];
        DB::table('mobility_route_quotes')->where('id', $quote)->update(['snapshot' => json_encode($snapshot)]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $payload, ['Idempotency-Key' => 'duplicate-stops'])
            ->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_STOPS_CHANGED');
        $this->assertSame(0, DB::table('mobility_pool_publications')->count());
        $this->assertSame(0, DB::table('trips')->count());
    }

    public function test_nonsynthetic_snapshot_graph_identity_is_required_at_canonical_publication(): void
    {
        [$host, $car] = $this->host('car');
        $quote = $this->quote($host, 'car');
        $snapshot = json_decode(DB::table('mobility_route_quotes')->where('id', $quote)->value('snapshot'), true);
        $snapshot['synthetic_only'] = false;
        unset($snapshot['data_version']);
        DB::table('mobility_route_quotes')->where('id', $quote)->update(['snapshot' => json_encode($snapshot)]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $this->payload($quote, $car), ['Idempotency-Key' => 'missing-graph-version'])->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_INVALID');

        $quote = $this->quote($host, 'car');
        $snapshot = json_decode(DB::table('mobility_route_quotes')->where('id', $quote)->value('snapshot'), true);
        $snapshot['synthetic_only'] = false;
        $snapshot['data_version'] = OsrmRouteProvider::dataVersion('car', str_repeat('a', 64), str_repeat('b', 64));
        DB::table('mobility_pickup_points')->whereIn('id', [101, 102])->update(['synthetic_only' => false]);
        $snapshot['route_schema'] = 'ordered-legs-v1';
        $snapshot['legs'] = [['from_index' => 0, 'to_index' => 1, 'distance_meters' => 2000, 'duration_seconds' => 600, 'seat_price_minor' => 321, 'provider' => 'synthetic', 'profile' => 'car', 'data_version' => OsrmRouteProvider::dataVersion('car', str_repeat('c', 64), str_repeat('d', 64))]];
        DB::table('mobility_route_quotes')->where('id', $quote)->update(['snapshot' => json_encode($snapshot)]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $this->payload($quote, $car), ['Idempotency-Key' => 'mixed-graph-version'])->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_INVALID');
    }

    public function test_missing_india_timezone_fails_before_any_new_publication(): void
    {
        [$host, $car] = $this->host('car');
        $quote = $this->quote($host, 'car');
        config(['app.timezone' => 'America/Argentina/Buenos_Aires']);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', $this->payload($quote, $car), ['Idempotency-Key' => 'timezone-required'])
            ->assertStatus(503)->assertJsonPath('error.code', 'MOBILITY_TIMEZONE_NOT_CONFIGURED');
        $this->assertNull(DB::table('mobility_route_quotes')->where('id', $quote)->value('consumed_trip_id'));
    }

    private function payload(string $quote, Car $car): array
    {
        return ['quote_id' => $quote, 'car_id' => $car->id, 'trip_date' => now('Asia/Kolkata')->addDays(3)->format('Y-m-d\\TH:i'), 'total_seats' => 1, 'description' => 'Synthetic quote fixture'];
    }

    private function member(): User
    {
        $user = User::factory()->create(['active' => true, 'banned' => false, 'identity_validated' => true]);
        DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    private function host(string $vehicleType): array
    {
        $host = $this->member();
        $host->forceFill(['nro_doc' => 'SYNTHETIC-ID', 'mobile_phone' => '0000000000'])->save();
        $car = Car::factory()->withCatalog()->create(['user_id' => $host->id]);
        DB::table('mobility_host_applications')->insert(['user_id' => $host->id, 'car_id' => $car->id, 'vehicle_type' => $vehicleType, 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);

        return [$host, $car];
    }

    private function quote(User $owner, string $vehicleType, int $fare = 321): string
    {
        foreach ([[501, 13.0000000, 77.5000000, 'Synthetic A'], [502, 13.0100000, 77.5100000, 'Synthetic B']] as [$nodeId, $lat, $lng, $name]) {
            DB::table('nodes_geo')->updateOrInsert(['id' => $nodeId], ['name' => $name, 'lat' => $lat, 'lng' => $lng, 'type' => 'synthetic']);
        }
        foreach ([[101, 501, 'Synthetic A', 13.0000000, 77.5000000], [102, 502, 'Synthetic B', 13.0100000, 77.5100000]] as [$id, $node, $name, $lat, $lng]) {
            DB::table('mobility_pickup_points')->updateOrInsert(['id' => $id], ['college_code' => 'CAMPUS-BLR', 'canonical_node_id' => $node, 'version' => 1, 'name' => $name, 'lat' => $lat, 'lng' => $lng, 'vehicle_modes' => json_encode(['car', 'bike']), 'active' => true, 'verified_at' => now(), 'synthetic_only' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $id = (string) Str::uuid();
        $points = [['address' => 'Synthetic A', 'json_address' => ['id' => 501, 'name' => 'Synthetic A'], 'lat' => 13.0, 'lng' => 77.5], ['address' => 'Synthetic B', 'json_address' => ['id' => 502, 'name' => 'Synthetic B'], 'lat' => 13.01, 'lng' => 77.51]];
        DB::table('mobility_route_quotes')->insert(['id' => $id, 'user_id' => $owner->id, 'college_code' => 'CAMPUS-BLR', 'snapshot' => json_encode(['vehicle_type' => $vehicleType, 'provider' => 'synthetic', 'profile' => $vehicleType, 'points' => $points, 'distance_meters' => 2000, 'duration_seconds' => 600, 'currency' => 'INR', 'seat_price_minor' => $fare, 'pricing_version' => 'test-v1', 'synthetic_only' => true, 'pickup_points' => [['id' => 101, 'version' => 1, 'canonical_node_id' => 501], ['id' => 102, 'version' => 1, 'canonical_node_id' => 502]]]), 'expires_at' => now('UTC')->addMinutes(5), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
