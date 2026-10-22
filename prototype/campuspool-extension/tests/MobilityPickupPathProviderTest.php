<?php

namespace Tests\Feature\Mobility;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use STS\Mobility\Pickup\OsrmPathProvider;
use STS\Mobility\Routing\RoutingException;
use Tests\TestCase;

final class MobilityPickupPathProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['mobility_routing.provider' => 'osrm', 'mobility_routing.car_base_url' => 'http://routes.example.test',
            'mobility_routing.car_profile' => 'driving', 'mobility_routing.car_expected_data_version' => $this->version('car')]);
    }

    public function test_full_path_preserves_geometry_waypoints_legs_and_mode_request(): void
    {
        Http::fake(fn ($request) => Http::response($this->body('car')));
        $path = (new OsrmPathProvider)->path($this->points(), 'car');

        $this->assertSame(2001, $path['distance_meters']);
        $this->assertSame(601, $path['duration_seconds']);
        $this->assertCount(3, $path['geometry']);
        $this->assertCount(2, $path['waypoints']);
        $this->assertCount(1, $path['legs']);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://routes.example.test/route/v1/driving/77.59,12.97;77.6,12.98?')
            && str_contains($request->url(), 'overview=full') && str_contains($request->url(), 'geometries=geojson') && str_contains($request->url(), 'radiuses=250%3B250'));
    }

    public function test_foot_requires_foot_graph_and_uses_50_meter_snaps(): void
    {
        config(['mobility_routing.foot_base_url' => 'http://walk.example.test', 'mobility_routing.foot_profile' => 'distance-walk',
            'mobility_routing.foot_expected_data_version' => $this->version('foot')]);
        Http::fake(fn ($request) => Http::response($this->body('foot')));
        (new OsrmPathProvider)->path($this->points(), 'foot');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'radiuses=50%3B50') && str_contains($request->url(), '/route/v1/distance-walk/'));

        config(['mobility_routing.foot_expected_data_version' => $this->version('car')]);
        $this->reject(fn () => (new OsrmPathProvider)->path($this->points(), 'foot'), 'ROUTING_MISCONFIGURED');
    }

    public function test_malformed_geometry_metrics_legs_and_excessive_foot_snap_fail_closed(): void
    {
        config(['mobility_routing.foot_base_url' => 'http://walk.example.test', 'mobility_routing.foot_profile' => 'walk',
            'mobility_routing.foot_expected_data_version' => $this->version('foot')]);
        $cases = [
            [fn (array $body): array => tap($body, function (&$value): void { $value['routes'][0]['geometry']['type'] = 'Polygon'; }), 'ROUTE_INVALID'],
            [fn (array $body): array => tap($body, function (&$value): void { $value['routes'][0]['distance'] = 'NaN'; }), 'ROUTE_INVALID'],
            [fn (array $body): array => tap($body, function (&$value): void { $value['routes'][0]['legs'] = []; }), 'ROUTE_INVALID'],
            [fn (array $body): array => tap($body, function (&$value): void { $value['waypoints'][0]['distance'] = 51; }), 'ROUTE_SNAP_INVALID'],
        ];
        foreach ($cases as [$mutate, $code]) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            $body = $mutate($this->body('foot'));
            Http::fake(fn () => Http::response($body));
            $this->reject(fn () => (new OsrmPathProvider)->path($this->points(), 'foot'), $code);
        }
    }

    public function test_graph_mode_hash_geometry_endpoint_and_leg_sum_mismatches_fail_closed(): void
    {
        $cases = [
            [fn (array $body): array => tap($body, function (&$value): void { $value['data_version'] = $this->version('bike'); }), 'ROUTE_GRAPH_MISMATCH'],
            [fn (array $body): array => tap($body, function (&$value): void { $value['routes'][0]['geometry']['coordinates'][0] = [77.591, 12.97]; }), 'ROUTE_INVALID'],
            [fn (array $body): array => tap($body, function (&$value): void { $value['routes'][0]['legs'][0]['distance'] = 1000; }), 'ROUTE_INVALID'],
        ];
        foreach ($cases as [$mutate, $code]) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake(fn () => Http::response($mutate($this->body('car'))));
            $this->reject(fn () => (new OsrmPathProvider)->path($this->points(), 'car'), $code);
        }
    }

    public function test_private_url_redirect_provider_error_and_timeout_are_bounded(): void
    {
        foreach (['https://u:p@routes.example.test', 'https://routes.example.test?token=secret', 'https://routes.example.test#fragment'] as $base) {
            config(['mobility_routing.car_base_url' => $base]);
            $this->reject(fn () => (new OsrmPathProvider)->path($this->points(), 'car'), 'ROUTING_MISCONFIGURED');
        }
        config(['mobility_routing.car_base_url' => 'http://routes.example.test']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => Http::response(['code' => 'Ok'], 302, ['Location' => 'https://other.example.test']));
        $this->reject(fn () => (new OsrmPathProvider)->path($this->points(), 'car'), 'ROUTE_PROVIDER_UNAVAILABLE');
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->reject(fn () => (new OsrmPathProvider)->path($this->points(), 'car'), 'ROUTE_PROVIDER_UNAVAILABLE');
    }

    public function test_zero_road_leg_requires_coincident_network_snaps_and_preserves_input_gaps(): void
    {
        config(['mobility_routing.foot_base_url'=>'http://walk.example.test','mobility_routing.foot_profile'=>'walk','mobility_routing.foot_expected_data_version'=>$this->version('foot')]);
        $points=[['lat'=>12.9701,'lng'=>77.59],['lat'=>12.9702,'lng'=>77.59]];
        $body=['code'=>'Ok','data_version'=>$this->version('foot'),'waypoints'=>[
            ['location'=>[77.59,12.97],'distance'=>12],['location'=>[77.59,12.97],'distance'=>23]],
            'routes'=>[['distance'=>0,'duration'=>0,'geometry'=>['type'=>'LineString','coordinates'=>[[77.59,12.97],[77.59,12.97]]],'legs'=>[['distance'=>0,'duration'=>0]]]]];
        Http::fake(fn()=>Http::response($body));
        $result=(new OsrmPathProvider)->path($points,'foot');
        $this->assertSame(0,$result['distance_meters']);
        $this->assertSame(12.0,$result['waypoints'][0]['distance_meters']);
        $this->assertSame(23.0,$result['waypoints'][1]['distance_meters']);
        Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
        $body['waypoints'][1]['location']=[77.59,12.9703];
        $body['routes'][0]['geometry']['coordinates'][1]=[77.59,12.9703];
        Http::fake(fn()=>Http::response($body));
        $this->reject(fn()=>(new OsrmPathProvider)->path($points,'foot'),'ROUTE_INVALID');
    }

    private function reject(callable $call, string $code): void
    {
        try {
            $call();
            $this->fail('Expected rejection '.$code);
        } catch (RoutingException $error) {
            $this->assertSame($code, $error->machineCode);
        }
    }

    private function points(): array
    {
        return [['lat' => 12.97, 'lng' => 77.59], ['lat' => 12.98, 'lng' => 77.60]];
    }

    private function version(string $mode): string
    {
        return $mode.'|crop_sha256='.str_repeat($mode === 'car' ? 'a' : 'c', 64).'|profile_sha256='.str_repeat($mode === 'car' ? 'b' : 'd', 64);
    }

    private function body(string $mode): array
    {
        return ['code' => 'Ok', 'data_version' => $this->version($mode), 'waypoints' => [
            ['distance' => 0, 'location' => [77.59, 12.97]], ['distance' => 0, 'location' => [77.60, 12.98]],
        ], 'routes' => [[
            'distance' => 2000.1, 'duration' => 600.1,
            'geometry' => ['type' => 'LineString', 'coordinates' => [[77.59, 12.97], [77.595, 12.975], [77.60, 12.98]]],
            'legs' => [['distance' => 2000.1, 'duration' => 600.1]],
        ]]];
    }
}
