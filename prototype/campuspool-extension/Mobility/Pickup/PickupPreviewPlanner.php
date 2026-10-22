<?php

namespace STS\Mobility\Pickup;

use STS\Mobility\Routing\RouteDataVersion;
use STS\Mobility\Routing\RoutingException;

/** Computes proposals only. It has no booking, fare or location-write authority. */
class PickupPreviewPlanner
{
    private const MAX_CANDIDATES = 4;
    private const IO_SECONDS = 20;

    public function __construct(private readonly PathProvider $paths, private readonly ?\Closure $clock = null) {}

    public function preview(array $driverPath, array $origin, string $mode, int $maxWalk, int $maxDetourM, int $maxDetourS): array
    {
        $points = $driverPath['points'] ?? null;
        $geometry = $driverPath['geometry'] ?? null;
        if (! in_array($mode, ['car', 'bike'], true) || ! RouteDataVersion::valid($driverPath['data_version'] ?? null, $mode)
            || ! is_array($points) || ! array_is_list($points) || count($points) !== 2
            || ! is_array($geometry) || ! array_is_list($geometry) || count($geometry) < 2 || count($geometry) > 20000
            || ! is_int($driverPath['distance_meters'] ?? null) || $driverPath['distance_meters'] < 1 || $driverPath['distance_meters'] > 100000
            || ! is_int($driverPath['duration_seconds'] ?? null) || $driverPath['duration_seconds'] < 1 || $driverPath['duration_seconds'] > 86400
            || $maxWalk < 0 || $maxWalk > PickupPolicy::MAX_WALK_METERS || $maxDetourM < 0 || $maxDetourM > 100000 || $maxDetourS < 0 || $maxDetourS > 86400) {
            throw new PickupException('PICKUP_INPUT_INVALID');
        }
        foreach ([$origin, ...$points] as $point) {
            $this->point($point);
        }
        foreach ($geometry as $pair) {
            if (! is_array($pair) || ! array_is_list($pair) || count($pair) !== 2) {
                throw new PickupException('PICKUP_ROUTE_INVALID');
            }
            $this->point(['lat' => $pair[1], 'lng' => $pair[0]]);
        }
        $this->validatePath($driverPath, $points, $mode);
        $deadline = $this->now() + self::IO_SECONDS;
        $vehicleVersion = $driverPath['data_version'];
        $direct = ['available' => false, 'code' => 'NO_ROUTE'];
        try {
            $path = $this->path([$points[0], $origin, $points[1]], $mode, $deadline);
            $this->vehicleIdentity($path, $vehicleVersion);
            $detour = $this->detour($path, $driverPath);
            $direct = $this->within($detour, $maxDetourM, $maxDetourS)
                ? ['available' => true, 'choice' => 'DIRECT_RIDER_PICKUP', 'requested_pickup' => $origin,
                    'vehicle_pickup_location' => $path['waypoints'][1]['location'], 'vehicle_snap_meters' => $path['waypoints'][1]['distance_meters'],
                    'walking_distance_meters' => null, 'walking_status' => 'not_evaluated', 'path' => $path, 'detour' => $detour,
                    'access_verified' => false, 'requires_pickup_confirmation' => true]
                : ['available' => false, 'code' => 'DETOUR_LIMIT_EXCEEDED', 'detour' => $detour];
        } catch (RoutingException $error) {
            if ($error->machineCode !== 'NO_ROUTE') {
                throw new PickupException($error->machineCode, 503);
            }
        }
        $candidates = $this->candidates($geometry, $origin, $maxWalk);
        $meeting = [];
        $footVersion = null;
        $evaluated = 0;
        $unavailable = 'NO_ELIGIBLE_MEETING_POINT';
        foreach ($candidates as $candidate) {
            $evaluated++;
            try {
                $vehicle = $this->path([$points[0], $candidate['point'], $points[1]], $mode, $deadline);
                $this->vehicleIdentity($vehicle, $vehicleVersion);
                $detour = $this->detour($vehicle, $driverPath);
                if (! $this->within($detour, $maxDetourM, $maxDetourS)) {
                    continue;
                }
                // Walk to the actual vehicle snap, rather than an unrelated projected point.
                $pickup = $vehicle['waypoints'][1]['location'];
                $walk = $this->path([$origin, ['lng' => $pickup[0], 'lat' => $pickup[1]]], 'foot', $deadline);
                $version = $walk['data_version'] ?? null;
                if (! is_string($version) || preg_match('/^foot\|crop_sha256=[a-f0-9]{64}\|profile_sha256=[a-f0-9]{64}$/D', $version) !== 1
                    || ($footVersion !== null && $footVersion !== $version)) {
                    throw new PickupException('ROUTE_GRAPH_MISMATCH', 503);
                }
                $footVersion = $version;
                $walkDistance = $walk['distance_meters'];
                $snaps = array_sum(array_column($walk['waypoints'], 'distance_meters'));
                $walkEnd = $walk['waypoints'][1]['location'];
                $handoffGap = $this->distance(['lng' => $pickup[0], 'lat' => $pickup[1]], ['lng' => $walkEnd[0], 'lat' => $walkEnd[1]]);
                if ($handoffGap > 50) {
                    continue;
                }
                // Unmapped endpoint gaps are displayed separately and counted conservatively
                // against the preference. They are not represented as routed walking paths.
                $unmappedAccessGap = (int) ceil($snaps);
                $totalAccess = $walkDistance + $unmappedAccessGap + (int) ceil($handoffGap);
                if ($totalAccess > $maxWalk) {
                    continue;
                }
                $meeting[] = ['available' => true, 'choice' => 'ON_ROUTE_MEETING_POINT', 'route_position' => $candidate['position'],
                    'vehicle_pickup_location' => $pickup, 'pedestrian_pickup_location' => $walkEnd,
                    'handoff_gap_meters' => $handoffGap,
                    'walking_distance_meters' => $walkDistance, 'walking_duration_seconds' => $walk['duration_seconds'],
                    'total_access_meters' => $totalAccess,
                    'unmapped_access_gap_meters' => $unmappedAccessGap, 'walking_path' => $walk, 'vehicle_path' => $vehicle, 'detour' => $detour,
                    'access_verified' => false, 'requires_pickup_confirmation' => true];
            } catch (RoutingException $error) {
                if ($error->machineCode === 'NO_ROUTE') {
                    continue;
                }
                if ($error->machineCode === 'ROUTE_PROVIDER_UNAVAILABLE' || $error->machineCode === 'ROUTING_MISCONFIGURED') {
                    // Only the meeting option becomes unavailable; no road route is used as a walk.
                    $unavailable = $error->machineCode;
                    $meeting = [];
                    break;
                }
                throw new PickupException($error->machineCode, 503);
            }
        }
        usort($meeting, static fn (array $a, array $b): int => [$a['total_access_meters'], $a['detour']['added_distance_meters'], $a['route_position']]
            <=> [$b['total_access_meters'], $b['detour']['added_distance_meters'], $b['route_position']]);

        return ['direct' => $direct, 'meeting' => $meeting === [] ? ['available' => false, 'code' => $unavailable] : $meeting[0],
            'alternatives' => array_slice($meeting, 1), 'evaluated_candidates' => $evaluated, 'candidate_limit' => self::MAX_CANDIDATES,
            'ranking' => 'shortest_total_access_meters_among_evaluated_candidates', 'vehicle_data_version' => $vehicleVersion, 'foot_data_version' => $footVersion,
            'preferences' => ['max_walk_meters' => $maxWalk, 'max_detour_meters' => $maxDetourM, 'max_detour_seconds' => $maxDetourS],
            'access_verified' => false, 'booking_enabled' => false];
    }

