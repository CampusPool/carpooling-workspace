<?php

namespace Tests\Feature\Mobility;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use STS\Mobility\Journeys\CanonicalRouteIntervalProjector;

final class MobilityCanonicalRouteIntervalProjectorTest extends TestCase
{
    private static function version(): string { return 'car|crop_sha256='.str_repeat('a', 64).'|profile_sha256='.str_repeat('b', 64); }

    public function test_exact_path_and_subdivided_path_project_the_same_interval(): void
    {
        $canonical = $this->path([[77.50, 12.90], [77.51, 12.90], [77.52, 12.90], [77.53, 12.90]], [1, 1, 1]);
        $candidate = $this->path([[77.50, 12.90], [77.51, 12.90], [77.515, 12.90], [77.52, 12.90], [77.53, 12.90]], [1, 1, 1, 1]);
        $candidate['waypoints'] = array_map(static fn (array $p): array => ['location' => $p, 'distance_meters' => 0], [[77.50, 12.90], [77.505, 12.90], [77.525, 12.90], [77.53, 12.90]]);
        $candidate['legs'] = [['distance_meters' => 1, 'duration_seconds' => 1], ['distance_meters' => 1, 'duration_seconds' => 1], ['distance_meters' => 1, 'duration_seconds' => 1]];
        $result = (new CanonicalRouteIntervalProjector())->project($canonical, $candidate, ['lat' => 12.90, 'lng' => 77.505], ['lat' => 12.90, 'lng' => 77.525]);
        self::assertSame(1, $result['version']); self::assertLessThan($result['route_length_meters'], $result['till_meters']); self::assertGreaterThan(0, $result['till_meters'] - $result['from_meters']);
        self::assertSame($result['interval_hash'], CanonicalRouteIntervalProjector::hashInterval($result));
        self::assertSame($result['route_length_meters'], (int) ceil(3 * $this->distance([77.50, 12.90], [77.51, 12.90])));
    }

    public function test_different_interior_waypoints_use_canonical_timing_and_gaps(): void
    {
        $canonical = $this->path([[77.50, 12.90], [77.51, 12.90], [77.52, 12.90], [77.53, 12.90]], [10, 10, 10]);
        $candidate = $this->path([[77.50, 12.90], [77.515, 12.90], [77.525, 12.90], [77.53, 12.90]], [1, 1, 1]);
        $result = (new CanonicalRouteIntervalProjector())->project($canonical, $candidate, ['lat' => 12.900001, 'lng' => 77.515], ['lat' => 12.899999, 'lng' => 77.525]);
        self::assertGreaterThanOrEqual(14, $result['from_seconds']); self::assertLessThanOrEqual(15, $result['from_seconds']); self::assertGreaterThanOrEqual(25, $result['till_seconds']); self::assertLessThanOrEqual(26, $result['till_seconds']);
        self::assertGreaterThanOrEqual(1, $result['pickup_gap_meters']); self::assertGreaterThanOrEqual(1, $result['dropoff_gap_meters']);
    }

    public function test_slight_noise_within_half_meter_is_accepted(): void
    {
        $canonical = $this->path([[77.50, 12.90], [77.51, 12.90], [77.52, 12.90]], [10, 10]);
        $candidate = $this->path([[77.50, 12.90], [77.51, 12.900003], [77.52, 12.90]], [1, 1]);
        $result = (new CanonicalRouteIntervalProjector())->project($canonical, $candidate, [77.505, 12.90], [77.515, 12.90]);
        self::assertSame($canonical['data_version'], $candidate['data_version']); self::assertSame(0, $result['pickup_gap_meters']);
    }

    public function test_offroute_and_reverse_paths_are_rejected(): void
    {
        $canonical = $this->path([[77.50, 12.90], [77.51, 12.90], [77.52, 12.90]], [1, 1]);
        $offroute = $this->path([[77.50, 12.90], [77.51, 12.91], [77.52, 12.90]], [1, 1]);
        $reverse = $this->path([[77.52, 12.90], [77.51, 12.90], [77.50, 12.90]], [1, 1]);
        try { (new CanonicalRouteIntervalProjector())->project($canonical, $offroute, [77.505, 12.90], [77.515, 12.90]); self::fail('offroute path accepted'); } catch (InvalidArgumentException $e) { self::assertSame('ROUTE_GEOMETRY_INCOMPATIBLE', $e->getMessage()); }
        try { (new CanonicalRouteIntervalProjector())->project($canonical, $reverse, [77.505, 12.90], [77.515, 12.90]); self::fail('reverse path accepted'); } catch (InvalidArgumentException) {}
    }

