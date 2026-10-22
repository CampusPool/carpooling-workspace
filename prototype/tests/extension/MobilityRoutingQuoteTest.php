<?php

namespace Tests\Feature\Mobility;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use STS\Mobility\Routing\RouteProvider;
use STS\Mobility\Routing\OsrmRouteProvider;
use STS\Models\User;
use Tests\TestCase;

final class MobilityRoutingQuoteTest extends TestCase
{
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = ['MOBILITY_COHORT_REQUIRED' => getenv('MOBILITY_COHORT_REQUIRED'), 'MOBILITY_COLLEGE_CODE' => getenv('MOBILITY_COLLEGE_CODE')];
        putenv('MOBILITY_COHORT_REQUIRED=true');
        putenv('MOBILITY_COLLEGE_CODE=CAMPUS-BLR');
        config(['mobility_routing.provider' => 'osrm', 'mobility_routing.car_base_url' => 'https://routing.internal.test', 'mobility_routing.bike_base_url' => 'https://routing.internal.test', 'mobility_routing.car_profile' => 'car', 'mobility_routing.bike_profile' => 'motorcycle', 'mobility_routing.pricing_version' => 'test-v1', 'mobility_routing.car_minor_per_km' => 1000, 'mobility_routing.bike_minor_per_km' => 500, 'mobility_routing.synthetic_only' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
        parent::tearDown();
    }

    public function test_catalog_and_quotes_keep_vehicle_modes_and_provider_snapshot(): void
    {
        [$user, $car, $bike] = $this->fixture();
        $this->bindProvider();
        $this->actingAsApiUser($user)->getJson('/api/mobility/pickup-points?vehicle_type=car')->assertOk()->assertJsonCount(2, 'data');
        $response = $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['pickup_point_id' => $car, 'dropoff_point_id' => $car + 1, 'vehicle_type' => 'car'])->assertCreated();
        $response->assertJsonPath('data.vehicle_type', 'car')->assertJsonPath('data.provider', 'synthetic-test')->assertJsonPath('data.profile', 'car')->assertJsonPath('data.synthetic_only', true)->assertJsonPath('data.points.0.json_address.id', 101);
        $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['pickup_point_id' => $car + 1, 'dropoff_point_id' => $car + 2, 'vehicle_type' => 'bike'])->assertCreated()->assertJsonPath('data.vehicle_type', 'bike')->assertJsonPath('data.profile', 'bike');
        $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['pickup_point_id' => $car, 'dropoff_point_id' => 3, 'vehicle_type' => 'car'])->assertStatus(422);
    }

    public function test_quote_rejects_unknown_fields_and_wrong_actor(): void
    {
        [$user, $pickup] = $this->fixture();
        $this->bindProvider();
        $payload = ['pickup_point_id' => $pickup, 'dropoff_point_id' => $pickup + 1, 'vehicle_type' => 'car'];
        $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', $payload + ['distance_meters' => 1])->assertStatus(422)->assertJsonPath('error.code', 'UNKNOWN_FIELDS');
        $other = User::factory()->create(['active' => true, 'banned' => false]);
        $this->actingAsApiUser($other)->postJson('/api/mobility/routes/quote', $payload)->assertStatus(403)->assertJsonPath('error.code', 'MEMBERSHIP_REQUIRED');
    }

    public function test_catalog_change_after_provider_io_prevents_insert(): void
    {
        [$user, $pickup] = $this->fixture();
        $this->app->bind(RouteProvider::class, function () use ($pickup): RouteProvider {
            return new class($pickup) implements RouteProvider
            {
                public function __construct(private int $pickup) {}

                public function route(array $pickup, array $dropoff, string $vehicleType): array
                {
                    DB::table('mobility_pickup_points')->where('id', $this->pickup)->update(['version' => 2]);

                    return ['distance_meters' => 1000, 'duration_seconds' => 120, 'provider' => 'synthetic-test', 'profile' => 'car'];
                }
            };
        });
        $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['pickup_point_id' => $pickup, 'dropoff_point_id' => $pickup + 1, 'vehicle_type' => 'car'])->assertStatus(422)->assertJsonPath('error.code', 'CATALOG_CHANGED');
        $this->assertSame(0, DB::table('mobility_route_quotes')->count());
    }

    public function test_missing_config_and_provider_failures_are_safe(): void
    {
        [$user, $pickup] = $this->fixture();
        $this->bindProvider();
        config(['mobility_routing.pricing_version' => null]);
        $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['pickup_point_id' => $pickup, 'dropoff_point_id' => $pickup + 1, 'vehicle_type' => 'car'])->assertStatus(503)->assertJsonPath('error.code', 'ROUTING_MISCONFIGURED');
    }

    public function test_structured_graph_identity_is_kept_in_quote_and_leg_snapshot(): void
    {
        [$user, $pickup] = $this->fixture();
        $version = OsrmRouteProvider::dataVersion('car', str_repeat('a', 64), str_repeat('b', 64));
        $this->app->bind(RouteProvider::class, fn (): RouteProvider => new class($version) implements RouteProvider
        {
            public function __construct(private string $version) {}

            public function route(array $pickup, array $dropoff, string $vehicleType): array
            {
                return ['distance_meters' => 1000, 'duration_seconds' => 120, 'provider' => 'osrm', 'profile' => 'car', 'data_version' => $this->version];
            }
        });
        $response = $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['stop_ids' => [$pickup, $pickup + 1], 'vehicle_type' => 'car'])->assertCreated();
        $response->assertJsonPath('data.data_version', $version)->assertJsonPath('data.legs.0.data_version', $version);
        $stored = json_decode((string) DB::table('mobility_route_quotes')->latest('created_at')->value('snapshot'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($version, $stored['data_version']);
        $this->assertSame($version, $stored['legs'][0]['data_version']);
    }

    public function test_real_route_rejects_missing_version_and_mid_quote_graph_change(): void
    {
        [$user, $pickup] = $this->fixture();
        DB::table('mobility_pickup_points')->where('id', 3)->update(['vehicle_modes' => json_encode(['car']), 'synthetic_only' => false]);
        DB::table('mobility_pickup_points')->whereIn('id', [1, 2])->update(['synthetic_only' => false]);
        $carVersion = OsrmRouteProvider::dataVersion('car', str_repeat('a', 64), str_repeat('b', 64));
        $bikeVersion = OsrmRouteProvider::dataVersion('bike', str_repeat('c', 64), str_repeat('d', 64));
        config(['mobility_routing.synthetic_only' => false, 'mobility_routing.car_expected_data_version' => $carVersion, 'mobility_routing.bike_expected_data_version' => $bikeVersion]);
        $this->app->bind(RouteProvider::class, fn (): RouteProvider => new class($carVersion) implements RouteProvider
        {
            private int $calls = 0;
            public function __construct(private string $version) {}
            public function route(array $pickup, array $dropoff, string $vehicleType): array
            {
                $this->calls++;
                return ['distance_meters' => 1000, 'duration_seconds' => 120, 'provider' => 'osrm', 'profile' => 'car', 'data_version' => $this->calls === 1 ? $this->version : OsrmRouteProvider::dataVersion('car', str_repeat('e', 64), str_repeat('f', 64))];
            }
        });
        $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['stop_ids' => [$pickup, $pickup + 1, 3], 'vehicle_type' => 'car'])->assertStatus(422)->assertJsonPath('error.code', 'ROUTE_GRAPH_MISMATCH');
        $this->assertSame(0, DB::table('mobility_route_quotes')->count());

        $this->app->bind(RouteProvider::class, fn (): RouteProvider => new class implements RouteProvider
        {
            public function route(array $pickup, array $dropoff, string $vehicleType): array
            {
                return ['distance_meters' => 1000, 'duration_seconds' => 120, 'provider' => 'osrm', 'profile' => 'car'];
            }
        });
        $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['pickup_point_id' => $pickup, 'dropoff_point_id' => $pickup + 1, 'vehicle_type' => 'car'])->assertStatus(422)->assertJsonPath('error.code', 'ROUTE_GRAPH_MISMATCH');
    }

    public function test_real_mode_graph_pin_collision_is_rejected_before_provider_io(): void
    {
        [$user, $pickup] = $this->fixture();
        DB::table('mobility_pickup_points')->whereIn('id', [1, 2])->update(['synthetic_only' => false]);
        $version = OsrmRouteProvider::dataVersion('car', str_repeat('a', 64), str_repeat('b', 64));
        config(['mobility_routing.synthetic_only' => false, 'mobility_routing.car_expected_data_version' => $version, 'mobility_routing.bike_expected_data_version' => $version]);
        $this->app->bind(RouteProvider::class, fn (): RouteProvider => new class implements RouteProvider
        {
            public function route(array $pickup, array $dropoff, string $vehicleType): array
            {
                throw new \RuntimeException('provider should not be called');
            }
        });
        $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['pickup_point_id' => $pickup, 'dropoff_point_id' => $pickup + 1, 'vehicle_type' => 'car'])->assertStatus(503)->assertJsonPath('error.code', 'ROUTING_MISCONFIGURED');
    }

    public function test_no_route_and_timeout_from_the_http_adapter_create_no_quotes(): void
    {
        [$user, $pickup] = $this->fixture();
        Http::fake(fn () => Http::response(['code' => 'NoRoute'], 200));
        $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['pickup_point_id' => $pickup, 'dropoff_point_id' => $pickup + 1, 'vehicle_type' => 'car'])->assertStatus(422)->assertJsonPath('error.code', 'NO_ROUTE');
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['pickup_point_id' => $pickup, 'dropoff_point_id' => $pickup + 1, 'vehicle_type' => 'car'])->assertStatus(502)->assertJsonPath('error.code', 'ROUTE_PROVIDER_UNAVAILABLE');
    }

    public function test_catalog_filters_before_pagination_and_rejects_invalid_query_shapes(): void
    {
        [$user] = $this->fixture();
        $first = $this->actingAsApiUser($user)->getJson('/api/mobility/pickup-points?vehicle_type=bike&page_size=1')->assertOk()->assertJsonPath('data.0.id', 2)->assertJsonPath('next_cursor', 2);
        $this->actingAsApiUser($user)->getJson('/api/mobility/pickup-points?vehicle_type=bike&page_size=1&after='.$first->json('next_cursor'))->assertOk()->assertJsonPath('data.0.id', 3)->assertJsonPath('next_cursor', null);
        foreach (['page_size[]=1', 'after[]=1', 'vehicle_type[]=car', 'page_size=1.5', 'page_size=51', 'after=0'] as $query) {
            $this->actingAsApiUser($user)->getJson('/api/mobility/pickup-points?'.$query)->assertUnprocessable();
        }
    }

    public function test_synthetic_catalog_cannot_be_relabelled_live_and_price_uses_integer_ceiling(): void
    {
        [$user, $pickup] = $this->fixture();
        $this->bindProvider();
        config(['mobility_routing.synthetic_only' => false, 'mobility_routing.car_minor_per_km' => '51']);
        $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['pickup_point_id' => $pickup, 'dropoff_point_id' => $pickup + 1, 'vehicle_type' => 'car'])
            ->assertCreated()->assertJsonPath('data.synthetic_only', true)->assertJsonPath('data.seat_price_minor', 51);
        config(['mobility_routing.car_minor_per_km' => '51.25']);
        $this->actingAsApiUser($user)->postJson('/api/mobility/routes/quote', ['pickup_point_id' => $pickup, 'dropoff_point_id' => $pickup + 1, 'vehicle_type' => 'car'])->assertStatus(503);
    }

    private function fixture(): array
    {
        $user = User::factory()->create(['active' => true, 'banned' => false]);
        DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes_geo')->insert([['id' => 101, 'name' => 'Pickup', 'lat' => 12.97, 'lng' => 77.59], ['id' => 102, 'name' => 'Dropoff', 'lat' => 12.98, 'lng' => 77.60], ['id' => 103, 'name' => 'Bike dropoff', 'lat' => 12.99, 'lng' => 77.61]]);
        DB::table('mobility_pickup_points')->insert([['id' => 1, 'college_code' => 'CAMPUS-BLR', 'canonical_node_id' => 101, 'version' => 1, 'name' => 'Pickup', 'lat' => 12.97, 'lng' => 77.59, 'vehicle_modes' => json_encode(['car']), 'active' => true, 'verified_at' => now(), 'synthetic_only' => true, 'created_at' => now(), 'updated_at' => now()], ['id' => 2, 'college_code' => 'CAMPUS-BLR', 'canonical_node_id' => 102, 'version' => 1, 'name' => 'Dropoff', 'lat' => 12.98, 'lng' => 77.60, 'vehicle_modes' => json_encode(['car', 'bike']), 'active' => true, 'verified_at' => now(), 'synthetic_only' => true, 'created_at' => now(), 'updated_at' => now()], ['id' => 3, 'college_code' => 'CAMPUS-BLR', 'canonical_node_id' => 103, 'version' => 1, 'name' => 'Bike dropoff', 'lat' => 12.99, 'lng' => 77.61, 'vehicle_modes' => json_encode(['bike']), 'active' => true, 'verified_at' => now(), 'synthetic_only' => true, 'created_at' => now(), 'updated_at' => now()]]);

        return [$user, 1, 2];
    }

    private function bindProvider(): void
    {
        $this->app->bind(RouteProvider::class, fn () => new class implements RouteProvider
        {
            public function route(array $pickup, array $dropoff, string $vehicleType): array
            {
                return ['distance_meters' => 1000, 'duration_seconds' => 120, 'provider' => 'synthetic-test', 'profile' => $vehicleType];
            }
        });
    }
}
