<?php

namespace STS\Mobility\Journeys;

use InvalidArgumentException;
use JsonException;

/** Pure, conservative projection of a rider interval onto an immutable route. */
final class CanonicalRouteIntervalProjector
{
    private const TOLERANCE_METERS = 0.5;
    private const AMBIGUOUS_OFFSET_METERS = 2.0;
    private const MAX_VERTICES = 20000;
    private const MAX_ROUTE_METERS = 100000.0;
    private const MAX_DURATION_SECONDS = 86400.0;

    /**
     * @param array<string,mixed> $canonical Immutable publication path.
     * @param array<string,mixed> $candidate Candidate route/path for the rider.
     * @param array<string,mixed>|array<int,mixed> $pickup WGS84 {lat,lng} or [lng,lat].
     * @param array<string,mixed>|array<int,mixed> $dropoff WGS84 {lat,lng} or [lng,lat].
     * @return array<string,mixed>
     */
    public function project(array $canonical, array $candidate, array $pickup, array $dropoff, ?string $expectedHash = null): array
    {
        $canonicalPayload = $canonical;
        unset($canonicalPayload['canonical_route_hash'], $canonicalPayload['route_hash']);
        $canonicalHash = $this->jsonHash($canonicalPayload);
        if ($expectedHash !== null && (! is_string($expectedHash) || ! hash_equals($canonicalHash, $expectedHash))) throw new InvalidArgumentException('CANONICAL_ROUTE_HASH_MISMATCH');

        $base = $this->route($canonicalPayload);
        $other = $this->route($candidate);
        if ($base['provider'] !== $other['provider'] || $base['profile'] !== $other['profile'] || $base['data_version'] !== $other['data_version']) {
            throw new InvalidArgumentException('ROUTE_IDENTITY_MISMATCH');
        }
        if (abs($base['length'] - $other['length']) > self::TOLERANCE_METERS || ! $this->samePolyline($base, $other)) {
            throw new InvalidArgumentException('ROUTE_GEOMETRY_INCOMPATIBLE');
        }

        $from = $this->point($pickup);
        $to = $this->point($dropoff);
        if (count($other['waypoint_points']) >= 4 && ($this->distance($other['waypoint_points'][1], $from) > self::TOLERANCE_METERS || $this->distance($other['waypoint_points'][2], $to) > self::TOLERANCE_METERS)) throw new InvalidArgumentException('ROUTE_WAYPOINT_MISMATCH');
        $start = $this->projection($base, $from);
        $end = $this->projection($base, $to);
        if ($start['gap'] > self::TOLERANCE_METERS + 0.000001 || $end['gap'] > self::TOLERANCE_METERS + 0.000001) throw new InvalidArgumentException('ROUTE_PROJECTION_OFF_ROUTE');
        if ($start['offset'] >= $end['offset'] || $end['offset'] - $start['offset'] <= 0.0) {
            throw new InvalidArgumentException('ROUTE_INTERVAL_INVALID');
        }

        $fromMeters = (int) floor($start['offset']);
        $tillMeters = min((int) ceil($base['length']), (int) ceil($end['offset']));
        if ($tillMeters <= $fromMeters) {
            throw new InvalidArgumentException('ROUTE_INTERVAL_INVALID');
        }
        $fromSeconds = (int) floor($this->timeAt($base, $start['offset']));
        $tillSeconds = (int) ceil($this->timeAt($base, $end['offset']));

        $result = [
            'version' => 1,
            'canonical_route_hash' => $canonicalHash,
            'route_length_meters' => (int) ceil($base['length']),
            'from_meters' => $fromMeters,
            'till_meters' => $tillMeters,
            'from_seconds' => $fromSeconds,
            'till_seconds' => $tillSeconds,
            'pickup_gap_meters' => $this->gapMeters($start['gap']),
            'dropoff_gap_meters' => $this->gapMeters($end['gap']),
            'pickup_projected' => $start['point'],
            'dropoff_projected' => $end['point'],
        ];
        $result['interval_hash'] = self::hashInterval($result);
        return $result;
    }

