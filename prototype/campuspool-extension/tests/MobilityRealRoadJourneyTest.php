<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\Passenger;
use STS\Models\User;
use Tests\TestCase;

/**
 * Optional, local-only proof of the real OSRM-to-canonical-trip boundary.
 *
 * This deliberately has no HTTP fake: enable it only from the application
 * container with MOBILITY_REAL_ROAD_PROOF=local-only and both graph pins set.
 */
final class MobilityRealRoadJourneyTest extends TestCase
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

    public function test_forward_canonical_car_and_bike_journeys_keep_bidirectional_quote_versions(): void
    {
        if (getenv('MOBILITY_REAL_ROAD_PROOF') !== 'local-only') {
            $this->markTestSkipped('Set MOBILITY_REAL_ROAD_PROOF=local-only to run local OSRM proof.');
        }

        $carVersion = (string) getenv('MOBILITY_ROUTING_CAR_EXPECTED_DATA_VERSION');
        $bikeVersion = (string) getenv('MOBILITY_ROUTING_BIKE_EXPECTED_DATA_VERSION');
        if ($carVersion === '' || $bikeVersion === '') {
            $this->fail('Explicit car and bike graph pins are required for an enabled proof.');
        }
        $this->assertNotSame($carVersion, $bikeVersion, 'Car and bike graph pins must identify distinct prepared graphs.');

        config([
            'mobility_routing.provider' => 'osrm',
            'mobility_routing.synthetic_only' => false,
            'mobility_routing.car_base_url' => 'http://mobility-road-router-car:5000',
            'mobility_routing.bike_base_url' => 'http://mobility-road-router-bike:5000',
            'mobility_routing.car_profile' => 'driving',
            'mobility_routing.bike_profile' => 'driving',
            'mobility_routing.car_expected_data_version' => $carVersion,
            'mobility_routing.bike_expected_data_version' => $bikeVersion,
            'mobility_routing.pricing_version' => 'real-road-proof-v1',
            'mobility_routing.car_minor_per_km' => 100,
            'mobility_routing.bike_minor_per_km' => 50,
        ]);

        foreach (['car', 'bike'] as $mode) {
            $this->runMode($mode, $mode === 'car' ? $carVersion : $bikeVersion);
        }
    }

    private function runMode(string $mode, string $expectedVersion): void
    {
        [$host, $car] = $this->host($mode);
        $rider = $this->member('rider-'.$mode);
        $this->catalog();

        $forward = $this->actingAsApiUser($host)->postJson('/api/mobility/routes/quote', [
            'stop_ids' => [901, 902], 'vehicle_type' => $mode,
        ])->assertCreated();
        $reverse = $this->actingAsApiUser($host)->postJson('/api/mobility/routes/quote', [
            'stop_ids' => [902, 901], 'vehicle_type' => $mode,
        ])->assertCreated();

        $forwardId = (string) $forward->json('data.id');
        $reverseId = (string) $reverse->json('data.id');
        $this->assertNotSame($forwardId, $reverseId);
        $this->assertSame($expectedVersion, $forward->json('data.data_version'));
        $this->assertSame($expectedVersion, $reverse->json('data.data_version'));
        $this->assertSame($expectedVersion, $forward->json('data.legs.0.data_version'));
        $this->assertTrue($forward->json('data.synthetic_only'), 'Test stops and fares remain labeled software fixtures.');
        $this->assertSame($mode, $forward->json('data.vehicle_type'));
        $this->assertSame('osrm', $forward->json('data.provider'));
        $this->assertSame('driving', $forward->json('data.profile'));
        $this->assertGreaterThan(0, (int) $forward->json('data.distance_meters'));
        $this->assertGreaterThan(0, (int) $forward->json('data.duration_seconds'));

        $distance = (int) $forward->json('data.distance_meters');
        $expectedFare = (int) ceil($distance * ($mode === 'car' ? 100 : 50) / 1000);
        $this->assertSame($expectedFare, (int) $forward->json('data.seat_price_minor'));
        $snapshotBefore = (string) DB::table('mobility_route_quotes')->where('id', $forwardId)->value('snapshot');
        $reverseSnapshot = (string) DB::table('mobility_route_quotes')->where('id', $reverseId)->value('snapshot');

        $published = $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', [
            'quote_id' => $forwardId, 'car_id' => $car->id,
            'trip_date' => now('Asia/Kolkata')->addDays(3)->format('Y-m-d\\TH:i'),
            'total_seats' => $mode === 'bike' ? 1 : 2,
            'description' => 'Software road reference A to B',
        ], ['Idempotency-Key' => 'real-road-'.$mode.'-'.Str::uuid()])->assertCreated();
        $this->assertSame($expectedVersion, $published->json('data.data_version'));
        $tripId = (int) $published->json('data.id');
        $this->assertSame($forwardId, (string) DB::table('mobility_route_quotes')->where('id', $forwardId)->value('id'));
        $this->assertSame($tripId, (int) DB::table('mobility_route_quotes')->where('id', $forwardId)->value('consumed_trip_id'));
        $this->assertNull(DB::table('mobility_route_quotes')->where('id', $reverseId)->value('consumed_trip_id'));
        $this->assertSame($snapshotBefore, (string) DB::table('mobility_route_quotes')->where('id', $forwardId)->value('snapshot'));
        $this->assertSame($reverseSnapshot, (string) DB::table('mobility_route_quotes')->where('id', $reverseId)->value('snapshot'));

        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$tripId.'/requests')->assertOk();
        $this->actingAsApiUser($host)->postJson('/api/trips/'.$tripId.'/requests/'.$rider->id.'/accept')->assertOk();
        $this->assertDatabaseHas('trip_passengers', ['trip_id' => $tripId, 'user_id' => $rider->id, 'request_state' => Passenger::STATE_ACCEPTED]);
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$tripId.'/requests/'.$rider->id.'/cancel')->assertOk();
        $this->assertDatabaseHas('trip_passengers', ['trip_id' => $tripId, 'user_id' => $rider->id, 'request_state' => Passenger::STATE_CANCELED]);
        $this->assertSame($snapshotBefore, (string) DB::table('mobility_route_quotes')->where('id', $forwardId)->value('snapshot'));
        $this->assertSame($expectedFare, (int) DB::table('trips')->where('id', $tripId)->value('seat_price_cents'));
    }

    private function catalog(): void
    {
        DB::table('nodes_geo')->upsert([
            ['id' => 9901, 'name' => 'Software road reference A', 'lat' => 12.9716, 'lng' => 77.5946, 'type' => 'synthetic'],
            ['id' => 9902, 'name' => 'Software road reference B', 'lat' => 12.9352, 'lng' => 77.6200, 'type' => 'synthetic'],
        ], ['id'], ['name', 'lat', 'lng', 'type']);
        DB::table('mobility_pickup_points')->upsert([
            ['id' => 901, 'college_code' => 'CAMPUS-BLR', 'canonical_node_id' => 9901, 'version' => 1, 'name' => 'Software road reference A', 'lat' => 12.9716, 'lng' => 77.5946, 'vehicle_modes' => json_encode(['car', 'bike']), 'active' => true, 'verified_at' => now(), 'synthetic_only' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 902, 'college_code' => 'CAMPUS-BLR', 'canonical_node_id' => 9902, 'version' => 1, 'name' => 'Software road reference B', 'lat' => 12.9352, 'lng' => 77.6200, 'vehicle_modes' => json_encode(['car', 'bike']), 'active' => true, 'verified_at' => now(), 'synthetic_only' => true, 'created_at' => now(), 'updated_at' => now()],
        ], ['id'], ['college_code', 'canonical_node_id', 'version', 'name', 'lat', 'lng', 'vehicle_modes', 'active', 'verified_at', 'synthetic_only', 'updated_at']);
    }

    private function host(string $mode): array
    {
        $host = $this->member('host-'.$mode);
        $host->forceFill(['nro_doc' => 'SOFTWARE-'.$mode, 'mobile_phone' => '0000000000'])->save();
        $car = Car::factory()->withCatalog()->create(['user_id' => $host->id]);
        DB::table('mobility_host_applications')->insert(['user_id' => $host->id, 'car_id' => $car->id, 'vehicle_type' => $mode, 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);

        return [$host, $car];
    }

    private function member(string $label): User
    {
        $user = User::factory()->create(['active' => true, 'banned' => false, 'identity_validated' => true, 'nro_doc' => 'SOFTWARE-'.$label, 'mobile_phone' => '0000000000']);
        DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }
}
