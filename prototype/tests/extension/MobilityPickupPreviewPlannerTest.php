<?php

namespace Tests\Unit\Mobility;

use Closure;
use PHPUnit\Framework\TestCase;
use STS\Mobility\Pickup\PathProvider;
use STS\Mobility\Pickup\PickupException;
use STS\Mobility\Pickup\PickupPolicy;
use STS\Mobility\Pickup\PickupPreviewPlanner;
use STS\Mobility\Routing\RoutingException;

final class MobilityPickupPreviewPlannerTest extends TestCase
{
    public const CAR_VERSION = 'car|crop_sha256='.self::CAR_CROP.'|profile_sha256='.self::CAR_PROFILE;
    public const FOOT_VERSION = 'foot|crop_sha256='.self::FOOT_CROP.'|profile_sha256='.self::FOOT_PROFILE;
    private const CAR_CROP = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CAR_PROFILE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const FOOT_CROP = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const FOOT_PROFILE = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    public function testDirectPickupReportsActualDetourAndDoesNotEvaluateWalking(): void
    {
        $provider = new FunctionalPickupPathProvider();
        $provider->vehicleDistance = 1350;
        $provider->vehicleDuration = 160;
        $planner = new PickupPreviewPlanner($provider);

        $result = $planner->preview($this->driverPath(), $this->point(0.004, 0.001), 'car', 999, 500, 100);

        self::assertTrue($result['direct']['available']);
        self::assertSame('DIRECT_RIDER_PICKUP', $result['direct']['choice']);
        self::assertSame(350, $result['direct']['detour']['distance_delta_meters']);
        self::assertSame(60, $result['direct']['detour']['duration_delta_seconds']);
        self::assertNull($result['direct']['walking_distance_meters']);
        self::assertSame('not_evaluated', $result['direct']['walking_status']);
    }

    public function testDirectPickupRespectsMeterAndSecondLimits(): void
    {
        $provider = new FunctionalPickupPathProvider();
        $provider->vehicleDistance = 1350;
        $provider->vehicleDuration = 160;
        $result = (new PickupPreviewPlanner($provider))->preview(
            $this->driverPath(), $this->point(0.004, 0.001), 'car', 0, 349, 60
        );

        self::assertFalse($result['direct']['available']);
        self::assertSame('DETOUR_LIMIT_EXCEEDED', $result['direct']['code']);
    }

    public function testMeetingRanksActualFootDistanceRatherThanGeometricProjection(): void
    {
        $provider = new FunctionalPickupPathProvider();
        $provider->walkDistances = [
            '0.0040000,0.0000000' => 900,
            '0.0000000,0.0000000' => 100,
            '0.0100000,0.0000000' => 200,
        ];
        $result = (new PickupPreviewPlanner($provider))->preview(
            $this->driverPath(), $this->point(0.004, 0.001), 'car', 999, 0, 0
        );

        self::assertTrue($result['meeting']['available']);
        self::assertSame(100, $result['meeting']['walking_distance_meters']);
        self::assertSame([0.0, 0.0], $result['meeting']['vehicle_pickup_location']);
        self::assertSame('shortest_total_access_meters_among_evaluated_candidates', $result['ranking']);
    }

    public function testWalkingCapAccepts999AndRejectsOneKilometre(): void
    {
        $provider = new FunctionalPickupPathProvider();
        $planner = new PickupPreviewPlanner($provider);

        $result = $planner->preview($this->driverPath(), $this->point(0.004, 0.001), 'car', PickupPolicy::MAX_WALK_METERS, 0, 0);
        self::assertTrue($result['meeting']['available']);

        $this->expectException(PickupException::class);
        $this->expectExceptionMessage('PICKUP_INPUT_INVALID');
        $planner->preview($this->driverPath(), $this->point(0.004, 0.001), 'car', 1000, 0, 0);
    }

    public function testLowerWalkingPreferenceStillAppliesToTotalAccess(): void
    {
        $provider = new FunctionalPickupPathProvider();
        $provider->walkDistances = ['0.0000000,0.0000000' => 500];
        $result = (new PickupPreviewPlanner($provider))->preview(
            $this->driverPath(), $this->point(0.004, 0.001), 'car', 499, 0, 0
        );

        self::assertFalse($result['meeting']['available']);
        self::assertSame('NO_ELIGIBLE_MEETING_POINT', $result['meeting']['code']);
    }

