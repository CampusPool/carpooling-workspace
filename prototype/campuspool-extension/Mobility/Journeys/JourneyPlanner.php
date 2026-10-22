<?php

namespace STS\Mobility\Journeys;

use STS\Mobility\Pickup\PathProvider;
use STS\Mobility\Pickup\PickupException;
use STS\Mobility\Pickup\PickupPolicy;
use STS\Mobility\Routing\RoutingException;

/** Server road routing only. Proposals carry no seat, consent or booking authority. */
final class JourneyPlanner
{
    private const MAX_PAIRS = 4;
    private const DEADLINE_SECONDS = 25;

    public function __construct(private readonly PathProvider $paths, private readonly ?\Closure $clock = null) {}

    public function plan(array $baseline, array $origin, array $destination, string $mode, string $choice, int $maxWalk, int $maxDetourMeters, int $maxDetourSeconds): array
    {
        PickupPolicy::walkPreference($maxWalk, 422);
        if (! in_array($mode, ['car', 'bike'], true) || ! in_array($choice, ['DIRECT_RIDER_PICKUP', 'ON_ROUTE_MEETING_POINT'], true)
            || $maxDetourMeters < 0 || $maxDetourMeters > 100000 || $maxDetourSeconds < 0 || $maxDetourSeconds > 86400) throw new PickupException('JOURNEY_INPUT_INVALID');
        $this->point($origin); $this->point($destination);
        $driverPoints = $baseline['points'] ?? [];
        if (count($driverPoints) !== 2) throw new PickupException('JOURNEY_ROUTE_INVALID');
        $this->validate($baseline, $driverPoints, $mode);
        if ($choice === 'ON_ROUTE_MEETING_POINT') return $this->meetingPlan($baseline, $origin, $destination, $mode, $maxWalk);
        $geometry = $baseline['geometry'];
        $starts = $this->candidates($geometry, $origin);
        $ends = $this->candidates($geometry, $destination);
        $pairs = [];
        foreach ($starts as $start) foreach ($ends as $end) {
            if ($start['position'] < $end['position']) $pairs[] = [$start, $end];
        }
        usort($pairs, static fn ($a, $b) => [$a[0]['distance'] + $a[1]['distance'], $a[0]['position'], $a[1]['position']] <=> [$b[0]['distance'] + $b[1]['distance'], $b[0]['position'], $b[1]['position']]);
        // Direct pickup still requires an ordered compatible portion of the driver's route.
        if ($pairs === []) return $this->unavailable('ROUTE_DIRECTION_INCOMPATIBLE');
        if ($choice === 'DIRECT_RIDER_PICKUP') $pairs = [$pairs[0]];
        $deadline = $this->now() + self::DEADLINE_SECONDS;
        $options = []; $evaluated = 0;
        foreach (array_slice($pairs, 0, self::MAX_PAIRS) as [$start, $end]) {
            $evaluated++;
            $pickup = $choice === 'DIRECT_RIDER_PICKUP' ? $origin : $start['point'];
            $dropoff = $choice === 'DIRECT_RIDER_PICKUP' ? $destination : $end['point'];
            try {
                $vehicle = $this->path([$driverPoints[0], $pickup, $dropoff, $driverPoints[1]], $mode, $deadline);
                if ($vehicle['data_version'] !== $baseline['data_version']) throw new PickupException('PICKUP_GRAPH_MISMATCH', 503);
                $detour = ['added_distance_meters' => max(0, $vehicle['distance_meters'] - $baseline['distance_meters']), 'added_duration_seconds' => max(0, $vehicle['duration_seconds'] - $baseline['duration_seconds'])];
                if ($detour['added_distance_meters'] > $maxDetourMeters || $detour['added_duration_seconds'] > $maxDetourSeconds) continue;
                $pickup = $this->fromPair($vehicle['waypoints'][1]['location']);
                $dropoff = $this->fromPair($vehicle['waypoints'][2]['location']);
                $access = $this->walking($origin, $pickup, $deadline);
                $egress = $this->walking($dropoff, $destination, $deadline);
                $total = $access['total_meters'] + $egress['total_meters'];
                if ($total > $maxWalk) continue;
                $ride = $vehicle['legs'][1];
                if ($ride['distance_meters'] < 1 || $ride['duration_seconds'] < 1) continue;
                $options[] = ['available' => true, 'schema' => 'private-journey-proposal-v2', 'choice' => $choice,
                    'rider_origin' => $origin, 'rider_destination' => $destination,
                    'pickup' => $pickup, 'dropoff' => $dropoff, 'pickup_route_position' => $start['position'], 'dropoff_route_position' => $end['position'],
                    'driver_path' => $vehicle, 'access' => $access, 'riding' => $ride, 'egress' => $egress,
                    'total_walking_meters' => $total, 'max_walk_meters' => $maxWalk, 'detour' => $detour,
                    'pricing' => DemoFarePolicy::quote($mode, $choice, $ride['distance_meters']),
                    'access_verified' => false, 'requires_access_acknowledgement' => true, 'booking_enabled' => false];
            } catch (RoutingException $error) {
                if ($error->machineCode === 'NO_ROUTE') continue;
                throw new PickupException($error->machineCode, 503);
            }
        }
        usort($options, static fn ($a, $b) => [$a['total_walking_meters'], $a['detour']['added_duration_seconds'], $a['pricing']['total_fare_minor']] <=> [$b['total_walking_meters'], $b['detour']['added_duration_seconds'], $b['pricing']['total_fare_minor']]);
        return ($options[0] ?? $this->unavailable('NO_COMPATIBLE_JOURNEY_WITHIN_LIMITS')) + ['evaluated_candidates' => $evaluated, 'candidate_limit' => self::MAX_PAIRS, 'ranking' => 'least_total_walking_among_evaluated_ordered_candidates'];
    }

