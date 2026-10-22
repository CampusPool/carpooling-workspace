<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Routing\TrustedRouteContext;
use STS\Models\Car;
use STS\Models\Trip;
use STS\Models\User;
use STS\Services\Logic\TripsManager;
use Tests\TestCase;

/** Internal canonical creation seam only; not private admission or a booked ride. */
final class MobilityPrivateCanonicalContextTest extends TestCase
{
    private array $prior = [];
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['MOBILITY_COHORT_REQUIRED' => 'true', 'MOBILITY_COLLEGE_CODE' => 'CAMPUS-BLR'] as $k => $v) { $this->prior[$k] = getenv($k); putenv($k.'='.$v); }
    }
    protected function tearDown(): void { TrustedRouteContext::reset(); foreach ($this->prior as $k => $v) putenv($v === false ? $k : $k.'='.$v); parent::tearDown(); }

    public function test_private_points_create_no_global_nodes_or_route_links_and_keep_trusted_fare(): void
    {
        $host = User::factory()->create(['active' => true, 'banned' => false, 'image' => 'synthetic-profile.png', 'description' => 'Synthetic canonical seam fixture', 'nro_doc' => 'DEMO-ONLY', 'mobile_phone' => '+910000000000']); $car = Car::factory()->withCatalog()->create(['user_id' => $host->id]);
        $nodes = DB::table('nodes_geo')->count(); $routes = DB::table('routes')->count();
        $id = (string) Str::uuid(); $points = $this->points($id);
        $quote = ['endpoint_schema' => 'actor-private-v1', 'private_quote_id' => $id, 'points' => $points,
            'distance_meters' => 1500, 'duration_seconds' => 300, 'seat_price_minor' => 3200];
        TrustedRouteContext::push($points, $quote);
        try {
            $trip = app(TripsManager::class)->create($host, ['is_passenger' => 0, 'car_id' => $car->id, 'state' => Trip::STATE_READY,
                'from_town' => 'Private pickup', 'to_town' => 'Private dropoff', 'punto_partida' => 'Private pickup', 'punto_llegada' => 'Private dropoff',
                'trip_date' => now()->addHour()->format('Y-m-d H:i:s'), 'total_seats' => 1, 'friendship_type_id' => 2,
                'estimated_time' => '00:05:00', 'distance' => 1.5, 'co2' => 0, 'description' => '', 'points' => $points, 'seat_price_cents' => 3200]);
        } finally { TrustedRouteContext::reset(); }
        $this->assertNotNull($trip, json_encode(app(TripsManager::class)->getErrors())); $this->assertSame(3200, (int) $trip->seat_price_cents);
        $this->assertSame($nodes, DB::table('nodes_geo')->count()); $this->assertSame($routes, DB::table('routes')->count());
        $this->assertSame(0, $trip->routes()->count()); $this->assertNull($trip->path);
        $stored = DB::table('trips_points')->where('trip_id', $trip->id)->orderBy('id')->get();
        $this->assertCount(2, $stored); $this->assertEquals($points[0]['lat'], (float) $stored[0]->lat);
        $this->assertSame($id, json_decode($stored[0]->json_address, true)['private_quote_id']);
    }

    public function test_private_context_rejects_catalog_identity_and_mismatched_points(): void
    {
        $id = (string) Str::uuid(); $points = $this->points($id); $quote = ['endpoint_schema' => 'actor-private-v1', 'private_quote_id' => $id, 'points' => $points];
        $bad = $points; $bad[0]['json_address']['id'] = 1; $quote['points'] = $bad; TrustedRouteContext::push($bad, $quote);
        try { TrustedRouteContext::privateEndpoints(); $this->fail('Catalog identity accepted'); } catch (\LogicException) { $this->assertTrue(true); }
        $quote['points'] = $points; TrustedRouteContext::push($bad, $quote);
        $this->expectException(\LogicException::class); TrustedRouteContext::privateEndpoints();
    }

    private function points(string $id): array
    {
        return [
            ['address' => 'Private pickup', 'lat' => 12.9716, 'lng' => 77.5946, 'json_address' => ['private_quote_id' => $id, 'role' => 'pickup']],
            ['address' => 'Private dropoff', 'lat' => 12.9816, 'lng' => 77.6046, 'json_address' => ['private_quote_id' => $id, 'role' => 'dropoff']],
        ];
    }
}