    public function testHandoffGapIsIncludedConservativelyInTotalAccess(): void
    {
        $provider = new FunctionalPickupPathProvider();
        $provider->walkingEndpointOffset = 0.000005;
        $result = (new PickupPreviewPlanner($provider))->preview(
            $this->driverPath(), $this->point(0.004, 0.001), 'car', 501, 0, 0
        );

        self::assertTrue($result['meeting']['available']);
        self::assertSame(500, $result['meeting']['walking_distance_meters']);
        self::assertGreaterThan(0, $result['meeting']['handoff_gap_meters']);
        self::assertSame(
            $result['meeting']['walking_distance_meters']
                + $result['meeting']['unmapped_access_gap_meters']
                + (int) ceil($result['meeting']['handoff_gap_meters']),
            $result['meeting']['total_access_meters']
        );
        self::assertGreaterThan(500, $result['meeting']['total_access_meters']);
    }

    public function testVehicleGraphMismatchFailsClosed(): void
    {
        $provider = new FunctionalPickupPathProvider();
        $provider->vehicleVersion = str_replace(str_repeat('b', 64), str_repeat('e', 64), self::CAR_VERSION);

        $this->expectException(PickupException::class);
        $this->expectExceptionMessage('ROUTE_GRAPH_MISMATCH');
        (new PickupPreviewPlanner($provider))->preview($this->driverPath(), $this->point(0.004, 0.001), 'car', 999, 500, 100);
    }

    public function testFootGraphMismatchFailsClosed(): void
    {
        $provider = new FunctionalPickupPathProvider();
        $provider->footVersion = 'foot|crop_sha256='.str_repeat('c',64).'|profile_sha256='.str_repeat('e',64);
        $provider->changeFootVersionAfterFirst = true;

        $this->expectException(PickupException::class);
        $this->expectExceptionMessage('ROUTE_GRAPH_MISMATCH');
        (new PickupPreviewPlanner($provider))->preview($this->driverPath(), $this->point(0.004, 0.001), 'car', 999, 0, 0);
    }

    public function testFootOutageLeavesMeetingUnavailableWithoutRoadFallback(): void
    {
        $provider = new FunctionalPickupPathProvider();
        $provider->footFailure = new RoutingException('ROUTE_PROVIDER_UNAVAILABLE');
        $result = (new PickupPreviewPlanner($provider))->preview(
            $this->driverPath(), $this->point(0.004, 0.001), 'car', 999, 0, 0
        );

        self::assertFalse($result['meeting']['available']);
        self::assertSame('ROUTE_PROVIDER_UNAVAILABLE', $result['meeting']['code']);
        self::assertNotEmpty($provider->vehicleRequests);
        self::assertNotEmpty($provider->footRequests);
        self::assertSame([], $result['alternatives']);
    }

    public function testDeadlineIsPassedToCallsAndExpiryFailsClosed(): void
    {
        $now = 0.0;
        $provider = new FunctionalPickupPathProvider();
        $provider->advanceClock = static function () use (&$now): void { $now += 21.0; };
        $planner = new PickupPreviewPlanner($provider, static function () use (&$now): float { return $now; });

        try {
            $planner->preview($this->driverPath(), $this->point(0.004, 0.001), 'car', 999, 500, 100);
            self::fail('Expected overall deadline rejection');
        } catch (PickupException $error) {
            self::assertSame('PICKUP_ROUTING_DEADLINE', $error->machineCode);
        }
        self::assertNotEmpty($provider->timeouts);
        self::assertLessThanOrEqual(5.0, $provider->timeouts[0]);
    }

    public function testCandidateEvaluationIsBoundedAndTieBreaksByRoutePosition(): void
    {
        $provider = new FunctionalPickupPathProvider();
        $provider->walkDistances = [];
        $result = (new PickupPreviewPlanner($provider))->preview(
            $this->driverPath(), $this->point(0.005, 0.001), 'car', 999, 0, 0
        );

        self::assertLessThanOrEqual(4, $result['evaluated_candidates']);
        self::assertSame(4, $result['candidate_limit']);
        self::assertTrue($result['meeting']['available']);
        self::assertEquals(0.0, $result['meeting']['route_position']);
    }