    /** Foot-network rendezvous on the unchanged ordered driver road path. */
    private function meetingPlan(array $baseline, array $origin, array $destination, string $mode, int $maxWalk): array
    {
        $deadline = $this->now() + self::DEADLINE_SECONDS;
        $evaluated = 0;
        $walkOptions = function (array $point, bool $access) use ($baseline, $deadline, $maxWalk, &$evaluated): array {
            $options = [];
            foreach ($this->candidates($baseline['geometry'], $point, 48) as $candidate) {
                if ($candidate['distance'] > $maxWalk) continue;
                $evaluated++;
                try { $walk = $this->walking($access ? $point : $candidate['point'], $access ? $candidate['point'] : $point, $deadline); }
                catch (RoutingException $error) { if ($error->machineCode === 'NO_ROUTE') continue; throw $error; }
                if ($walk['total_meters'] <= $maxWalk) $options[] = $candidate + ['walk' => $walk];
            }
            return $options;
        };
        $starts = $walkOptions($origin, true); $ends = $walkOptions($destination, false); $pairs = [];
        foreach ($starts as $start) foreach ($ends as $end) {
            $total = $start['walk']['total_meters'] + $end['walk']['total_meters'];
            if ($start['position'] < $end['position'] && $total <= $maxWalk) $pairs[] = [$start, $end, $total];
        }
        usort($pairs, static fn ($a, $b) => [$a[2], $a[0]['position'], $a[1]['position']] <=> [$b[2], $b[0]['position'], $b[1]['position']]);
        foreach (array_slice($pairs, 0, 16) as [$start, $end, $total]) {
            if ($this->now() >= $deadline) throw new PickupException('JOURNEY_ROUTING_DEADLINE', 503);
            try { $vehicle = (new CanonicalRouteIntervalProjector())->slice($baseline, $start['point'], $end['point'], hash('sha256', json_encode($baseline, JSON_THROW_ON_ERROR)))['driver_path']; }
            catch (\InvalidArgumentException) { continue; }
            $ride = $vehicle['legs'][1];
            if ($ride['distance_meters'] < 1 || $ride['duration_seconds'] < 1) continue;
            return ['available' => true, 'schema' => 'private-journey-proposal-v2', 'choice' => 'ON_ROUTE_MEETING_POINT',
                'rider_origin' => $origin, 'rider_destination' => $destination,
                'pickup' => $this->fromPair($vehicle['waypoints'][1]['location']), 'dropoff' => $this->fromPair($vehicle['waypoints'][2]['location']),
                'pickup_route_position' => $start['position'], 'dropoff_route_position' => $end['position'],
                'driver_path' => $vehicle, 'access' => $start['walk'], 'riding' => $ride, 'egress' => $end['walk'],
                'total_walking_meters' => $total, 'max_walk_meters' => $maxWalk,
                'detour' => ['added_distance_meters' => 0, 'added_duration_seconds' => 0],
                'pricing' => DemoFarePolicy::quote($mode, 'ON_ROUTE_MEETING_POINT', $ride['distance_meters']),
                'driver_route_unchanged' => true, 'meeting_candidate_limit_per_endpoint' => 48, 'evaluated_candidates' => $evaluated,
                'ranking' => 'least_actual_total_walking_among_evaluated_ordered_route_points',
                'access_verified' => false, 'requires_access_acknowledgement' => true, 'booking_enabled' => false];
        }
        return $this->unavailable('NO_COMPATIBLE_JOURNEY_WITHIN_LIMITS') + ['evaluated_candidates' => $evaluated, 'meeting_candidate_limit_per_endpoint' => 48];
    }