    /** Build a publication-derived four-waypoint path without rerouting its geometry. */
    public function slice(array $canonical, array $pickup, array $dropoff, string $expectedHash): array
    {
        if (! hash_equals($this->jsonHash($canonical), $expectedHash)) throw new InvalidArgumentException('CANONICAL_ROUTE_HASH_MISMATCH');
        $route = $this->route($canonical);
        if (! is_int($canonical['distance_meters'] ?? null) || ! is_int($canonical['duration_seconds'] ?? null)) throw new InvalidArgumentException('ROUTE_METRICS_INVALID');
        foreach ($route['distances'] as $i => $distance) if (($distance == 0.0) !== ($route['durations'][$i] == 0.0)) throw new InvalidArgumentException('ROUTE_METRICS_INVALID');
        $from = $this->point($pickup); $to = $this->point($dropoff);
        $start = $this->projection($route, $from); $end = $this->projection($route, $to);
        if ($start['gap'] > self::TOLERANCE_METERS + 0.000001 || $end['gap'] > self::TOLERANCE_METERS + 0.000001 || $start['offset'] >= $end['offset']) throw new InvalidArgumentException('ROUTE_INTERVAL_INVALID');
        $distanceParts = $this->splitMetric($route, $start['offset'], $end['offset'], 'distances', (int) $canonical['distance_meters']);
        $durationParts = $this->splitMetric($route, $start['offset'], $end['offset'], 'durations', (int) $canonical['duration_seconds']);
        $sliced = $canonical;
        $sliced['waypoints'] = [
            ['location' => $route['waypoint_points'][0], 'distance_meters' => 0],
            ['location' => $start['point'], 'distance_meters' => $this->gapMeters($start['gap'])],
            ['location' => $end['point'], 'distance_meters' => $this->gapMeters($end['gap'])],
            ['location' => $route['waypoint_points'][array_key_last($route['waypoint_points'])], 'distance_meters' => 0],
        ];
        $sliced['legs'] = [];
        for ($i = 0; $i < 3; $i++) $sliced['legs'][] = ['distance_meters' => $distanceParts[$i], 'duration_seconds' => $durationParts[$i]];
        $interval = $this->project($canonical, $sliced, $start['point'], $end['point'], $expectedHash);
        return ['driver_path' => $sliced, 'interval' => $interval];
    }

    /** Three-waypoint pickup preview on the complete immutable publication. */
    public function pickupPath(array $canonical, array $pickup, string $expectedHash): array
    {
        $end = $canonical['waypoints'][array_key_last($canonical['waypoints'] ?? [])]['location'] ?? [];
        $path = $this->slice($canonical, $pickup, $end, $expectedHash)['driver_path'];
        $path['waypoints'] = [$path['waypoints'][0], $path['waypoints'][1], $path['waypoints'][3]];
        $path['legs'] = [$path['legs'][0], [
            'distance_meters' => $path['legs'][1]['distance_meters'] + $path['legs'][2]['distance_meters'],
            'duration_seconds' => $path['legs'][1]['duration_seconds'] + $path['legs'][2]['duration_seconds'],
        ]];
        return $path;
    }

    /**
     * Preserve an exact mutually agreed path when repeated road geometry makes
     * its waypoint occurrence ambiguous. Reserve the entire route; never guess
     * a smaller seat interval. Pickup timing keeps the immutable source legs.
     */
    public function reserveExact(array $canonical, array $candidate, string $expectedHash): array
    {
        if (! hash_equals($expectedHash, $this->jsonHash($canonical)) || ! hash_equals($expectedHash, $this->jsonHash($candidate))) {
            throw new InvalidArgumentException('CANONICAL_ROUTE_HASH_MISMATCH');
        }
        $route = $this->route($canonical, false);
        if (count($route['waypoint_points']) !== 4) throw new InvalidArgumentException('ROUTE_WAYPOINTS_INVALID');
        $pickup = $this->projectionRaw($route['coords'], $route['cumulative'], $route['waypoint_points'][1], false);
        $dropoff = $this->projectionRaw($route['coords'], $route['cumulative'], $route['waypoint_points'][2], false);
        $result = ['version' => 1, 'occupancy' => 'whole_route_exact_terms', 'canonical_route_hash' => $expectedHash,
            'route_length_meters' => (int) ceil($route['length']), 'from_meters' => 0, 'till_meters' => (int) ceil($route['length']),
            'from_seconds' => (int) floor($route['durations'][0]), 'till_seconds' => (int) ceil($route['durations'][0] + $route['durations'][1]),
            'pickup_gap_meters' => $this->gapMeters($pickup['gap']), 'dropoff_gap_meters' => $this->gapMeters($dropoff['gap']),
            'pickup_projected' => $pickup['point'], 'dropoff_projected' => $dropoff['point']];
        $result['interval_hash'] = self::hashInterval($result);
        return $result;
    }

