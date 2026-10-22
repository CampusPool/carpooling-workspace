<?php

namespace Tests\Feature\Mobility;

use STS\Mobility\Journeys\DemoFarePolicy;
use STS\Mobility\Journeys\JourneyPlanner;
use STS\Mobility\Pickup\PathProvider;
use STS\Mobility\Pickup\PickupException;
use STS\Mobility\Routing\RoutingException;
use Tests\TestCase;

/** Injected synthetic paths verify policy, not road quality or physical access. */
final class MobilityJourneyPlannerTest extends TestCase
{
    private array $from = ['lat' => 12.9, 'lng' => 77.50];
    private array $to = ['lat' => 12.9, 'lng' => 77.60];
    private array $origin = ['lat' => 12.9, 'lng' => 77.52];
    private array $destination = ['lat' => 12.9, 'lng' => 77.57];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['car', 'bike', 'foot'] as $mode) config(['mobility_routing.'.$mode.'_expected_data_version' => self::version($mode)]);
    }

    public function test_both_vehicle_choices_bind_access_riding_egress_and_demo_compensation(): void
    {
        foreach (['car', 'bike'] as $mode) foreach (['DIRECT_RIDER_PICKUP', 'ON_ROUTE_MEETING_POINT'] as $choice) {
            $provider = $this->provider(); $planner = new JourneyPlanner($provider);
            $result = $planner->plan($this->baseline($provider, $mode), $this->origin, $this->destination, $mode, $choice, 999, 10000, 3600);
            $this->assertTrue($result['available']); $this->assertSame(200, $result['total_walking_meters']);
            $this->assertSame($this->destination, $result['rider_destination']);
            $this->assertCount(4, $result['driver_path']['waypoints']);
            $this->assertLessThan($result['dropoff_route_position'], $result['pickup_route_position']);
            $this->assertFalse($result['booking_enabled']); $this->assertFalse($result['access_verified']);
            $pricing = $result['pricing']; $this->assertTrue($pricing['demo_only']);
            $this->assertSame($pricing['base_fare_minor'] + $pricing['direct_pickup_surcharge_minor'], $pricing['total_fare_minor']);
            $this->assertSame($choice === 'DIRECT_RIDER_PICKUP' ? ($mode === 'car' ? 2000 : 1000) : 0, $pricing['driver_additional_compensation_minor']);
            // Charge only the middle occupied leg, not driver approach/full detour.
            $this->assertSame($result['riding']['distance_meters'], $pricing['riding_distance_meters']);
            $this->assertLessThan($result['driver_path']['distance_meters'], $pricing['riding_distance_meters']);
        }
    }

    public function test_combined_walking_999_allowed_1000_rejected_and_lower_preference_supported(): void
    {
        $p = $this->provider(); $baseline = $this->baseline($p);
        $p->walkDistances = [499, 500];
        $this->assertSame(999, (new JourneyPlanner($p))->plan($baseline, $this->origin, $this->destination, 'car', 'DIRECT_RIDER_PICKUP', 999, 10000, 3600)['total_walking_meters']);
        $p->walkDistances = [500, 500];
        $this->assertFalse((new JourneyPlanner($p))->plan($baseline, $this->origin, $this->destination, 'car', 'DIRECT_RIDER_PICKUP', 999, 10000, 3600)['available']);
        $p->walkDistances = [100, 100];
        $this->assertFalse((new JourneyPlanner($p))->plan($baseline, $this->origin, $this->destination, 'car', 'DIRECT_RIDER_PICKUP', 199, 10000, 3600)['available']);
    }

    public function test_unmapped_endpoint_gaps_count_against_combined_walking(): void
    {
        $p = $this->provider(); $baseline = $this->baseline($p); $p->walkDistances = [495, 495]; $p->reportedGap = 3.1;
        $this->assertFalse((new JourneyPlanner($p))->plan($baseline, $this->origin, $this->destination, 'car', 'DIRECT_RIDER_PICKUP', 999, 10000, 3600)['available']);
    }

    public function test_reverse_direction_is_rejected_before_routing(): void
    {
        $p = $this->provider(); $baseline = $this->baseline($p); $calls = $p->calls;
        $result = (new JourneyPlanner($p))->plan($baseline, $this->destination, $this->origin, 'car', 'DIRECT_RIDER_PICKUP', 999, 10000, 3600);
        $this->assertSame('ROUTE_DIRECTION_INCOMPATIBLE', $result['code']); $this->assertSame($calls, $p->calls);
    }

    public function test_missing_foot_routes_do_not_fall_back_to_straight_line_walking(): void
    {
        $p = $this->provider(); $baseline = $this->baseline($p); $p->noFoot = true;
        $this->assertFalse((new JourneyPlanner($p))->plan($baseline, $this->origin, $this->destination, 'car', 'DIRECT_RIDER_PICKUP', 999, 10000, 3600)['available']);
    }

    public function test_detour_is_measured_on_complete_pickup_and_dropoff_route(): void
    {
        $p = $this->provider(); $baseline = $this->baseline($p);
        $this->assertFalse((new JourneyPlanner($p))->plan($baseline, $this->origin, $this->destination, 'car', 'DIRECT_RIDER_PICKUP', 999, 100, 3600)['available']);
    }

    public function test_invalid_walking_policy_graph_and_pricing_fail_closed(): void
    {
        $p = $this->provider(); $baseline = $this->baseline($p);
        try { (new JourneyPlanner($p))->plan($baseline, $this->origin, $this->destination, 'car', 'DIRECT_RIDER_PICKUP', 1000, 10000, 3600); $this->fail(); }
        catch (PickupException $e) { $this->assertSame('PICKUP_WALK_LIMIT_EXCEEDED', $e->machineCode); }
        config(['mobility_routing.car_expected_data_version' => self::version('bike')]);
        try { (new JourneyPlanner($p))->plan($baseline, $this->origin, $this->destination, 'car', 'DIRECT_RIDER_PICKUP', 999, 10000, 3600); $this->fail(); }
        catch (PickupException $e) { $this->assertSame('PICKUP_GRAPH_MISMATCH', $e->machineCode); }
        config(['mobility_routing.demo_car_direct_surcharge_minor' => 0]);
        $this->expectExceptionMessage('DEMO_PRICING_MISCONFIGURED'); DemoFarePolicy::quote('car', 'DIRECT_RIDER_PICKUP', 1000);
    }

    public function test_integer_pricing_rounds_up_and_supports_explicit_configuration(): void
    {
        config(['mobility_routing.demo_pricing_version' => 'acceptance-demo', 'mobility_routing.demo_car_minor_per_km' => '801', 'mobility_routing.demo_car_direct_surcharge_minor' => '1234']);
        $price = DemoFarePolicy::quote('car', 'DIRECT_RIDER_PICKUP', 1001);
        $this->assertSame(802, $price['base_fare_minor']); $this->assertSame(2036, $price['total_fare_minor']);
        $this->assertSame('acceptance-demo', $price['policy_version']);
    }

    private function baseline(PathProvider $p, string $mode = 'car'): array { return $p->path([$this->from, $this->to], $mode) + ['points' => [$this->from, $this->to]]; }
    public function test_meeting_uses_actual_walk_ranking_and_never_reroutes_the_driver(): void
    {
        $provider = new class implements PathProvider {
            public int $carCalls=0;
            public function path(array $points,string $mode,float $timeoutSeconds=5):array {
                if($mode==='car') {
                    if(++$this->carCalls>1)throw new \RuntimeException('Meeting must not reroute the car');
                    $geometry=[[77.5,12.9],[77.52,12.9],[77.523,12.9],[77.57,12.9],[77.6,12.9]];$distance=10000;$seconds=1000;
                } else {
                    $geometry=array_map(fn($p)=>[$p['lng'],$p['lat']],$points);
                    $distance=$points[0]==$points[1]?0:(abs($points[1]['lng']-77.523)<0.00001?400:850);$seconds=(int)ceil($distance/1.3);
                }
                return ['provider'=>'osrm','profile'=>'fixture','data_version'=>MobilityJourneyPlannerTest::version($mode),'geometry'=>$geometry,'waypoints'=>array_map(fn($p)=>['location'=>[$p['lng'],$p['lat']],'distance_meters'=>0],$points),'legs'=>[['distance_meters'=>$distance,'duration_seconds'=>$seconds]],'distance_meters'=>$distance,'duration_seconds'=>$seconds];
            }
        };
        $baseline=$this->baseline($provider);$planner=new JourneyPlanner($provider);$origin=['lat'=>12.901,'lng'=>77.52];
        $result=$planner->plan($baseline,$origin,$this->destination,'car','ON_ROUTE_MEETING_POINT',999,0,0);
        $this->assertTrue($result['available']);$this->assertSame($baseline['geometry'],$result['driver_path']['geometry']);$this->assertSame(1,$provider->carCalls);
        $this->assertSame(400,$result['total_walking_meters'],'farther geometric point wins because its actual foot route is shorter');
        $this->assertEqualsWithDelta(77.523,$result['pickup']['lng'],0.00001);$this->assertSame(0,$result['detour']['added_distance_meters']);$this->assertSame(0,$result['pricing']['direct_pickup_surcharge_minor']);
        $this->assertFalse($planner->plan($baseline,$origin,$this->destination,'car','ON_ROUTE_MEETING_POINT',399,0,0)['available']);
    }
    public static function version(string $mode): string { return $mode.'|crop_sha256='.str_repeat('a', 64).'|profile_sha256='.str_repeat($mode === 'car' ? 'b' : ($mode === 'bike' ? 'c' : 'd'), 64); }
    private function provider(): PathProvider
    {
        return new class implements PathProvider {
            public array $walkDistances = [100, 100]; public float $reportedGap = 0; public bool $noFoot = false; public int $calls = 0;
            public function path(array $points, string $mode, float $timeoutSeconds = 5): array
            {
                $this->calls++; if ($mode === 'foot' && $this->noFoot) throw new RoutingException('NO_ROUTE');
                $distance = $mode === 'foot' ? (array_shift($this->walkDistances) ?? 100) : 1200;
                $legs = array_fill(0, count($points) - 1, ['distance_meters' => $distance, 'duration_seconds' => 100]);
                return ['provider' => 'osrm', 'profile' => 'fixture-only', 'data_version' => MobilityJourneyPlannerTest::version($mode),
                    'distance_meters' => $distance * count($legs), 'duration_seconds' => 100 * count($legs), 'legs' => $legs,
                    'waypoints' => array_map(fn ($p) => ['location' => [$p['lng'], $p['lat']], 'distance_meters' => $mode === 'foot' ? $this->reportedGap : 0], $points),
                    'geometry' => array_map(fn ($p) => [$p['lng'], $p['lat']], $points)];
            }
        };
    }
}
