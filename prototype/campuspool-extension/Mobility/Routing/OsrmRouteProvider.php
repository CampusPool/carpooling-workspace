<?php

namespace STS\Mobility\Routing;

use Illuminate\Support\Facades\Http;

final class OsrmRouteProvider implements RouteProvider
{
    public function route(array $pickup, array $dropoff, string $vehicleType): array
    {
        if (! in_array($vehicleType, ['bike', 'car'], true)) {
            throw new RoutingException('INVALID_VEHICLE_TYPE');
        }
        $base = trim((string) config('mobility_routing.'.$vehicleType.'_base_url', ''));
        $profile = trim((string) config('mobility_routing.'.$vehicleType.'_profile', ''));
        $expectedVersion = config('mobility_routing.'.$vehicleType.'_expected_data_version');
        $synthetic = config('mobility_routing.synthetic_only', false) === true && app()->environment('testing');
        $parts = parse_url($base);
        if (config('mobility_routing.provider') !== 'osrm' || ! filter_var($base, FILTER_VALIDATE_URL)
            || ! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || rtrim(strtolower($parts['host'] ?? ''), '.') === 'router.project-osrm.org'
            || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $profile)
            || (! $synthetic && ! self::validDataVersion($expectedVersion, $vehicleType))
            || ($synthetic && $expectedVersion !== null && ! self::validDataVersion($expectedVersion, $vehicleType))) {
            throw new RoutingException('ROUTING_MISCONFIGURED');
        }
        foreach ([$pickup, $dropoff] as $point) {
            if (! $this->coordinate($point['lat'] ?? null, -90, 90) || ! $this->coordinate($point['lng'] ?? null, -180, 180)) {
                throw new RoutingException('ROUTE_INVALID');
            }
        }
        $url = rtrim($base, '/').'/route/v1/'.$profile.'/'.$pickup['lng'].','.$pickup['lat'].';'.$dropoff['lng'].','.$dropoff['lat'];
        try {
            $response = Http::connectTimeout(2)->timeout(5)->withoutRedirecting()->acceptJson()->get($url, [
                'overview' => 'false', 'steps' => 'false', 'alternatives' => 'false', 'radiuses' => '250;250',
            ]);
        } catch (\Throwable) {
            throw new RoutingException('ROUTE_PROVIDER_UNAVAILABLE');
        }
        if (! $response->successful()) {
            // OSRM may return 400 for a location that cannot snap to its graph.
            if ($response->status() === 400 && in_array($response->json('code'), ['NoRoute', 'NoSegment'], true)) {
                throw new RoutingException('NO_ROUTE');
            }
            throw new RoutingException('ROUTE_PROVIDER_UNAVAILABLE');
        }
        if (strlen($response->body()) > 65536) {
            throw new RoutingException('ROUTE_INVALID');
        }
        $body = $response->json();
        if (is_array($body) && in_array($body['code'] ?? '', ['NoRoute', 'NoSegment'], true)) {
            throw new RoutingException('NO_ROUTE');
        }
        if (! is_array($body) || ($body['code'] ?? null) !== 'Ok' || ! is_array($body['routes'][0] ?? null)) {
            throw new RoutingException('ROUTE_INVALID');
        }
        $dataVersion = $body['data_version'] ?? null;
        if ($dataVersion === null && $synthetic) {
            // Explicit test fixtures may exercise the HTTP adapter without a graph manifest.
        } elseif (! self::validDataVersion($dataVersion, $vehicleType) || ($expectedVersion !== null && ! hash_equals((string) $expectedVersion, (string) $dataVersion))) {
            throw new RoutingException('ROUTE_GRAPH_MISMATCH');
        }
        $distance = $body['routes'][0]['distance'] ?? null;
        $duration = $body['routes'][0]['duration'] ?? null;
        if (! $this->positiveMetric($distance, 100000) || ! $this->positiveMetric($duration, 86400)) {
            throw new RoutingException('ROUTE_INVALID');
        }
        if (! is_array($body['waypoints'] ?? null) || ! array_is_list($body['waypoints']) || count($body['waypoints']) !== 2) {
            throw new RoutingException('ROUTE_SNAP_INVALID');
        }
        foreach ($body['waypoints'] as $index => $waypoint) {
            $snap = $waypoint['distance'] ?? null;
            $location = $waypoint['location'] ?? null;
            if (! $this->coordinate($snap, 0, 250) || ! is_array($location) || ! array_is_list($location) || count($location) !== 2
                || ! $this->coordinate($location[0], -180, 180) || ! $this->coordinate($location[1], -90, 90)) {
                throw new RoutingException('ROUTE_SNAP_INVALID');
            }
            $point = [$pickup, $dropoff][$index];
            $latDelta = deg2rad($location[1] - $point['lat']);
            $lngDelta = deg2rad($location[0] - $point['lng']);
            $a = sin($latDelta / 2) ** 2 + cos(deg2rad($point['lat'])) * cos(deg2rad($location[1])) * sin($lngDelta / 2) ** 2;
            if (6371000 * 2 * asin(sqrt(min(1, max(0, $a)))) > 250) {
                throw new RoutingException('ROUTE_SNAP_TOO_FAR');
            }
        }

        return ['distance_meters' => (int) ceil($distance), 'duration_seconds' => (int) ceil($duration), 'provider' => 'osrm', 'profile' => $profile, 'data_version' => $dataVersion];
    }

    /** Build the graph identity used by the runtime and its prepared manifests. */
    public static function dataVersion(string $vehicleType, string $cropHash, string $profileHash): string
    {
        if (! in_array($vehicleType, ['car', 'bike'], true) || ! preg_match('/^[a-f0-9]{64}$/D', $cropHash) || ! preg_match('/^[a-f0-9]{64}$/D', $profileHash)) {
            throw new \InvalidArgumentException('Invalid routing graph identity');
        }

        return $vehicleType.'|crop_sha256='.$cropHash.'|profile_sha256='.$profileHash;
    }

    private static function validDataVersion(mixed $value, string $vehicleType): bool
    {
        return RouteDataVersion::valid($value, $vehicleType);
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= $minimum && $value <= $maximum;
    }

    private function positiveMetric(mixed $value, int $maximum): bool
    {
        return $this->coordinate($value, 0, $maximum) && $value > 0;
    }
}