    /** Hashes the stable interval fields, independent of JSON object key order. */
    public static function hashInterval(array $interval): string
    {
        unset($interval['interval_hash']);
        self::sortKeys($interval);
        try {
            return hash('sha256', json_encode($interval, JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
            throw new InvalidArgumentException('INTERVAL_HASH_INVALID', 0, $e);
        }
    }

    /** @return array<string,mixed> */
    private function route(array $path, bool $unambiguous = true): array
    {
        if (($path['provider'] ?? null) !== 'osrm' || ! is_string($path['profile'] ?? null) || $path['profile'] === '' || ! is_string($path['data_version'] ?? null) || $path['data_version'] === '') {
            throw new InvalidArgumentException('ROUTE_INVALID');
        }
        $geometry = $path['geometry'] ?? null;
        if (! is_array($geometry) || ! array_is_list($geometry) || count($geometry) < 2 || count($geometry) > self::MAX_VERTICES) {
            throw new InvalidArgumentException('ROUTE_GEOMETRY_INVALID');
        }
        $coords = array_map(fn ($pair) => $this->pair($pair), $geometry);
        $cumulative = [0.0];
        for ($i = 1; $i < count($coords); $i++) {
            $cumulative[] = $cumulative[$i - 1] + $this->distance($coords[$i - 1], $coords[$i]);
        }
        $length = $cumulative[array_key_last($cumulative)];
        if (! is_finite($length) || $length <= 0 || $length > self::MAX_ROUTE_METERS) {
            throw new InvalidArgumentException('ROUTE_GEOMETRY_INVALID');
        }
        $waypoints = $path['waypoints'] ?? null;
        $legs = $path['legs'] ?? null;
        if (! is_array($waypoints) || ! array_is_list($waypoints) || count($waypoints) < 2 || count($waypoints) > 8 || ! is_array($legs) || ! array_is_list($legs) || count($legs) !== count($waypoints) - 1) {
            throw new InvalidArgumentException('ROUTE_WAYPOINTS_INVALID');
        }
        $waypointOffsets = [];
        $waypointPoints = [];
        foreach ($waypoints as $index => $waypoint) {
            $location = $this->pair($waypoint['location'] ?? null);
            $waypointPoints[] = $location;
            if ($index === 0 && $this->distance($location, $coords[0]) > self::TOLERANCE_METERS + 0.000001 || $index === array_key_last($waypoints) && $this->distance($location, $coords[array_key_last($coords)]) > self::TOLERANCE_METERS + 0.000001) throw new InvalidArgumentException('ROUTE_ENDPOINT_MISMATCH');
            $projection = $this->projectionRaw($coords, $cumulative, $location, $unambiguous && $index > 0 && $index < array_key_last($waypoints));
            if ($projection['gap'] > self::TOLERANCE_METERS + 0.000001) throw new InvalidArgumentException('ROUTE_WAYPOINT_MISMATCH');
            $waypointOffsets[] = $projection['offset'];
        }
        $waypointOffsets[0] = 0.0; $waypointOffsets[array_key_last($waypointOffsets)] = $length;
        for ($i = 1; $i < count($waypointOffsets); $i++) {
            if ($unambiguous && $waypointOffsets[$i] + 0.000001 < $waypointOffsets[$i - 1]) throw new InvalidArgumentException('ROUTE_WAYPOINT_ORDER_INVALID');
        }
        $durations = [];
        $distances = [];
        foreach ($legs as $index => $leg) {
            $distance = $leg['distance_meters'] ?? null;
            if ((! is_int($distance) && ! is_float($distance)) || ! is_finite((float) $distance) || $distance < 0 || $distance > self::MAX_ROUTE_METERS) throw new InvalidArgumentException('ROUTE_METRICS_INVALID');
            $distances[] = (float) $distance;
            $duration = $leg['duration_seconds'] ?? null;
            if (! is_int($duration) && ! is_float($duration) || ! is_finite((float) $duration) || $duration < 0 || $duration > self::MAX_DURATION_SECONDS) throw new InvalidArgumentException('ROUTE_METRICS_INVALID');
            $coincident = $this->distance($waypointPoints[$index], $waypointPoints[$index + 1]) <= 1.0;
            if ((! $coincident && $duration == 0.0) || ($coincident && (($distance == 0.0) !== ($duration == 0.0)))) throw new InvalidArgumentException('ROUTE_METRICS_INVALID');
            $durations[] = (float) $duration;
        }
        $durationTotal = array_sum($durations);
        if ($durationTotal < 0 || $durationTotal > self::MAX_DURATION_SECONDS) throw new InvalidArgumentException('ROUTE_METRICS_INVALID');
        foreach (['distance_meters' => self::MAX_ROUTE_METERS, 'duration_seconds' => self::MAX_DURATION_SECONDS] as $key => $maximum) {
            if (array_key_exists($key, $path) && (! is_int($path[$key]) && ! is_float($path[$key]) || ! is_finite((float) $path[$key]) || $path[$key] < 0 || $path[$key] > $maximum)) throw new InvalidArgumentException('ROUTE_METRICS_INVALID');
        }
        if (! array_key_exists('distance_meters', $path) || ! array_key_exists('duration_seconds', $path)) throw new InvalidArgumentException('ROUTE_METRICS_INVALID');
        if ((! is_int($path['distance_meters']) && ! is_float($path['distance_meters'])) || ! is_finite((float) $path['distance_meters']) || $path['distance_meters'] < 0 || $path['distance_meters'] > self::MAX_ROUTE_METERS || (! is_int($path['duration_seconds']) && ! is_float($path['duration_seconds'])) || ! is_finite((float) $path['duration_seconds']) || $path['duration_seconds'] < 0 || $path['duration_seconds'] > self::MAX_DURATION_SECONDS || abs(array_sum($distances) - (float) $path['distance_meters']) > max(1, count($legs)) || abs($durationTotal - (float) $path['duration_seconds']) > max(1, count($legs))) throw new InvalidArgumentException('ROUTE_METRICS_INVALID');
        return ['provider' => $path['provider'], 'profile' => $path['profile'], 'data_version' => $path['data_version'], 'coords' => $coords, 'cumulative' => $cumulative, 'length' => $length, 'waypoint_offsets' => $waypointOffsets, 'durations' => $durations, 'distances' => $distances, 'waypoint_points' => $waypointPoints];
    }

    private function splitMetric(array $route, float $from, float $to, string $field, int $declaredTotal): array
    {
        $bounds = [0.0, $from, $to, $route['length']]; $values = [];
        for ($part = 0; $part < 3; $part++) $values[] = $this->metricBetween($route, $bounds[$part], $bounds[$part + 1], $field);
        $result = array_map(static fn (float $value): int => (int) floor($value), $values); $remaining = $declaredTotal - array_sum($result);
        $order = [0, 1, 2]; usort($order, static fn (int $a, int $b): int => (($values[$b] - floor($values[$b])) <=> ($values[$a] - floor($values[$a]))) ?: ($a <=> $b));
        if ($remaining >= 0) for ($i = 0; $i < $remaining; $i++) $result[$order[$i % 3]]++;
        else { rsort($order); for ($i = 0; $i < -$remaining; $i++) { $index = $order[$i % 3]; if ($result[$index] > 0) $result[$index]--; } }
        return $result;
    }

    private function metricBetween(array $route, float $from, float $to, string $field): float
    {
        $total = 0.0;
        for ($i = 1; $i < count($route['waypoint_offsets']); $i++) {
            $a = $route['waypoint_offsets'][$i - 1]; $b = $route['waypoint_offsets'][$i]; $span = max(0.0, $b - $a); if ($span <= 0) continue;
            $overlap = max(0.0, min($to, $b) - max($from, $a)); if ($overlap > 0) $total += $route[$field][$i - 1] * ($overlap / $span);
        }
        return $total;
    }

    private function samePolyline(array $a, array $b): bool
    {
        $marks = array_merge($a['cumulative'], $b['cumulative']);
        sort($marks, SORT_NUMERIC);
        $unique = [];
        foreach ($marks as $mark) if ($unique === [] || abs($mark - $unique[array_key_last($unique)]) > 0.000001) $unique[] = $mark;
        foreach ($unique as $distance) if ($this->distance($this->at($a, min($distance, $a['length'])), $this->at($b, min($distance, $b['length']))) > self::TOLERANCE_METERS + 0.000001) return false;
        return true;
    }

    private function projection(array $route, array $point): array
    {
        return $this->projectionRaw($route['coords'], $route['cumulative'], $point, true);
    }

    private function projectionRaw(array $coords, array $cumulative, array $point, bool $ambiguous): array
    {
        $rows = [];
        $scale = cos(deg2rad($point[1]));
        for ($i = 0; $i < count($coords) - 1; $i++) {
            $a = $coords[$i]; $b = $coords[$i + 1];
            $dx = ($b[0] - $a[0]) * $scale; $dy = $b[1] - $a[1]; $size = $dx * $dx + $dy * $dy;
            $t = $size > 0 ? max(0.0, min(1.0, (($point[0] - $a[0]) * $scale * $dx + ($point[1] - $a[1]) * $dy) / $size)) : 0.0;
            $projected = [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t];
            $rows[] = ['offset' => $cumulative[$i] + ($cumulative[$i + 1] - $cumulative[$i]) * $t, 'point' => $projected, 'gap' => $this->distance($point, $projected), 'segment' => $i];
        }
        usort($rows, static fn ($x, $y) => [$x['gap'], $x['offset'], $x['segment']] <=> [$y['gap'], $y['offset'], $y['segment']]);
        if ($ambiguous) {
            $best = $rows[0];
            foreach ($rows as $row) if ($row['gap'] <= $best['gap'] + self::TOLERANCE_METERS && abs($row['offset'] - $best['offset']) > self::AMBIGUOUS_OFFSET_METERS) throw new InvalidArgumentException('ROUTE_PROJECTION_AMBIGUOUS');
        }
        return $rows[0];
    }

    private function at(array $route, float $distance): array
    {
        $lo = 1; $hi = count($route['cumulative']) - 1;
        while ($lo < $hi) { $mid = intdiv($lo + $hi, 2); if ($route['cumulative'][$mid] < $distance) $lo = $mid + 1; else $hi = $mid; }
        $i = $lo;
        $i = min($i, count($route['coords']) - 1); $span = $route['cumulative'][$i] - $route['cumulative'][$i - 1];
        $t = $span > 0 ? max(0.0, min(1.0, ($distance - $route['cumulative'][$i - 1]) / $span)) : 0.0;
        return [$route['coords'][$i - 1][0] + ($route['coords'][$i][0] - $route['coords'][$i - 1][0]) * $t, $route['coords'][$i - 1][1] + ($route['coords'][$i][1] - $route['coords'][$i - 1][1]) * $t];
    }

    private function timeAt(array $route, float $offset): float
    {
        $total = 0.0;
        for ($i = 1; $i < count($route['waypoint_offsets']); $i++) {
            $start = $route['waypoint_offsets'][$i - 1]; $end = $route['waypoint_offsets'][$i]; $duration = $route['durations'][$i - 1];
            if ($offset >= $end) { $total += $duration; continue; }
            if ($offset > $start && $end > $start) $total += $duration * (($offset - $start) / ($end - $start));
            break;
        }
        return $total;
    }

    private function pair(mixed $pair): array
    {
        if (! is_array($pair) || ! array_is_list($pair) || count($pair) !== 2 || ! is_numeric($pair[0]) || ! is_numeric($pair[1]) || ! is_finite((float) $pair[0]) || ! is_finite((float) $pair[1]) || (float) $pair[0] < 77.2 || (float) $pair[0] > 77.95 || (float) $pair[1] < 12.65 || (float) $pair[1] > 13.35) throw new InvalidArgumentException('ROUTE_POINT_INVALID');
        return [(float) $pair[0], (float) $pair[1]];
    }

    private function point(array $point): array
    {
        if (! array_is_list($point) && isset($point['lng'], $point['lat'])) return $this->pair([$point['lng'], $point['lat']]);
        return $this->pair($point);
    }

    private function distance(array $a, array $b): float
    {
        $h = sin(deg2rad($b[1] - $a[1]) / 2) ** 2 + cos(deg2rad($a[1])) * cos(deg2rad($b[1])) * sin(deg2rad($b[0] - $a[0]) / 2) ** 2;
        return 6371000 * 2 * asin(sqrt(min(1.0, max(0.0, $h))));
    }

    private function gapMeters(float $gap): int
    {
        return (int) ceil(max(0.0, $gap - 0.000001));
    }

    private function jsonHash(array $value): string
    {
        try { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)); } catch (JsonException $e) { throw new InvalidArgumentException('CANONICAL_ROUTE_INVALID', 0, $e); }
    }

    private static function sortKeys(array &$value): void
    {
        foreach ($value as &$item) if (is_array($item)) self::sortKeys($item);
        unset($item); if (! array_is_list($value)) ksort($value, SORT_STRING);
    }
}
