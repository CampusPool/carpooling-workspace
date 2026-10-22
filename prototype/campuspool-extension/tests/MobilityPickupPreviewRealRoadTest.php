<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\User;
use Tests\TestCase;

/** Explicit local proof: real three-graph HTTP and private MySQL API proposals. */
final class MobilityPickupPreviewRealRoadTest extends TestCase
{
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['MOBILITY_COHORT_REQUIRED', 'MOBILITY_COLLEGE_CODE'] as $name) $this->previous[$name] = getenv($name);
        putenv('MOBILITY_COHORT_REQUIRED=true'); putenv('MOBILITY_COLLEGE_CODE=CAMPUS-BLR');
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $name => $value) putenv($value === false ? $name : $name.'='.$value);
        parent::tearDown();
    }

    public function test_real_three_graph_private_previews_keep_modes_revisions_and_no_booking_authority(): void
    {
        if (getenv('MOBILITY_PICKUP_ROAD_PROOF') !== 'local-only') $this->markTestSkipped('Explicit three-graph local proof only.');
        config(['mobility_routing.provider' => 'osrm']);
        foreach (['car', 'bike', 'foot'] as $mode) {
            $pin = getenv('MOBILITY_ROUTING_'.strtoupper($mode).'_EXPECTED_DATA_VERSION');
            $this->assertIsString($pin);
            $this->assertNotEmpty($pin);
            config(['mobility_routing.'.$mode.'_expected_data_version' => $pin,
                'mobility_routing.'.$mode.'_base_url' => 'http://mobility-road-router-'.$mode.':5000',
                'mobility_routing.'.$mode.'_profile' => 'driving']);
        }
        foreach (['car', 'bike'] as $mode) {
            $host = $this->member(); $rider = $this->member(); $outsider = $this->member();
            $car = Car::factory()->withCatalog()->create(['user_id' => $host->id]);
            DB::table('mobility_host_applications')->insert(['user_id' => $host->id, 'car_id' => $car->id, 'vehicle_type' => $mode,
                'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $planInput = ['car_id' => $car->id, 'vehicle_type' => $mode, 'ride_mode' => $mode === 'car' ? 'scheduled' : 'on_demand',
                'points' => [['lng' => 77.5946, 'lat' => 12.9716], ['lng' => 77.6200, 'lat' => 12.9352]]];
            $created = $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-plans', $planInput, ['Idempotency-Key' => 'actual-plan-'.$mode])->assertCreated();
            $plan = $created->json('data.plan'); $token = $created->json('data.invite_token');
            $this->assertNotEmpty($plan['path']['geometry']);
            $this->assertSame(config('mobility_routing.'.$mode.'_expected_data_version'), $plan['path']['data_version']);
            $this->actingAsApiUser($rider)->getJson('/api/mobility/pickup-plans/'.$plan['id'])->assertForbidden();
            $intentInput = ['plan_id' => $plan['id'], 'invite_token' => $token, 'origin' => ['lng' => 77.598, 'lat' => 12.9706], 'max_walk_meters' => 999];
            $intent = $this->actingAsApiUser($rider)->postJson('/api/mobility/pickup-intents', $intentInput, ['Idempotency-Key' => 'actual-intent-'.$mode])->assertCreated()->json('data.intent');
            $prefs = ['expected_plan_version' => 1, 'expected_intent_version' => 1, 'max_detour_meters' => 10000, 'max_detour_seconds' => 3600];
            $response = $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-intents/'.$intent['id'].'/previews', $prefs, ['Idempotency-Key' => 'actual-preview-'.$mode])->assertCreated();
            $preview = $response->json('data.preview'); $snapshot = $preview['snapshot'];
            $this->assertTrue($snapshot['direct']['available']);
            $this->assertTrue($snapshot['meeting']['available']);
            $this->assertSame('DIRECT_RIDER_PICKUP', $snapshot['direct']['choice']);
            $this->assertSame('ON_ROUTE_MEETING_POINT', $snapshot['meeting']['choice']);
            $this->assertFalse($preview['booking_enabled']); $this->assertFalse($snapshot['access_verified']);
            $this->assertSame(config('mobility_routing.foot_expected_data_version'), $snapshot['foot_data_version']);
            $this->assertGreaterThan(0, $snapshot['meeting']['walking_distance_meters']);
            $this->assertGreaterThanOrEqual(0, $snapshot['meeting']['unmapped_access_gap_meters']);
            $stored = DB::table('mobility_pickup_previews')->where('id', $preview['id'])->value('snapshot');
            $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-intents/'.$intent['id'].'/previews', $prefs, ['Idempotency-Key' => 'actual-preview-'.$mode])->assertOk()->assertJsonPath('data.preview.id', $preview['id']);
            $this->actingAsApiUser($rider)->getJson('/api/mobility/pickup-previews/'.$preview['id'])->assertOk();
            $this->actingAsApiUser($outsider)->getJson('/api/mobility/pickup-previews/'.$preview['id'])->assertForbidden();
            $this->assertSame($stored, DB::table('mobility_pickup_previews')->where('id', $preview['id'])->value('snapshot'));
            $this->actingAsApiUser($rider)->putJson('/api/mobility/pickup-intents/'.$intent['id'], ['expected_version' => 1, 'origin' => ['lng' => 77.5981, 'lat' => 12.9706], 'max_walk_meters' => 999])->assertOk();
            $this->actingAsApiUser($host)->getJson('/api/mobility/pickup-previews/'.$preview['id'])->assertStatus(409);
            $this->assertSame($stored, DB::table('mobility_pickup_previews')->where('id', $preview['id'])->value('snapshot'));
        }
        foreach (['trips', 'trip_passengers', 'mobility_route_quotes', 'mobility_pool_publications', 'mobility_dispatch_requests'] as $table) $this->assertSame(0, DB::table($table)->count(), $table);
    }

    private function member(): User
    {
        $user = User::factory()->create(['active' => true, 'banned' => false]);
        DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return $user;
    }
}