    /** Only pedestrian I/O is allowed when adding a rider to a published route. */
    public function previewPublished(array $canonical, array $origin, string $mode, int $maxWalk, int $maxDetourM, int $maxDetourS, string $routeHash): array
    {
        PickupPolicy::walkPreference($maxWalk, 422);
        $this->point($origin);
        $points = array_map(fn ($w) => ['lng' => $w['location'][0], 'lat' => $w['location'][1]], $canonical['waypoints'] ?? []);
        if (count($points) !== 4 || ! in_array($mode, ['car', 'bike'], true)) throw new PickupException('PICKUP_INPUT_INVALID');
        $this->validatePath($canonical, $points, $mode);
        $projector = new \STS\Mobility\Journeys\CanonicalRouteIntervalProjector();
        $zeroDetour = ['added_distance_meters' => 0, 'added_duration_seconds' => 0];
        $direct = ['available' => false, 'code' => 'PUBLICATION_ROUTE_INCOMPATIBLE'];
        try {
            $path = $projector->pickupPath($canonical, $origin, $routeHash);
            $direct = ['available' => true, 'choice' => 'DIRECT_RIDER_PICKUP', 'requested_pickup' => $origin,
                'vehicle_pickup_location' => $path['waypoints'][1]['location'], 'vehicle_snap_meters' => $path['waypoints'][1]['distance_meters'],
                'walking_distance_meters' => null, 'walking_status' => 'not_evaluated', 'path' => $path, 'detour' => $zeroDetour,
                'access_verified' => false, 'requires_pickup_confirmation' => true];
        } catch (\InvalidArgumentException) {}
        $deadline = $this->now() + self::IO_SECONDS; $meeting = []; $footVersion = null; $evaluated = 0;
        foreach ($this->candidates($canonical['geometry'], $origin, $maxWalk) as $candidate) {
            $evaluated++;
            try {
                $vehicle = $projector->pickupPath($canonical, $candidate['point'], $routeHash);
                $pickup = $vehicle['waypoints'][1]['location'];
                $walk = $this->path([$origin, ['lng' => $pickup[0], 'lat' => $pickup[1]]], 'foot', $deadline);
                $version = $walk['data_version'];
                if ($footVersion !== null && $footVersion !== $version) throw new PickupException('PICKUP_GRAPH_MISMATCH', 503);
                $footVersion = $version;
                $walkEnd = $walk['waypoints'][1]['location'];
                $handoff = $this->distance(['lng' => $pickup[0], 'lat' => $pickup[1]], ['lng' => $walkEnd[0], 'lat' => $walkEnd[1]]);
                $gaps = (int) ceil(array_sum(array_column($walk['waypoints'], 'distance_meters')));
                $total = $walk['distance_meters'] + $gaps + (int) ceil($handoff);
                if ($handoff > 50 || $total > $maxWalk) continue;
                $meeting[] = ['available' => true, 'choice' => 'ON_ROUTE_MEETING_POINT', 'route_position' => $candidate['position'],
                    'vehicle_pickup_location' => $pickup, 'pedestrian_pickup_location' => $walkEnd, 'handoff_gap_meters' => $handoff,
                    'walking_distance_meters' => $walk['distance_meters'], 'walking_duration_seconds' => $walk['duration_seconds'],
                    'total_access_meters' => $total, 'unmapped_access_gap_meters' => $gaps, 'walking_path' => $walk,
                    'vehicle_path' => $vehicle, 'detour' => $zeroDetour, 'access_verified' => false, 'requires_pickup_confirmation' => true];
            } catch (\InvalidArgumentException) { continue; }
            catch (RoutingException $error) {
                if ($error->machineCode === 'NO_ROUTE') continue;
                if (in_array($error->machineCode, ['ROUTE_PROVIDER_UNAVAILABLE', 'ROUTING_MISCONFIGURED'], true)) { $meeting = []; break; }
                throw new PickupException($error->machineCode, 503);
            }
        }
        usort($meeting, static fn ($a, $b) => [$a['total_access_meters'], $a['route_position']] <=> [$b['total_access_meters'], $b['route_position']]);
        return ['direct' => $direct, 'meeting' => $meeting[0] ?? ['available' => false, 'code' => 'PUBLICATION_ROUTE_INCOMPATIBLE'],
            'alternatives' => array_slice($meeting, 1), 'evaluated_candidates' => $evaluated, 'candidate_limit' => self::MAX_CANDIDATES,
            'ranking' => 'shortest_total_access_meters_among_evaluated_candidates', 'vehicle_data_version' => $canonical['data_version'], 'foot_data_version' => $footVersion,
            'preferences' => ['max_walk_meters' => $maxWalk, 'max_detour_meters' => $maxDetourM, 'max_detour_seconds' => $maxDetourS],
            'access_verified' => false, 'booking_enabled' => false];
    }

