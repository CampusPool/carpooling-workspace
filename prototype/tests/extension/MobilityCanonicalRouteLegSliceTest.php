<?php

namespace Tests\Feature\Mobility;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use STS\Mobility\Journeys\CanonicalRouteIntervalProjector;

final class MobilityCanonicalRouteLegSliceTest extends TestCase
{
    public function test_slice_preserves_detour_geometry_and_source_totals(): void
    {
        $path = $this->path([[77.50, 12.90], [77.51, 12.90], [77.52, 12.901], [77.53, 12.901], [77.54, 12.90]], [[1000, 10], [2000, 20], [3000, 30]]);
        $hash = $this->hash($path); $result = (new CanonicalRouteIntervalProjector())->slice($path, [77.515, 12.9005], [77.525, 12.901], $hash); $sliced = $result['driver_path'];
        self::assertSame($path['geometry'], $sliced['geometry']); self::assertSame($path['provider'], $sliced['provider']); self::assertSame($path['profile'], $sliced['profile']); self::assertSame($path['data_version'], $sliced['data_version']); self::assertSame($path['distance_meters'], $sliced['distance_meters']); self::assertSame($path['duration_seconds'], $sliced['duration_seconds']); self::assertCount(4, $sliced['waypoints']);
        self::assertSame($path['distance_meters'], array_sum(array_column($sliced['legs'], 'distance_meters'))); self::assertSame($path['duration_seconds'], array_sum(array_column($sliced['legs'], 'duration_seconds')));
        self::assertSame($result['interval'], (new CanonicalRouteIntervalProjector())->project($path, $sliced, $sliced['waypoints'][1]['location'], $sliced['waypoints'][2]['location'], $hash));
    }

    public function test_slice_metrics_are_proportional_across_distinct_original_legs(): void
    {
        $path = $this->path([[77.50, 12.90], [77.51, 12.90], [77.52, 12.90], [77.53, 12.90]], [[1000, 10], [2000, 20], [3000, 30]]); $result = (new CanonicalRouteIntervalProjector())->slice($path, [77.505, 12.90], [77.525, 12.90], $this->hash($path));
        self::assertCount(3, $result['driver_path']['legs']); self::assertSame([500, 4000, 1500], array_column($result['driver_path']['legs'], 'distance_meters')); self::assertSame([5, 40, 15], array_column($result['driver_path']['legs'], 'duration_seconds'));
    }

    public function test_declared_osrm_totals_are_preserved_when_leg_rounding_differs(): void
    {
        $path = $this->path([[77.50, 12.90], [77.51, 12.90], [77.52, 12.90], [77.53, 12.90]], [[1000, 10], [2000, 20], [3000, 30]]); $path['distance_meters'] = 6001; $path['duration_seconds'] = 61;
        $sliced = (new CanonicalRouteIntervalProjector())->slice($path, [77.505, 12.90], [77.525, 12.90], $this->hash($path))['driver_path'];
        self::assertSame(6001, array_sum(array_column($sliced['legs'], 'distance_meters'))); self::assertSame(61, array_sum(array_column($sliced['legs'], 'duration_seconds')));
    }

    public function test_endpoint_dropoff_and_fail_closed_inputs(): void
    {
        $path = $this->path([[77.50, 12.90], [77.51, 12.90], [77.52, 12.90], [77.52, 12.90]], [[1000, 10], [2000, 20], [0, 0]]); $projector = new CanonicalRouteIntervalProjector(); $hash = $this->hash($path);
        $result = $projector->slice($path, [77.505, 12.90], [77.52, 12.90], $hash); self::assertSame([77.52, 12.90], $result['driver_path']['waypoints'][3]['location']);
        foreach ([[$path, [77.525, 12.90], [77.505, 12.90], $hash], [$path, [77.505, 12.91], [77.515, 12.90], $hash], [$path, [77.505, 12.90], [77.515, 12.90], str_repeat('0', 64)]] as [$candidate, $pickup, $dropoff, $expected]) { try { $projector->slice($candidate, $pickup, $dropoff, $expected); self::fail('invalid slice accepted'); } catch (InvalidArgumentException) { self::assertTrue(true); } }
    }

    public function test_ambiguous_loop_and_identity_are_rejected(): void
    {
        $loop = $this->path([[77.50, 12.90], [77.51, 12.90], [77.50, 12.90], [77.51, 12.90]], [[1000, 10], [1000, 10], [1000, 10]]); $projector = new CanonicalRouteIntervalProjector();
        try { $projector->slice($loop, [77.505, 12.90], [77.508, 12.90], $this->hash($loop)); self::fail('ambiguous loop accepted'); } catch (InvalidArgumentException $e) { self::assertSame('ROUTE_PROJECTION_AMBIGUOUS', $e->getMessage()); }
    }

    private function path(array $geometry, array $legs): array
    {
        $waypoints = array_map(static fn (array $pair): array => ['location' => $pair, 'distance_meters' => 0], [$geometry[0], $geometry[(int) floor(count($geometry) / 3)], $geometry[(int) floor(2 * count($geometry) / 3)], $geometry[array_key_last($geometry)]]);
        return ['provider' => 'osrm', 'profile' => 'fixture', 'data_version' => 'car|crop_sha256='.str_repeat('a', 64).'|profile_sha256='.str_repeat('b', 64), 'geometry' => $geometry, 'waypoints' => $waypoints, 'legs' => array_map(static fn (array $leg): array => ['distance_meters' => $leg[0], 'duration_seconds' => $leg[1]], $legs), 'distance_meters' => array_sum(array_column($legs, 0)), 'duration_seconds' => array_sum(array_column($legs, 1))];
    }

    private function hash(array $path): string { return hash('sha256', json_encode($path, JSON_THROW_ON_ERROR)); }
}