    public function testMalformedDriverSnapshotIsRejectedWithSafePickupException(): void
    {
        $snapshot = $this->driverPath();
        unset($snapshot['geometry']);

        try {
            (new PickupPreviewPlanner(new FunctionalPickupPathProvider()))->preview(
                $snapshot, $this->point(0.004, 0.001), 'car', 999, 500, 100
            );
            self::fail('Expected malformed snapshot to be rejected');
        } catch (PickupException $error) {
            self::assertContains($error->machineCode, ['PICKUP_INPUT_INVALID', 'PICKUP_ROUTE_INVALID']);
        }
    }

    private function driverPath(): array
    {
        return [
            'points' => [$this->point(0.0, 0.0), $this->point(0.01, 0.0)],
            'distance_meters' => 1000,
            'duration_seconds' => 100,
            'provider' => 'osrm',
            'profile' => 'driving',
            'data_version' => self::CAR_VERSION,
            'geometry' => [[0.0, 0.0], [0.01, 0.0]],
            'waypoints' => [
                ['distance_meters' => 0.0, 'location' => [0.0, 0.0]],
                ['distance_meters' => 0.0, 'location' => [0.01, 0.0]],
            ],
            'legs' => [['distance_meters' => 1000, 'duration_seconds' => 100]],
        ];
    }

    private function point(float $lng, float $lat): array
    {
        return ['lng' => $lng, 'lat' => $lat];
    }
}

final class FunctionalPickupPathProvider implements PathProvider
{
    public string $vehicleVersion = MobilityPickupPreviewPlannerTest::CAR_VERSION;
    public string $footVersion = MobilityPickupPreviewPlannerTest::FOOT_VERSION;
    public int $vehicleDistance = 1000;
    public int $vehicleDuration = 100;
    public array $walkDistances = [];
    public ?RoutingException $footFailure = null;
    public ?Closure $advanceClock = null;
    public array $vehicleRequests = [];
    public array $footRequests = [];
    public array $timeouts = [];
    public bool $changeFootVersionAfterFirst = false;
    public float $walkingEndpointOffset = 0.0;

    public function path(array $points, string $mode, ?float $timeoutSeconds = null): array
    {
        $this->timeouts[] = $timeoutSeconds;
        if ($this->advanceClock) {
            ($this->advanceClock)();
        }
        if ($mode === 'foot') {
            $this->footRequests[] = $points;
            if ($this->footFailure) {
                throw $this->footFailure;
            }
            $key = sprintf('%.7f,%.7f', $points[1]['lng'], $points[1]['lat']);
            $distance = $this->walkDistances[$key] ?? 500;
            $version = $this->changeFootVersionAfterFirst && count($this->footRequests) > 1 ? str_replace(str_repeat('e',64),str_repeat('f',64),$this->footVersion) : $this->footVersion;
            if ($this->walkingEndpointOffset !== 0.0) {
                $points[1]['lng'] += $this->walkingEndpointOffset;
            }
            return $this->route($points, $distance, 50, $version, 'distance-walk');
        }
        $this->vehicleRequests[] = $points;
        $distance = count($points) === 3 ? $this->vehicleDistance : 1000;
        $duration = count($points) === 3 ? $this->vehicleDuration : 100;
        return $this->route($points, $distance, $duration, $this->vehicleVersion, 'driving');
    }

    private function route(array $points, int $distance, int $duration, string $version, string $profile): array
    {
        $legs = [];
        $legDistance = max(1, intdiv($distance, max(1, count($points) - 1)));
        $legDuration = max(1, intdiv($duration, max(1, count($points) - 1)));
        for ($i = 0; $i < count($points) - 1; $i++) {
            $legs[] = ['distance_meters' => $legDistance, 'duration_seconds' => $legDuration];
        }
        $geometry = array_map(static fn (array $point): array => [$point['lng'], $point['lat']], $points);
        return [
            'distance_meters' => $distance, 'duration_seconds' => $duration,
            'provider' => 'osrm', 'profile' => $profile, 'data_version' => $version,
            'geometry' => $geometry,
            'waypoints' => array_map(static fn (array $point): array => [
                'distance_meters' => 0.0, 'location' => [$point['lng'], $point['lat']],
            ], $points),
            'legs' => $legs,
        ];
    }
}