    private function path(array $points, string $mode, float $deadline): array
    {
        if ($this->now() >= $deadline) {
            throw new PickupException('PICKUP_ROUTING_DEADLINE', 503);
        }
        $path = $this->paths->path($points, $mode, min(5, max(0.001, $deadline - $this->now())));
        $this->validatePath($path, $points, $mode);
        if ($this->now() >= $deadline) {
            throw new PickupException('PICKUP_ROUTING_DEADLINE', 503);
        }
        return $path;
    }

    private function validatePath(array $path, array $points, string $mode): void
    {
        $version = $path['data_version'] ?? null;
        $validVersion = $mode === 'foot'
            ? is_string($version) && preg_match('/^foot\|crop_sha256=[a-f0-9]{64}\|profile_sha256=[a-f0-9]{64}$/D', $version) === 1
            : RouteDataVersion::valid($version, $mode);
        if (! $validVersion || ($path['provider'] ?? null) !== 'osrm' || ! is_string($path['profile'] ?? null) || $path['profile'] === '') {
            throw new PickupException('PICKUP_ROUTE_INVALID', 503);
        }
        foreach (['distance_meters' => 100000, 'duration_seconds' => 86400] as $field => $limit) {
            if (! is_int($path[$field] ?? null) || $path[$field] < 0 || $path[$field] > $limit) {
                throw new PickupException('PICKUP_ROUTE_INVALID', 503);
            }
        }
        $waypoints = $path['waypoints'] ?? null;
        $geometry = $path['geometry'] ?? null;
        $legs = $path['legs'] ?? null;
        if (! is_array($waypoints) || ! array_is_list($waypoints) || count($waypoints) !== count($points)
            || ! is_array($geometry) || ! array_is_list($geometry) || count($geometry) < 2 || count($geometry) > 20000
            || ! is_array($legs) || ! array_is_list($legs) || count($legs) !== count($points) - 1) {
            throw new PickupException('PICKUP_ROUTE_INVALID', 503);
        }
        $radius = $mode === 'foot' ? 50 : 250;
        foreach ($waypoints as $index => $waypoint) {
            $pair = $waypoint['location'] ?? null;
            if (! is_array($pair) || ! array_is_list($pair) || count($pair) !== 2) {
                throw new PickupException('PICKUP_ROUTE_INVALID', 503);
            }
            $location = ['lat' => $pair[1], 'lng' => $pair[0]];
            $this->point($location);
            $snap = $waypoint['distance_meters'] ?? null;
            if ((! is_int($snap) && ! is_float($snap)) || ! is_finite((float) $snap) || $snap < 0 || $snap > $radius || $this->distance($points[$index], $location) > $radius) {
                throw new PickupException('PICKUP_ROUTE_INVALID', 503);
            }
        }
        foreach ($geometry as $pair) {
            if (! is_array($pair) || ! array_is_list($pair) || count($pair) !== 2) {
                throw new PickupException('PICKUP_ROUTE_INVALID', 503);
            }
            $this->point(['lat' => $pair[1], 'lng' => $pair[0]]);
        }
        foreach ([[0, 0], [count($geometry)-1, count($waypoints)-1]] as [$g, $w]) {
            if ($this->distance(['lng' => $geometry[$g][0], 'lat' => $geometry[$g][1]], ['lng' => $waypoints[$w]['location'][0], 'lat' => $waypoints[$w]['location'][1]]) > 1) {
                throw new PickupException('PICKUP_ROUTE_INVALID', 503);
            }
        }
        foreach (['distance_meters', 'duration_seconds'] as $field) {
            $sum = 0;
            foreach ($legs as $leg) {
                if (! is_int($leg[$field] ?? null) || $leg[$field] < 0) {
                    throw new PickupException('PICKUP_ROUTE_INVALID', 503);
                }
                $sum += $leg[$field];
            }
            if (abs($sum - $path[$field]) > count($legs)) {
                throw new PickupException('PICKUP_ROUTE_INVALID', 503);
            }
        }
    }