    /** Quotes a new rider leg without replacing any previously agreed detour. */
    public function planPublished(array $canonical, array $origin, array $destination, string $mode, string $choice, int $maxWalk, string $routeHash): array
    {
        PickupPolicy::walkPreference($maxWalk, 422);
        if (! in_array($mode, ['car', 'bike'], true) || ! in_array($choice, ['DIRECT_RIDER_PICKUP', 'ON_ROUTE_MEETING_POINT'], true)) throw new PickupException('JOURNEY_INPUT_INVALID');
        $this->point($origin); $this->point($destination);
        unset($canonical['points']);
        $points = array_map(fn ($w) => $this->fromPair($w['location']), $canonical['waypoints'] ?? []);
        if (count($points) !== 4) throw new PickupException('JOURNEY_ROUTE_INVALID');
        $this->validate($canonical, $points, $mode);
        $pairs = [];
        foreach ($this->candidates($canonical['geometry'], $origin) as $start) foreach ($this->candidates($canonical['geometry'], $destination) as $end) {
            if ($start['position'] < $end['position']) $pairs[] = [$start, $end];
        }
        usort($pairs, static fn ($a, $b) => [$a[0]['distance'] + $a[1]['distance'], $a[0]['position'], $a[1]['position']] <=> [$b[0]['distance'] + $b[1]['distance'], $b[0]['position'], $b[1]['position']]);
        if ($choice === 'DIRECT_RIDER_PICKUP') $pairs = array_slice($pairs, 0, 1);
        $deadline = $this->now() + self::DEADLINE_SECONDS; $options = []; $evaluated = 0;
        foreach (array_slice($pairs, 0, self::MAX_PAIRS) as [$start, $end]) {
            $evaluated++;
            try {
                $pickup = $choice === 'DIRECT_RIDER_PICKUP' ? $origin : $start['point'];
                $dropoff = $choice === 'DIRECT_RIDER_PICKUP' ? $destination : $end['point'];
                $vehicle = (new CanonicalRouteIntervalProjector())->slice($canonical, $pickup, $dropoff, $routeHash)['driver_path'];
                $pickup = $this->fromPair($vehicle['waypoints'][1]['location']);
                $dropoff = $this->fromPair($vehicle['waypoints'][2]['location']);
                $access = $this->walking($origin, $pickup, $deadline); $egress = $this->walking($dropoff, $destination, $deadline);
                $total = $access['total_meters'] + $egress['total_meters']; $ride = $vehicle['legs'][1];
                if ($total > $maxWalk || $ride['distance_meters'] < 1 || $ride['duration_seconds'] < 1) continue;
                $options[] = ['available' => true, 'schema' => 'private-journey-proposal-v2', 'choice' => $choice,
                    'rider_origin' => $origin, 'rider_destination' => $destination, 'pickup' => $pickup, 'dropoff' => $dropoff,
                    'pickup_route_position' => $start['position'], 'dropoff_route_position' => $end['position'],
                    'driver_path' => $vehicle, 'access' => $access, 'riding' => $ride, 'egress' => $egress,
                    'total_walking_meters' => $total, 'max_walk_meters' => $maxWalk,
                    'detour' => ['added_distance_meters' => 0, 'added_duration_seconds' => 0],
                    'pricing' => DemoFarePolicy::quote($mode, $choice, $ride['distance_meters']),
                    'access_verified' => false, 'requires_access_acknowledgement' => true, 'booking_enabled' => false];
            } catch (\InvalidArgumentException) {
                // No ambiguous interval or new off-route detour is admitted.
                continue;
            } catch (RoutingException $error) {
                if ($error->machineCode === 'NO_ROUTE') continue;
                throw new PickupException($error->machineCode, 503);
            }
        }
        usort($options, static fn ($a, $b) => [$a['total_walking_meters'], $a['pricing']['total_fare_minor']] <=> [$b['total_walking_meters'], $b['pricing']['total_fare_minor']]);
        return ($options[0] ?? $this->unavailable('PUBLICATION_ROUTE_INCOMPATIBLE')) + ['evaluated_candidates' => $evaluated, 'candidate_limit' => self::MAX_PAIRS, 'ranking' => 'least_total_walking_among_evaluated_ordered_candidates'];
    }

