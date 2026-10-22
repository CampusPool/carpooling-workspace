<?php

namespace STS\Mobility\Pickup;

use Illuminate\Support\Facades\Http;
use STS\Mobility\Routing\RouteDataVersion;
use STS\Mobility\Routing\RoutingException;

final class OsrmPathProvider implements PathProvider
{
    private const MAX_BODY_BYTES = 2097152;
    private const MAX_POINTS = 8;
    private const MAX_GEOMETRY_POINTS = 20000;
    private const MAX_DISTANCE_METERS = 100000;
    private const MAX_DURATION_SECONDS = 86400;

    public function path(array $points, string $mode, float $timeoutSeconds = 5): array
    {
        if (! is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 5) {
            throw new RoutingException('ROUTE_PROVIDER_UNAVAILABLE');
        }
        if (! in_array($mode, ['car', 'bike', 'foot'], true) || count($points) < 2 || count($points) > self::MAX_POINTS || ! array_is_list($points)) {
            throw new RoutingException('ROUTE_INVALID');
        }
        foreach ($points as $point) {
            if (! is_array($point) || ! $this->coordinate($point['lat'] ?? null, -90, 90) || ! $this->coordinate($point['lng'] ?? null, -180, 180)) {
                throw new RoutingException('ROUTE_INVALID');
            }
        }

        $prefix = $mode === 'foot' ? 'foot' : $mode;
        $base = trim((string) config('mobility_routing.'.$prefix.'_base_url', ''));
        $profile = trim((string) config('mobility_routing.'.$prefix.'_profile', ''));
        $expectedVersion = config('mobility_routing.'.$prefix.'_expected_data_version');
        $parts = parse_url($base);
        if (config('mobility_routing.provider') !== 'osrm' || ! filter_var($base, FILTER_VALIDATE_URL)
            || ! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || rtrim(strtolower($parts['host'] ?? ''), '.') === 'router.project-osrm.org'
            || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $profile)
            || ! $this->validVersion($expectedVersion, $mode)) {
            throw new RoutingException('ROUTING_MISCONFIGURED');
        }