    public function test_identity_hash_and_malformed_metadata_fail_closed(): void
    {
        $canonical = $this->path([[77.50, 12.90], [77.51, 12.90]], [10]); $candidate = $canonical;
        $candidate['profile'] = 'other';
        try { (new CanonicalRouteIntervalProjector())->project($canonical, $candidate, [77.50, 12.90], [77.505, 12.90]); self::fail('profile mismatch accepted'); } catch (InvalidArgumentException $e) { self::assertSame('ROUTE_IDENTITY_MISMATCH', $e->getMessage()); }
        try { (new CanonicalRouteIntervalProjector())->project($canonical, $candidate, [77.50, 12.90], [77.505, 12.90], str_repeat('0', 64)); self::fail('bad hash accepted'); } catch (InvalidArgumentException $e) { self::assertSame('CANONICAL_ROUTE_HASH_MISMATCH', $e->getMessage()); }
        $expected = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
        $result = (new CanonicalRouteIntervalProjector())->project($canonical, $canonical, [77.50, 12.90], [77.505, 12.90], $expected);
        self::assertSame($result['interval_hash'], CanonicalRouteIntervalProjector::hashInterval(array_reverse($result, true)));
    }

    public function test_graph_mismatch_and_zero_duration_nonzero_route_is_rejected(): void
    {
        $canonical = $this->path([[77.50, 12.90], [77.51, 12.90], [77.52, 12.90]], [1, 1]); $candidate = $canonical;
        $candidate['data_version'] = str_replace('car|', 'bike|', self::version());
        try { (new CanonicalRouteIntervalProjector())->project($canonical, $candidate, [77.50, 12.90], [77.519, 12.90]); self::fail('graph mismatch accepted'); } catch (InvalidArgumentException $e) { self::assertSame('ROUTE_IDENTITY_MISMATCH', $e->getMessage()); }
        $zero = $canonical; $zero['legs'] = [['distance_meters' => 1, 'duration_seconds' => 0], ['distance_meters' => 1, 'duration_seconds' => 0]]; $zero['duration_seconds'] = 0;
        try { (new CanonicalRouteIntervalProjector())->project($zero, $zero, [77.50, 12.90], [77.519, 12.90]); self::fail('zero-duration route accepted'); } catch (InvalidArgumentException $e) { self::assertSame('ROUTE_METRICS_INVALID', $e->getMessage()); }
    }

    public function test_ambiguous_loop_and_bounds_are_rejected(): void
    {
        $loop = $this->path([[77.50, 12.90], [77.51, 12.90], [77.50, 12.90], [77.51, 12.90]], [1, 1, 1]);
        $loop['waypoints'] = [$loop['waypoints'][0], $loop['waypoints'][3]]; $loop['legs'] = [['distance_meters' => 3, 'duration_seconds' => 3]]; $loop['distance_meters'] = 3;
        try { (new CanonicalRouteIntervalProjector())->project($loop, $loop, [77.505, 12.90], [77.508, 12.90]); self::fail('ambiguous loop accepted'); } catch (InvalidArgumentException $e) { self::assertSame('ROUTE_PROJECTION_AMBIGUOUS', $e->getMessage()); }
        try { (new CanonicalRouteIntervalProjector())->project($loop, $loop, ['lat' => 14, 'lng' => 77.5], ['lat' => 12.9, 'lng' => 77.5]); self::fail('out of bounds accepted'); } catch (InvalidArgumentException $e) { self::assertSame('ROUTE_POINT_INVALID', $e->getMessage()); }
    }

