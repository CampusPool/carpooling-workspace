<?php

namespace Tests\Feature\Mobility;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use STS\Mobility\Routing\OsrmRouteProvider;
use STS\Mobility\Routing\RoutingException;
use Tests\TestCase;

final class MobilityRoutingProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['mobility_routing.provider' => 'osrm', 'mobility_routing.car_base_url' => 'http://routes.example.test',
            'mobility_routing.bike_base_url' => 'http://motorbike.example.test', 'mobility_routing.car_profile' => 'car-prepared',
            'mobility_routing.bike_profile' => 'motorcycle-prepared',
            'mobility_routing.car_expected_data_version' => $this->version('car'),
            'mobility_routing.bike_expected_data_version' => $this->version('bike')]);
    }

    public function test_actual_http_adapter_preserves_mode_and_validates_both_snapped_locations(): void
    {
        Http::fake(fn ($request) => Http::response($this->validBody(str_contains($request->url(), 'motorbike') ? 'bike' : 'car')));
        $provider = new OsrmRouteProvider;
        foreach (['car', 'bike'] as $mode) {
            $quote = $provider->route($this->point(0), $this->point(1), $mode);
            $this->assertSame(2001, $quote['distance_meters']);
            $this->assertSame(601, $quote['duration_seconds']);
            $this->assertSame('osrm', $quote['provider']);
        }
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://routes.example.test/route/v1/car-prepared/77.59,12.97;77.6,12.98?'));
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://motorbike.example.test/route/v1/motorcycle-prepared/'));
        Http::assertSentCount(2);
    }

    public function test_unconfigured_and_public_demo_urls_never_trigger_a_provider_request(): void
    {
        Http::fake();
        foreach (['', 'https://router.project-osrm.org', 'https://ROUTER.PROJECT-OSRM.ORG.:443/path', 'http://u:p@router.project-osrm.org:80', 'https://routes.example.test?token=hidden'] as $base) {
            config(['mobility_routing.car_base_url' => $base]);
            $this->reject(fn () => (new OsrmRouteProvider)->route($this->point(0), $this->point(1), 'car'), 'ROUTING_MISCONFIGURED');
        }
        config(['mobility_routing.car_base_url' => 'http://routes.example.test', 'mobility_routing.car_expected_data_version' => null]);
        $this->reject(fn () => (new OsrmRouteProvider)->route($this->point(0), $this->point(1), 'car'), 'ROUTING_MISCONFIGURED');
        config(['mobility_routing.car_expected_data_version' => $this->version('bike')]);
        $this->reject(fn () => (new OsrmRouteProvider)->route($this->point(0), $this->point(1), 'car'), 'ROUTING_MISCONFIGURED');
        Http::assertNothingSent();
    }

    public function test_missing_or_mismatched_graph_identity_fails_closed(): void
    {
        foreach ([null, $this->version('bike'), 'car|crop_sha256=bad|profile_sha256=bad'] as $version) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake(fn () => Http::response(array_replace($this->validBody('car'), ['data_version' => $version])));
            $this->reject(fn () => (new OsrmRouteProvider)->route($this->point(0), $this->point(1), 'car'), $version === $this->version('bike') ? 'ROUTE_GRAPH_MISMATCH' : 'ROUTE_GRAPH_MISMATCH');
        }
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(array_replace($this->validBody('car'), ['data_version' => OsrmRouteProvider::dataVersion('car', str_repeat('e', 64), str_repeat('f', 64))])));
        $this->reject(fn () => (new OsrmRouteProvider)->route($this->point(0), $this->point(1), 'car'), 'ROUTE_GRAPH_MISMATCH');
    }

    public function test_graph_identity_helper_is_mode_specific_and_structured(): void
    {
        $version = OsrmRouteProvider::dataVersion('car', str_repeat('a', 64), str_repeat('b', 64));
        $this->assertSame('car|crop_sha256='.str_repeat('a', 64).'|profile_sha256='.str_repeat('b', 64), $version);
        $this->expectException(\InvalidArgumentException::class);
        OsrmRouteProvider::dataVersion('bike', 'crop', str_repeat('b', 64));
    }

    public function test_malformed_metrics_and_snaps_do_not_become_trusted_routes(): void
    {
        $cases = [];
        foreach ([0, -1, 100001, 1e90, 'NaN', '20', null] as $distance) {
            $body = $this->validBody();
            $body['routes'][0]['distance'] = $distance;
            $cases[] = [$body, 'ROUTE_INVALID'];
        }
        foreach ([0, -1, 86401, null] as $duration) {
            $body = $this->validBody();
            $body['routes'][0]['duration'] = $duration;
            $cases[] = [$body, 'ROUTE_INVALID'];
        }
        foreach ([[], [['distance' => 0]], null] as $waypoints) {
            $body = $this->validBody();
            $body['waypoints'] = $waypoints;
            $cases[] = [$body, 'ROUTE_SNAP_INVALID'];
        }
        $body = $this->validBody();
        $body['waypoints'][0]['distance'] = 251;
        $cases[] = [$body, 'ROUTE_SNAP_INVALID'];
        $body = $this->validBody();
        $body['waypoints'][1]['location'] = [77.70, 13.2];
        $cases[] = [$body, 'ROUTE_SNAP_TOO_FAR'];
        $body = $this->validBody();
        unset($body['waypoints'][0]['location']);
        $cases[] = [$body, 'ROUTE_SNAP_INVALID'];
        foreach ($cases as [$body, $code]) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake(fn () => Http::response($body));
            $this->reject(fn () => (new OsrmRouteProvider)->route($this->point(0), $this->point(1), 'car'), $code);
        }
    }

    public function test_redirect_server_error_no_route_and_timeout_are_bounded_failures(): void
    {
        foreach ([[302, ['code' => 'Ok'], 'ROUTE_PROVIDER_UNAVAILABLE'], [500, [], 'ROUTE_PROVIDER_UNAVAILABLE'], [400, ['code' => 'NoSegment'], 'NO_ROUTE'], [200, ['code' => 'NoRoute'], 'NO_ROUTE'], [200, '<not-json>', 'ROUTE_INVALID']] as [$status, $body, $code]) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake(fn () => Http::response($body, $status, ['Location' => 'https://another.example.test']));
            $this->reject(fn () => (new OsrmRouteProvider)->route($this->point(0), $this->point(1), 'car'), $code);
        }
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => throw new ConnectionException('Synthetic timeout'));
        $this->reject(fn () => (new OsrmRouteProvider)->route($this->point(0), $this->point(1), 'car'), 'ROUTE_PROVIDER_UNAVAILABLE');
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

    private function point(int $index): array
    {
        return [['lat' => 12.97, 'lng' => 77.59], ['lat' => 12.98, 'lng' => 77.60]][$index];
    }

    private function validBody(string $mode = 'car'): array
    {
        return ['code' => 'Ok', 'data_version' => $this->version($mode), 'routes' => [['distance' => 2000.1, 'duration' => 600.1]], 'waypoints' => [
            ['distance' => 0, 'location' => [77.59, 12.97]], ['distance' => 0, 'location' => [77.60, 12.98]],
        ]];
    }

    private function version(string $mode): string
    {
        return OsrmRouteProvider::dataVersion($mode, str_repeat($mode === 'car' ? 'a' : 'c', 64), str_repeat($mode === 'car' ? 'b' : 'd', 64));
    }
}