        $coordinates = implode(';', array_map(static fn (array $point): string => $point['lng'].','.$point['lat'], $points));
        $radius = $mode === 'foot' ? 50 : 250;
        $url = rtrim($base, '/').'/route/v1/'.$profile.'/'.$coordinates;
        try {
            $response = Http::connectTimeout(min(2, $timeoutSeconds))->timeout($timeoutSeconds)->withoutRedirecting()->acceptJson()->get($url, [
                'overview' => 'full', 'geometries' => 'geojson', 'steps' => 'false', 'alternatives' => 'false',
                'radiuses' => implode(';', array_fill(0, count($points), (string) $radius)),
            ]);
        } catch (\Throwable) {
            throw new RoutingException('ROUTE_PROVIDER_UNAVAILABLE');
        }
        if (! $response->successful()) {
            if ($response->status() === 400 && in_array($response->json('code'), ['NoRoute', 'NoSegment'], true)) {
                throw new RoutingException('NO_ROUTE');
            }
            throw new RoutingException('ROUTE_PROVIDER_UNAVAILABLE');
        }
        $raw = $response->body();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new RoutingException('ROUTE_INVALID');
        }
        $body = $response->json();
        if (is_array($body) && in_array($body['code'] ?? '', ['NoRoute', 'NoSegment'], true)) {
            throw new RoutingException('NO_ROUTE');
        }
        if (! is_array($body) || ($body['code'] ?? null) !== 'Ok' || ! is_array($body['routes'][0] ?? null)) {
            throw new RoutingException('ROUTE_INVALID');
        }
        if (! is_string($body['data_version'] ?? null) || ! hash_equals($expectedVersion, $body['data_version']) || ! $this->validVersion($body['data_version'], $mode)) {
            throw new RoutingException('ROUTE_GRAPH_MISMATCH');
        }
        $route = $body['routes'][0];
        $distance = $route['distance'] ?? null;
        $duration = $route['duration'] ?? null;
        if (! $this->metric($distance, self::MAX_DISTANCE_METERS) || ! $this->metric($duration, self::MAX_DURATION_SECONDS)) {
            throw new RoutingException('ROUTE_INVALID');
        }
        $waypoints = $body['waypoints'] ?? null;
        if (! is_array($waypoints) || ! array_is_list($waypoints) || count($waypoints) !== count($points)) {
            throw new RoutingException('ROUTE_SNAP_INVALID');
        }
        $snapped = [];
        foreach ($waypoints as $index => $waypoint) {
            $snap = $waypoint['distance'] ?? null;
            $location = $waypoint['location'] ?? null;
            if (! $this->metric($snap, $radius) || ! is_array($location) || ! array_is_list($location) || count($location) !== 2
                || ! $this->coordinate($location[0] ?? null, -180, 180) || ! $this->coordinate($location[1] ?? null, -90, 90)
                || $this->haversine($points[$index], ['lat' => $location[1], 'lng' => $location[0]]) > $radius) {
                throw new RoutingException('ROUTE_SNAP_INVALID');
            }
            $snapped[] = ['distance_meters' => (float) $snap, 'location' => [(float) $location[0], (float) $location[1]]];
        }

        $geometryObject = $route['geometry'] ?? null;
        $geometry = is_array($geometryObject) && ($geometryObject['type'] ?? null) === 'LineString'
            ? ($geometryObject['coordinates'] ?? null) : null;
        if (! is_array($geometry) || ! array_is_list($geometry) || count($geometry) < 2 || count($geometry) > self::MAX_GEOMETRY_POINTS) {
            throw new RoutingException('ROUTE_INVALID');
        }
        foreach ($geometry as $coordinate) {
            if (! is_array($coordinate) || ! array_is_list($coordinate) || count($coordinate) !== 2
                || ! $this->coordinate($coordinate[0] ?? null, -180, 180) || ! $this->coordinate($coordinate[1] ?? null, -90, 90)) {
                throw new RoutingException('ROUTE_INVALID');
            }
        }
        if ($this->haversine(['lat' => $geometry[0][1], 'lng' => $geometry[0][0]], ['lat' => $snapped[0]['location'][1], 'lng' => $snapped[0]['location'][0]]) > 1
            || $this->haversine(['lat' => $geometry[array_key_last($geometry)][1], 'lng' => $geometry[array_key_last($geometry)][0]], ['lat' => $snapped[array_key_last($snapped)]['location'][1], 'lng' => $snapped[array_key_last($snapped)]['location'][0]]) > 1) {
            throw new RoutingException('ROUTE_INVALID');
        }

        $legs = $route['legs'] ?? null;
        if (! is_array($legs) || ! array_is_list($legs) || count($legs) !== count($points) - 1) {
            throw new RoutingException('ROUTE_INVALID');
        }
        $normalizedLegs = [];
        $sumDistance = 0.0;
        $sumDuration = 0.0;
        foreach ($legs as $index => $leg) {
            $legDistance = $leg['distance'] ?? null;
            $legDuration = $leg['duration'] ?? null;
            if (! $this->metric($legDistance, self::MAX_DISTANCE_METERS) || ! $this->metric($legDuration, self::MAX_DURATION_SECONDS)) {
                throw new RoutingException('ROUTE_INVALID');
            }
            // A legitimate zero road leg may join two inputs snapped to the same
            // network point. Callers still count the bounded access gaps.
            $coincident = $this->haversine(['lat'=>$snapped[$index]['location'][1], 'lng'=>$snapped[$index]['location'][0]], ['lat'=>$snapped[$index + 1]['location'][1], 'lng'=>$snapped[$index + 1]['location'][0]]) <= 1;
            if (! $coincident && ((float) $legDistance === 0.0 || (float) $legDuration === 0.0)) {
                throw new RoutingException('ROUTE_INVALID');
            }
            if ($coincident && (((float) $legDistance === 0.0) !== ((float) $legDuration === 0.0))) {
                throw new RoutingException('ROUTE_INVALID');
            }
            $sumDistance += (float) $legDistance;
            $sumDuration += (float) $legDuration;
            $normalizedLegs[] = ['distance_meters' => (int) ceil($legDistance), 'duration_seconds' => (int) ceil($legDuration)];
        }
        $allCoincident = true;
        foreach (array_slice($points, 1) as $index => $point) {
            $allCoincident = $allCoincident && $this->haversine(['lat'=>$snapped[0]['location'][1], 'lng'=>$snapped[0]['location'][0]], ['lat'=>$snapped[$index + 1]['location'][1], 'lng'=>$snapped[$index + 1]['location'][0]]) <= 1;
        }
        if (! $allCoincident && ((float) $distance === 0.0 || (float) $duration === 0.0)) {
            throw new RoutingException('ROUTE_INVALID');
        }
        if ($allCoincident && (((float) $distance === 0.0) !== ((float) $duration === 0.0))) {
            throw new RoutingException('ROUTE_INVALID');
        }
        if ($sumDistance > self::MAX_DISTANCE_METERS || $sumDuration > self::MAX_DURATION_SECONDS) {
            throw new RoutingException('ROUTE_INVALID');
        }
        if (abs($sumDistance - (float) $distance) > max(1, count($legs) * 0.2)
            || abs($sumDuration - (float) $duration) > max(1, count($legs) * 0.2)) {
            throw new RoutingException('ROUTE_INVALID');
        }

        return ['distance_meters' => (int) ceil($distance), 'duration_seconds' => (int) ceil($duration), 'provider' => 'osrm', 'profile' => $profile,
            'data_version' => $body['data_version'], 'geometry' => array_map(static fn (array $coordinate): array => [(float) $coordinate[0], (float) $coordinate[1]], $geometry),
            'waypoints' => $snapped, 'legs' => $normalizedLegs];
    }

    private function validVersion(mixed $value, string $mode): bool
    {
        return $mode === 'foot'
            ? is_string($value) && preg_match('/^foot\|crop_sha256=[a-f0-9]{64}\|profile_sha256=[a-f0-9]{64}$/D', $value) === 1
            : RouteDataVersion::valid($value, $mode);
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= $minimum && $value <= $maximum;
    }

    private function metric(mixed $value, float $maximum): bool
    {
        return $this->coordinate($value, 0, $maximum);
    }

    private function haversine(array $from, array $to): float
    {
        $lat = deg2rad($to['lat'] - $from['lat']);
        $lng = deg2rad($to['lng'] - $from['lng']);
        $a = sin($lat / 2) ** 2 + cos(deg2rad($from['lat'])) * cos(deg2rad($to['lat'])) * sin($lng / 2) ** 2;
        return 6371000 * 2 * asin(sqrt(min(1, max(0, $a))));
    }
}
