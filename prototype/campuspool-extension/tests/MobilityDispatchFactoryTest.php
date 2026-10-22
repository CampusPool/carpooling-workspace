<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Dispatch\DispatchBookingContext;
use STS\Mobility\Dispatch\DispatchCanonicalFactory;
use STS\Mobility\Dispatch\DispatchEligibility;
use STS\Mobility\Dispatch\DispatchTerms;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\Passenger;
use STS\Models\User;
use Tests\TestCase;

final class MobilityDispatchFactoryTest extends TestCase
{
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['MOBILITY_COHORT_REQUIRED' => 'true', 'MOBILITY_COLLEGE_CODE' => 'CAMPUS-BLR'] as $key => $value) {
            $this->previous[$key] = getenv($key);
            putenv($key.'='.$value);
        }
        config(['app.timezone' => 'Asia/Kolkata']);
        DB::table('nodes_geo')->insert([['id' => 8801, 'name' => 'Dispatch A', 'lat' => 13.01, 'lng' => 77.51, 'type' => 'synthetic'], ['id' => 8802, 'name' => 'Dispatch B', 'lat' => 13.02, 'lng' => 77.52, 'type' => 'synthetic']]);
        DB::table('mobility_pickup_points')->insert([
            ['id' => 8811, 'college_code' => 'CAMPUS-BLR', 'canonical_node_id' => 8801, 'version' => 1, 'name' => 'Dispatch A', 'lat' => 13.01, 'lng' => 77.51, 'vehicle_modes' => json_encode(['car']), 'active' => true, 'verified_at' => now(), 'synthetic_only' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 8812, 'college_code' => 'CAMPUS-BLR', 'canonical_node_id' => 8802, 'version' => 1, 'name' => 'Dispatch B', 'lat' => 13.02, 'lng' => 77.52, 'vehicle_modes' => json_encode(['car']), 'active' => true, 'verified_at' => now(), 'synthetic_only' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
        parent::tearDown();
    }

    public function test_quote_terms_preserve_exact_snapshot_hash_and_fare(): void
    {
        $snapshot = [
            'vehicle_type' => 'car', 'provider' => 'synthetic', 'profile' => 'car', 'currency' => 'INR', 'synthetic_only' => true,
            'distance_meters' => 2500, 'duration_seconds' => 600, 'seat_price_minor' => 250,
            'points' => [['address' => 'A', 'json_address' => ['id' => 1, 'name' => 'A'], 'lat' => 13.1, 'lng' => 77.1], ['address' => 'B', 'json_address' => ['id' => 2, 'name' => 'B'], 'lat' => 13.2, 'lng' => 77.2]],
            'pickup_points' => [['id' => 10, 'version' => 1, 'canonical_node_id' => 1], ['id' => 11, 'version' => 1, 'canonical_node_id' => 2]],
        ];
        $raw = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $terms = DispatchTerms::forQuote((object) ['id' => '11111111-1111-1111-1111-111111111111', 'user_id' => 7, 'college_code' => 'CAMPUS-BLR', 'snapshot' => $raw]);

        $this->assertSame(hash('sha256', $raw), $terms['hash']);
        $this->assertSame(250, $terms['fare_minor']);
        $this->assertSame(10, $terms['pickup_point_id']);
    }

    public function test_terms_reject_three_stop_dispatch_quote(): void
    {
        $snapshot = ['vehicle_type' => 'car', 'provider' => 'p', 'profile' => 'car', 'currency' => 'INR', 'synthetic_only' => true, 'distance_meters' => 1, 'duration_seconds' => 1, 'seat_price_minor' => 0, 'points' => [[], [], []], 'pickup_points' => [['id' => 1, 'canonical_node_id' => 1], ['id' => 2, 'canonical_node_id' => 2], ['id' => 3, 'canonical_node_id' => 3]]];
        $this->expectException(\Throwable::class);
        DispatchTerms::forQuote((object) ['id' => '11111111-1111-1111-1111-111111111111', 'user_id' => 7, 'snapshot' => json_encode($snapshot)]);
    }

    public function test_booking_context_is_scoped_and_restored_after_failure(): void
    {
        $this->assertFalse(DispatchBookingContext::allows(1, 2));
        try {
            DispatchBookingContext::run(1, 2, function (): void {
                $this->assertTrue(DispatchBookingContext::allows(1, 2));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('rollback', $error->getMessage());
        }
        $this->assertFalse(DispatchBookingContext::allows(1, 2));
    }

    public function test_factory_creates_driver_trip_and_accepted_rider_booking_atomically(): void
    {
        [$driver, $car, $rider, $terms] = $this->fixture();
        $result = DB::transaction(fn (): array => DispatchCanonicalFactory::create((object) ['rider_id' => $rider->id], (object) ['driver_id' => $driver->id, 'car_id' => $car->id, 'vehicle_type' => 'car'], (object) ['driver_id' => $driver->id], $terms));
        $this->assertDatabaseHas('trips', ['id' => $result['trip_id'], 'user_id' => $driver->id, 'car_id' => $car->id]);
        $this->assertDatabaseHas('trip_passengers', ['id' => $result['booking_id'], 'trip_id' => $result['trip_id'], 'user_id' => $rider->id, 'request_state' => Passenger::STATE_ACCEPTED]);
        $this->assertDatabaseHas('mobility_trip_lifecycles', ['trip_id' => $result['trip_id'], 'state' => 'planned']);
    }

    public function test_current_eligibility_rejects_changed_catalog_at_both_ends(): void
    {
        [$driver, $car, $rider, $terms] = $this->fixture();
        DB::table('mobility_pickup_points')->where('id', 8812)->update(['version' => 2]);
        $this->expectException(\Throwable::class);
        DispatchEligibility::current($terms, $driver->id, $car->id);
    }

    public function test_factory_rollback_removes_trip_and_booking_when_outer_transaction_fails(): void
    {
        [$driver, $car, $rider, $terms] = $this->fixture();
        try {
            DB::transaction(function () use ($driver, $car, $rider, $terms): void {
                DispatchCanonicalFactory::create((object) ['rider_id' => $rider->id], (object) ['driver_id' => $driver->id, 'car_id' => $car->id, 'vehicle_type' => 'car'], (object) ['driver_id' => $driver->id], $terms);
                throw new \RuntimeException('injected rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('injected rollback', $error->getMessage());
        }
        $this->assertSame(0, DB::table('trips')->where('user_id', $driver->id)->count());
        $this->assertSame(0, DB::table('trip_passengers')->where('user_id', $rider->id)->count());
    }

    private function fixture(): array
    {
        $driver = User::factory()->create(['active' => true, 'banned' => false, 'identity_validated' => true, 'nro_doc' => 'DISPATCH-D', 'mobile_phone' => '0000000000']);
        $rider = User::factory()->create(['active' => true, 'banned' => false, 'identity_validated' => true, 'nro_doc' => 'DISPATCH-R', 'mobile_phone' => '0000000001']);
        foreach ([$driver, $rider] as $user) {
            DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $car = Car::factory()->withCatalog()->create(['user_id' => $driver->id]);
        DB::table('mobility_host_applications')->insert(['user_id' => $driver->id, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $snapshot = ['vehicle_type' => 'car', 'provider' => 'synthetic', 'profile' => 'car', 'currency' => 'INR', 'synthetic_only' => true, 'distance_meters' => 2500, 'duration_seconds' => 600, 'seat_price_minor' => 250,
            'points' => [['address' => 'Dispatch A', 'json_address' => ['id' => 8801, 'name' => 'Dispatch A'], 'lat' => 13.01, 'lng' => 77.51], ['address' => 'Dispatch B', 'json_address' => ['id' => 8802, 'name' => 'Dispatch B'], 'lat' => 13.02, 'lng' => 77.52]],
            'pickup_points' => [['id' => 8811, 'version' => 1, 'canonical_node_id' => 8801], ['id' => 8812, 'version' => 1, 'canonical_node_id' => 8802]]];
        $quote = (object) ['id' => (string) Str::uuid(), 'user_id' => $rider->id, 'college_code' => 'CAMPUS-BLR', 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)];

        return [$driver, $car, $rider, DispatchTerms::forQuote($quote)];
    }
}