    private function now(): float
    {
        return $this->clock ? ($this->clock)() : hrtime(true) / 1000000000;
    }

    private function vehicleIdentity(array $path, string $expected): void
    {
        if (($path['data_version'] ?? null) !== $expected) {
            throw new PickupException('ROUTE_GRAPH_MISMATCH', 503);
        }
    }

    private function detour(array $path, array $baseline): array
    {
        $distance = $path['distance_meters'] - $baseline['distance_meters'];
        $duration = $path['duration_seconds'] - $baseline['duration_seconds'];
        return ['distance_delta_meters' => $distance, 'duration_delta_seconds' => $duration,
            'added_distance_meters' => max(0, $distance), 'added_duration_seconds' => max(0, $duration)];
    }

    private function within(array $detour, int $meters, int $seconds): bool
    {
        return $detour['added_distance_meters'] <= $meters && $detour['added_duration_seconds'] <= $seconds;
    }

    private function candidates(array $geometry, array $origin, int $maxWalk): array
    {
        $near = [];
        $xScale = cos(deg2rad($origin['lat']));
        for ($i = 0; $i < count($geometry) - 1; $i++) {
            $a = $geometry[$i]; $b = $geometry[$i + 1];
            $dx = ($b[0] - $a[0]) * $xScale; $dy = $b[1] - $a[1];
            $denominator = $dx * $dx + $dy * $dy;
            $fraction = $denominator > 0 ? max(0, min(1, ((($origin['lng'] - $a[0]) * $xScale) * $dx + ($origin['lat'] - $a[1]) * $dy) / $denominator)) : 0;
            foreach ([$fraction, 0, 1] as $t) {
                $point = ['lng' => $a[0] + $t * ($b[0] - $a[0]), 'lat' => $a[1] + $t * ($b[1] - $a[1])];
                $distance = $this->distance($origin, $point);
                // This lower-bound shortlist allows both providers' maximum snap radii.
                if ($distance <= $maxWalk + 300) {
                    $key = sprintf('%.7f,%.7f', $point['lng'], $point['lat']);
                    $near[$key] ??= ['point' => $point, 'distance' => $distance, 'position' => $i + $t];
                }
            }
        }
        usort($near, static fn (array $a, array $b): int => [$a['distance'], $a['position']] <=> [$b['distance'], $b['position']]);
        $selected = [];
        foreach ($near as $candidate) {
            foreach ($selected as $other) {
                if ($this->distance($candidate['point'], $other['point']) < 25) {
                    continue 2;
                }
            }
            $selected[] = $candidate;
            if (count($selected) === self::MAX_CANDIDATES) {
                break;
            }
        }
        return $selected;
    }

    private function point(mixed $point): void
    {
        if (! is_array($point) || ! isset($point['lat'], $point['lng'])
            || (! is_int($point['lat']) && ! is_float($point['lat'])) || (! is_int($point['lng']) && ! is_float($point['lng']))
            || ! is_finite((float) $point['lat']) || ! is_finite((float) $point['lng']) || abs($point['lat']) > 90 || abs($point['lng']) > 180) {
            throw new PickupException('PICKUP_INPUT_INVALID');
        }
    }

    private function distance(array $a, array $b): float
    {
        $h = sin(deg2rad($b['lat'] - $a['lat']) / 2) ** 2 + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin(deg2rad($b['lng'] - $a['lng']) / 2) ** 2;
        return 6371000 * 2 * asin(sqrt(min(1, max(0, $h))));
    }
}