    private function walking(array $from, array $to, float $deadline): array
    {
        $path = $this->path([$from, $to], 'foot', $deadline);
        $first = $this->fromPair($path['waypoints'][0]['location']); $last = $this->fromPair($path['waypoints'][1]['location']);
        // Count each endpoint connection conservatively, including handoff. The
        // handoff metric below is a disclosed subset of these gaps, not another path.
        $gaps = (int) ceil(max($path['waypoints'][0]['distance_meters'], self::distance($from, $first)))
            + (int) ceil(max($path['waypoints'][1]['distance_meters'], self::distance($last, $to)));
        $handoff = (int) ceil(self::distance($from, $first) + self::distance($last, $to));
        return ['path' => $path, 'walking_distance_meters' => $path['distance_meters'], 'duration_seconds' => $path['duration_seconds'],
            'unmapped_gap_meters' => $gaps, 'handoff_gap_meters' => $handoff, 'handoff_included_in_unmapped_gap' => true, 'total_meters' => $path['distance_meters'] + $gaps];
    }

    private function path(array $points, string $mode, float $deadline): array
    {
        if ($this->now() >= $deadline) throw new PickupException('JOURNEY_ROUTING_DEADLINE', 503);
        $path = $this->paths->path($points, $mode, min(5, max(0.001, $deadline - $this->now())));
        $this->validate($path, $points, $mode);
        if ($this->now() >= $deadline) throw new PickupException('JOURNEY_ROUTING_DEADLINE', 503);
        return $path;
    }

    private function validate(array $path, array $points, string $mode): void
    {
        PickupPolicy::graph($mode, (string) ($path['data_version'] ?? ''));
        if (($path['provider'] ?? '') !== 'osrm' || ! is_string($path['profile'] ?? null) || $path['profile'] === ''
            || ! is_array($path['waypoints'] ?? null) || ! array_is_list($path['waypoints']) || count($path['waypoints']) !== count($points)
            || ! is_array($path['legs'] ?? null) || ! array_is_list($path['legs']) || count($path['legs']) !== count($points) - 1
            || ! is_array($path['geometry'] ?? null) || ! array_is_list($path['geometry']) || count($path['geometry']) < 2 || count($path['geometry']) > 20000) throw new PickupException('JOURNEY_ROUTE_INVALID', 503);
        foreach ($points as $index => $point) {
            $this->point($point); $snap = $path['waypoints'][$index];
            $actual = $this->fromPair($snap['location'] ?? null); $radius = $mode === 'foot' ? 50 : 250;
            if (! is_numeric($snap['distance_meters'] ?? null) || ! is_finite((float) $snap['distance_meters']) || $snap['distance_meters'] < 0 || $snap['distance_meters'] > $radius || self::distance($point, $actual) > $radius) throw new PickupException('JOURNEY_ROUTE_INVALID', 503);
        }
        foreach ($path['geometry'] as $pair) $this->fromPair($pair);
        if (self::distance($this->fromPair($path['geometry'][0]), $this->fromPair($path['waypoints'][0]['location'])) > 1
            || self::distance($this->fromPair($path['geometry'][count($path['geometry']) - 1]), $this->fromPair($path['waypoints'][count($points) - 1]['location'])) > 1) throw new PickupException('JOURNEY_ROUTE_INVALID', 503);
        foreach (['distance_meters' => 100000, 'duration_seconds' => 86400] as $metric => $max) {
            if (! is_int($path[$metric] ?? null) || $path[$metric] < 0 || $path[$metric] > $max) throw new PickupException('JOURNEY_ROUTE_INVALID', 503);
            $sum = 0;
            foreach ($path['legs'] as $leg) {
                if (! is_int($leg[$metric] ?? null) || $leg[$metric] < 0) throw new PickupException('JOURNEY_ROUTE_INVALID', 503);
                $sum += $leg[$metric];
            }
            if (abs($sum - $path[$metric]) > count($points)) throw new PickupException('JOURNEY_ROUTE_INVALID', 503);
        }
    }