    public function test_unique_loop_repeated_geometry_and_coincident_final_waypoint(): void
    {
        $geometry = [[77.50, 12.90], [77.51, 12.90], [77.51, 12.91], [77.50, 12.91], [77.50, 12.90], [77.49, 12.90]];
        $path = $this->path($geometry, [1, 1, 1, 1, 1]);
        $path['waypoints'] = [$path['waypoints'][0], $path['waypoints'][5]];
        $path['legs'] = [['distance_meters' => 5, 'duration_seconds' => 5]];
        $path['distance_meters'] = 5;
        $interval = (new CanonicalRouteIntervalProjector())->project($path, $path, [77.505, 12.91], [77.495, 12.90]);
        self::assertGreaterThan($interval['from_meters'], $interval['till_meters']);
        $coincident = $this->path([[77.50, 12.90], [77.51, 12.90], [77.52, 12.90], [77.52, 12.90]], [10, 10, 0]);
        $coincident['legs'][2]['distance_meters'] = 0;
        $coincident['distance_meters'] = 2;
        $interval = (new CanonicalRouteIntervalProjector())->project($coincident, $coincident, [77.51, 12.90], [77.52, 12.90]);
        self::assertSame(20, $interval['till_seconds']);
        self::assertSame($interval['route_length_meters'], $interval['till_meters']);
    }

    public function test_exact_ambiguous_route_reserves_full_route_without_guessing_occurrence(): void
    {
        $path = $this->path([[77.50,12.90],[77.51,12.90],[77.50,12.90],[77.51,12.90]], [10,10,10]);
        $path['distance_meters']=3;
        $hash=hash('sha256',json_encode($path,JSON_THROW_ON_ERROR));
        $result=(new CanonicalRouteIntervalProjector())->reserveExact($path,$path,$hash);
        self::assertSame('whole_route_exact_terms',$result['occupancy']);
        self::assertSame(0,$result['from_meters']);self::assertSame($result['route_length_meters'],$result['till_meters']);
        self::assertSame(10,$result['from_seconds']);self::assertSame(20,$result['till_seconds']);
        $other=$path;$other['legs'][0]['duration_seconds']=11;
        $this->expectException(InvalidArgumentException::class);
        (new CanonicalRouteIntervalProjector())->reserveExact($path,$other,$hash);
    }

    public function test_large_geometry_bound_and_measured_workload(): void
    {
        $geometry = [];
        for ($i = 0; $i < 20000; $i++) $geometry[] = [77.50 + .03 * $i / 19999, 12.90];
        $path = $this->path([$geometry[0], $geometry[19999]], [300]);
        $path['geometry'] = $geometry;
        $started = hrtime(true);
        $interval = (new CanonicalRouteIntervalProjector())->project($path, $path, [77.505, 12.90], [77.525, 12.90]);
        $elapsed = (hrtime(true) - $started) / 1e9;
        fwrite(STDERR, sprintf("\nProjector workload: 20000 vertices, %.4fs\n", $elapsed));
        self::assertLessThan(10, $elapsed, 'Bounded synthetic projector workload exceeded 10 seconds');
        self::assertGreaterThan(0, $interval['from_meters']);
        $path['geometry'][] = [77.531, 12.90];
        $this->expectException(InvalidArgumentException::class);
        (new CanonicalRouteIntervalProjector())->project($path, $path, [77.505, 12.90], [77.525, 12.90]);
    }

    /** @param array<int,array{0:float,1:float}> $geometry */
    private function path(array $geometry, array $durations): array
    {
        $waypoints = array_map(static fn (array $pair): array => ['location' => $pair, 'distance_meters' => 0], $geometry);
        return ['provider' => 'osrm', 'profile' => 'fixture', 'data_version' => self::version(), 'geometry' => $geometry, 'waypoints' => $waypoints,
            'legs' => array_map(static fn ($duration): array => ['distance_meters' => 1, 'duration_seconds' => $duration], $durations), 'distance_meters' => 1, 'duration_seconds' => array_sum($durations)];
    }

    private function distance(array $a, array $b): float
    {
        $lat = deg2rad($b[1] - $a[1]); $lng = deg2rad($b[0] - $a[0]);
        return 6371000 * 2 * asin(sqrt(sin($lat / 2) ** 2 + cos(deg2rad($a[1])) * cos(deg2rad($b[1])) * sin($lng / 2) ** 2));
    }
}
