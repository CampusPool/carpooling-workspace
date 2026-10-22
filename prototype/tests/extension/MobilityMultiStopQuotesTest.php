<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Routing\RouteProvider;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

final class MobilityMultiStopQuotesTest extends TestCase
{
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['MOBILITY_COHORT_REQUIRED' => 'true', 'MOBILITY_COLLEGE_CODE' => 'CAMPUS-BLR'] as $key => $value) {
            $this->previous[$key] = getenv($key);
            putenv($key.'='.$value);
        }
        config(['mobility_routing.pricing_version' => 'test-v1', 'mobility_routing.car_minor_per_km' => 100, 'mobility_routing.synthetic_only' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
        parent::tearDown();
    }

    public function test_ordered_quote_persists_adjacent_provider_provenance_and_ceiling_fares(): void
    {
        [$user] = $this->fixture(3);
        $calls = [];
        $levels = [];
        $baseline = DB::connection()->transactionLevel();
        $this->app->bind(RouteProvider::class, function () use (&$calls, &$levels): RouteProvider {
            return new class($calls, $levels) implements RouteProvider
            {
                public function __construct(private array &$calls, private array &$levels) {}

                public function route(array $pickup, array $dropoff, string $vehicleType): array
                {
                    $this->calls[] = [$pickup['json_address']['id'], $dropoff['json_address']['id']];
                    $this->levels[] = DB::connection()->transactionLevel();

                    return ['distance_meters' => 1501, 'duration_seconds' => 120, 'provider' => 'fake-provider', 'profile' => 'car'];
                }
            };
        });

        $response = $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['stop_ids' => [101, 102, 103], 'vehicle_type' => 'car'])->assertCreated();
        $response->assertJsonPath('data.route_schema', 'ordered-legs-v1')->assertJsonPath('data.distance_meters', 3002)->assertJsonPath('data.seat_price_minor', 302);
        $this->assertSame([[501, 502], [502, 503]], $calls);
        $this->assertSame([$baseline, $baseline], $levels);
        $snapshot = json_decode(DB::table('mobility_route_quotes')->where('id', $response->json('data.id'))->value('snapshot'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([0, 1], array_column($snapshot['legs'], 'from_index'));
        $this->assertSame([1, 2], array_column($snapshot['legs'], 'to_index'));
        $this->assertSame(['fake-provider', 'fake-provider'], array_column($snapshot['legs'], 'provider'));
        $this->assertSame(['car', 'car'], array_column($snapshot['legs'], 'profile'));
    }

    public function test_ordered_input_rejects_mixed_or_repeated_canonical_stops_without_provider_calls(): void
    {
        [$user] = $this->fixture(3);
        $calls = 0;
        $this->app->bind(RouteProvider::class, function () use (&$calls): RouteProvider {
            return new class($calls) implements RouteProvider
            {
                public function __construct(private int &$calls) {}

                public function route(array $pickup, array $dropoff, string $vehicleType): array
                {
                    $this->calls++;

                    return ['distance_meters' => 1, 'duration_seconds' => 1, 'provider' => 'fake', 'profile' => 'car'];
                }
            };
        });
        $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['stop_ids' => [101, 101, 102], 'vehicle_type' => 'car'])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_POINTS');
        $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['stop_ids' => [101, 102], 'pickup_point_id' => 101, 'vehicle_type' => 'car'])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_POINTS');
        $this->assertSame(0, $calls);
        $this->assertSame(0, DB::table('mobility_route_quotes')->count());
    }

    public function test_provider_leg_failure_and_catalog_change_leave_no_partial_publication(): void
    {
        [$user] = $this->fixture(3);
        $this->app->bind(RouteProvider::class, fn (): RouteProvider => new class implements RouteProvider
        {
            public function route(array $pickup, array $dropoff, string $vehicleType): array
            {
                if ($pickup['json_address']['id'] === 502) {
                    throw new \RuntimeException('leg failure');
                }
                DB::table('mobility_pickup_points')->where('id', 101)->update(['version' => 2]);

                return ['distance_meters' => 1000, 'duration_seconds' => 60, 'provider' => 'fake', 'profile' => 'car'];
            }
        });
        $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['stop_ids' => [101, 102, 103], 'vehicle_type' => 'car'])->assertStatus(500);
        $this->assertSame(0, DB::table('mobility_route_quotes')->count());
    }

    public function test_multi_stop_publication_keeps_all_canonical_points_routes_and_full_fare(): void
    {
        [$host] = $this->fixture(3);
        $car = Car::factory()->withCatalog()->create(['user_id' => $host->id]);
        DB::table('mobility_host_applications')->insert(['user_id' => $host->id, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->app->bind(RouteProvider::class, fn (): RouteProvider => new class implements RouteProvider
        {
            public function route(array $pickup, array $dropoff, string $vehicleType): array
            {
                return ['distance_meters' => 1501, 'duration_seconds' => 120, 'provider' => 'fake-provider', 'profile' => 'car'];
            }
        });
        $quote = $this->actingAsApiUser($host)->postJson('/api/mobility/routes/quote', ['stop_ids' => [101, 102, 103], 'vehicle_type' => 'car'])->assertCreated()->json('data.id');
        $published = $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', ['quote_id' => $quote, 'car_id' => $car->id, 'trip_date' => now('Asia/Kolkata')->addDays(3)->format('Y-m-d\\TH:i'), 'total_seats' => 1, 'description' => 'multi-stop'], ['Idempotency-Key' => 'multi-stop-publication'])->assertCreated();
        $tripId = $published->json('data.id');
        $this->assertSame(3, DB::table('trips_points')->where('trip_id', $tripId)->count());
        $this->assertSame(2, DB::table('trip_routes')->where('trip_id', $tripId)->count());
        $this->assertSame(302, (int) Trip::findOrFail($tripId)->seat_price_cents);
        $published->assertJsonPath('data.distance_meters', 3002)->assertJsonPath('data.legs.0.from_index', 0)->assertJsonPath('data.legs.1.to_index', 2);
    }

    public function test_forged_three_stop_snapshot_without_ordered_legs_is_denied(): void
    {
        [$host] = $this->fixture(3);
        $car = Car::factory()->withCatalog()->create(['user_id' => $host->id]);
        DB::table('mobility_host_applications')->insert(['user_id' => $host->id, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->app->bind(RouteProvider::class, fn (): RouteProvider => new class implements RouteProvider
        {
            public function route(array $pickup, array $dropoff, string $vehicleType): array
            {
                return ['distance_meters' => 1000, 'duration_seconds' => 60, 'provider' => 'fake-provider', 'profile' => 'car'];
            }
        });
        $quote = $this->actingAsApiUser($host)->postJson('/api/mobility/routes/quote', ['stop_ids' => [101, 102, 103], 'vehicle_type' => 'car'])->assertCreated()->json('data.id');
        $snapshot = json_decode(DB::table('mobility_route_quotes')->where('id', $quote)->value('snapshot'), true, 512, JSON_THROW_ON_ERROR);
        unset($snapshot['route_schema'], $snapshot['legs']);
        DB::table('mobility_route_quotes')->where('id', $quote)->update(['snapshot' => json_encode($snapshot)]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', ['quote_id' => $quote, 'car_id' => $car->id, 'trip_date' => now('Asia/Kolkata')->addDays(3)->format('Y-m-d\\TH:i'), 'total_seats' => 1], ['Idempotency-Key' => 'forged-three-stop'])->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_INVALID');
        $this->assertSame(0, DB::table('mobility_pool_publications')->where('quote_id', $quote)->count());
    }

    private function fixture(int $count): array
    {
        $user = User::factory()->create(['active' => true, 'banned' => false, 'identity_validated' => true, 'nro_doc' => 'SYNTHETIC-ID', 'mobile_phone' => '0000000000']);
        DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        for ($i = 1; $i <= $count; $i++) {
            $node = 500 + $i;
            DB::table('nodes_geo')->insert(['id' => $node, 'name' => 'Stop '.$i, 'lat' => 13.0 + $i / 100, 'lng' => 77.5 + $i / 100, 'type' => 'synthetic']);
            DB::table('mobility_pickup_points')->insert(['id' => 100 + $i, 'college_code' => 'CAMPUS-BLR', 'canonical_node_id' => $node, 'version' => 1, 'name' => 'Stop '.$i, 'lat' => 13.0 + $i / 100, 'lng' => 77.5 + $i / 100, 'vehicle_modes' => json_encode(['car']), 'active' => true, 'verified_at' => now(), 'synthetic_only' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$user];
    }
}