    private function candidates(array $geometry, array $point, int $limit = self::MAX_PAIRS): array
    {
        $rows = []; $scale = cos(deg2rad($point['lat']));
        for ($i = 0; $i < count($geometry) - 1; $i++) {
            $a = $geometry[$i]; $b = $geometry[$i + 1]; $dx = ($b[0] - $a[0]) * $scale; $dy = $b[1] - $a[1]; $size = $dx * $dx + $dy * $dy;
            $t = $size > 0 ? max(0, min(1, (($point['lng'] - $a[0]) * $scale * $dx + ($point['lat'] - $a[1]) * $dy) / $size)) : 0;
            $candidate = ['lng' => $a[0] + ($b[0] - $a[0]) * $t, 'lat' => $a[1] + ($b[1] - $a[1]) * $t];
            $rows[] = ['point' => $candidate, 'distance' => self::distance($point, $candidate), 'position' => $i + $t];
            // Road vertices add nearby junctions/crossings to the perpendicular
            // shortlist. Actual foot paths, including gaps, decide feasibility.
            $vertex = ['lng' => $a[0], 'lat' => $a[1]];
            if ($limit > self::MAX_PAIRS) $rows[] = ['point' => $vertex, 'distance' => self::distance($point, $vertex), 'position' => $i];
        }
        usort($rows, static fn ($a, $b) => [$a['distance'], $a['position']] <=> [$b['distance'], $b['position']]);
        $result = [];
        foreach ($rows as $row) {
            foreach ($result as $other) if (self::distance($row['point'], $other['point']) < 25) continue 2;
            $result[] = $row; if (count($result) >= $limit) break;
        }
        return $result;
    }

    private function point(mixed $point): void
    {
        if (! is_array($point) || ! isset($point['lat'], $point['lng']) || ! is_numeric($point['lat']) || ! is_numeric($point['lng'])
            || ! is_finite((float) $point['lat']) || ! is_finite((float) $point['lng']) || $point['lat'] < 12.65 || $point['lat'] > 13.35 || $point['lng'] < 77.2 || $point['lng'] > 77.95) throw new PickupException('JOURNEY_LOCATION_INVALID');
    }
    private function fromPair(mixed $pair): array
    {
        if (! is_array($pair) || ! array_is_list($pair) || count($pair) !== 2) throw new PickupException('JOURNEY_ROUTE_INVALID', 503);
        $point = ['lng' => $pair[0], 'lat' => $pair[1]]; $this->point($point); return $point;
    }
    private static function distance(array $a, array $b): float
    {
        $h = sin(deg2rad($b['lat'] - $a['lat']) / 2) ** 2 + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin(deg2rad($b['lng'] - $a['lng']) / 2) ** 2;
        return 6371000 * 2 * asin(sqrt(min(1, max(0, $h))));
    }
    private function now(): float { return $this->clock ? ($this->clock)() : hrtime(true) / 1000000000; }
    private function unavailable(string $code): array { return ['available' => false, 'code' => $code, 'booking_enabled' => false]; }
}
