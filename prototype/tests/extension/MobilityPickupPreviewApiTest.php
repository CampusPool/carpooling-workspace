<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Models\Car;
use STS\Models\User;
use STS\Mobility\Pickup\PathProvider;
use STS\Mobility\Pickup\PickupPreviewPlanner;
use STS\Mobility\Trust\CohortPolicy;
use Tests\TestCase;

final class MobilityPickupPreviewApiTest extends TestCase
{
    private array $oldEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['MOBILITY_COHORT_REQUIRED', 'MOBILITY_COLLEGE_CODE'] as $name) $this->oldEnv[$name] = getenv($name);
        putenv('MOBILITY_COHORT_REQUIRED=true'); putenv('MOBILITY_COLLEGE_CODE=CAMPUS-BLR');
        config(['mobility_routing.car_expected_data_version' => $this->version('car'), 'mobility_routing.bike_expected_data_version' => $this->version('bike'), 'mobility_routing.foot_expected_data_version' => $this->version('foot')]);
        $this->bindPathProvider();
    }

    protected function tearDown(): void
    {
        foreach ($this->oldEnv as $name => $value) putenv($value === false ? $name : $name.'='.$value);
        parent::tearDown();
    }

    public function test_plan_create_invite_privacy_retry_and_changed_key_conflict(): void
    {
        [$host, $car] = $this->hostFixture(); $payload = $this->planPayload($car, 'car');
        $first = $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-plans', $payload, ['Idempotency-Key' => 'plan-1'])->assertCreated();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->json('data.invite_token')); $this->assertArrayNotHasKey('invite_hash', $first->json('data.plan'));
        $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-plans', $payload, ['Idempotency-Key' => 'plan-1'])->assertOk()->assertJsonPath('data.existing', true);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-plans', array_replace($payload, ['ride_mode' => 'on_demand']), ['Idempotency-Key' => 'plan-1'])->assertStatus(409);
    }

    public function test_intent_create_update_strict_fields_and_stale_version(): void
    {
        [$host, $car] = $this->hostFixture(); $rider = $this->memberFixture(); $plan = $this->createPlan($host, $car); $intent = $this->createIntent($rider, $plan);
        $this->actingAsApiUser($rider)->putJson('/api/mobility/pickup-intents/'.$intent, ['expected_version' => 1, 'origin' => ['lat' => 12.96, 'lng' => 77.56], 'max_walk_meters' => 600])->assertOk()->assertJsonPath('data.intent.version', 2);
        $this->actingAsApiUser($rider)->putJson('/api/mobility/pickup-intents/'.$intent, ['expected_version' => 1, 'origin' => ['lat' => 12.96, 'lng' => 77.56], 'max_walk_meters' => 600])->assertStatus(409);
        $this->actingAsApiUser($rider)->putJson('/api/mobility/pickup-intents/'.$intent, ['expected_version' => 2, 'origin' => ['lat' => 12.96, 'lng' => 77.56], 'max_walk_meters' => 600, 'invite_token' => $plan['invite']])->assertStatus(422);
    }

    public function test_walk_cap_rejects_one_kilometre_and_legacy_overlimit_reads(): void
    {
        [$host, $car] = $this->hostFixture(); $rider = $this->memberFixture(); $plan = $this->createPlan($host, $car);
        $this->actingAsApiUser($rider)->postJson('/api/mobility/pickup-intents', [
            'plan_id' => $plan['id'], 'invite_token' => $plan['invite'], 'origin' => ['lat' => 12.95, 'lng' => 77.55], 'max_walk_meters' => 1000,
        ], ['Idempotency-Key' => 'over-cap'])->assertStatus(422);
        $intent = $this->createIntent($rider, $plan);
        DB::table('mobility_pickup_intents')->where('id', $intent)->update(['max_walk_meters' => 1000]);
        $this->actingAsApiUser($rider)->getJson('/api/mobility/pickup-intents/'.$intent)->assertStatus(409)->assertJsonPath('error.code', 'PICKUP_WALK_LIMIT_EXCEEDED');
    }

    public function test_plan_update_rotates_invite_and_invalidates_intent(): void
    {
        [$host, $car] = $this->hostFixture(); $rider = $this->memberFixture(); $plan = $this->createPlan($host, $car); $intent = $this->createIntent($rider, $plan);
        $updated = $this->actingAsApiUser($host)->putJson('/api/mobility/pickup-plans/'.$plan['id'], array_merge($this->planPayload($car, 'car'), ['expected_version' => 1]))->assertOk();
        $this->assertNotSame($plan['invite'], $updated->json('data.invite_token')); $this->actingAsApiUser($rider)->getJson('/api/mobility/pickup-intents/'.$intent)->assertStatus(409);
    }

    public function test_banned_rider_revoked_host_and_wrong_cohort_are_denied_on_reads(): void
    {
        [$host, $car] = $this->hostFixture(); $rider = $this->memberFixture(); $plan = $this->createPlan($host, $car); $intent = $this->createIntent($rider, $plan);
        $rider->update(['banned' => true]); $this->actingAsApiUser($rider)->getJson('/api/mobility/pickup-intents/'.$intent)->assertStatus(403);
        $rider->update(['banned' => false]); DB::table('mobility_memberships')->where('user_id', $rider->id)->update(['status' => 'suspended']); $this->actingAsApiUser($rider)->getJson('/api/mobility/pickup-intents/'.$intent)->assertStatus(403);
        DB::table('mobility_memberships')->where('user_id', $host->id)->update(['college_code' => 'OTHER']); $this->actingAsApiUser($host)->getJson('/api/mobility/pickup-plans/'.$plan['id'])->assertStatus(403);
    }

    public function test_car_preview_has_private_no_store_response(): void
    {
        $this->previewMode('car');
    }

    public function test_bike_preview_has_private_no_store_response(): void
    {
        $this->previewMode('bike');
    }

    private function previewMode(string $mode): void
    { [$host, $car] = $this->hostFixture($mode); $rider = $this->memberFixture(); $plan = $this->createPlan($host, $car, $mode); $intent = $this->createIntent($rider, $plan); $this->mockPlanner($mode);
            $response = $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-intents/'.$intent.'/previews', $this->previewPayload(), ['Idempotency-Key' => 'preview-'.$mode])->assertCreated();
            $this->assertTrue($response->headers->hasCacheControlDirective('private')); $this->assertTrue($response->headers->hasCacheControlDirective('no-store')); $this->assertFalse($response->json('data.preview.booking_enabled')); $this->actingAsApiUser($rider)->getJson('/api/mobility/pickup-previews/'.$response->json('data.preview.id'))->assertOk();
    }

    public function test_preview_replay_avoids_planner_and_changed_payload_conflicts(): void
    {
        [$host, $car] = $this->hostFixture(); $rider = $this->memberFixture(); $plan = $this->createPlan($host, $car); $intent = $this->createIntent($rider, $plan); $this->mockPlanner('car');
        $payload = $this->previewPayload(); $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-intents/'.$intent.'/previews', $payload, ['Idempotency-Key' => 'replay'])->assertCreated();
        $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-intents/'.$intent.'/previews', $payload, ['Idempotency-Key' => 'replay'])->assertOk();
        $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-intents/'.$intent.'/previews', array_replace($payload, ['max_detour_meters' => 900]), ['Idempotency-Key' => 'replay'])->assertStatus(409);
    }

    public function test_expired_preview_and_other_actor_access_fail(): void
    {
        [$host, $car] = $this->hostFixture(); $rider = $this->memberFixture(); $other = $this->memberFixture(); $plan = $this->createPlan($host, $car); $intent = $this->createIntent($rider, $plan); $this->mockPlanner('car');
        $id = $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-intents/'.$intent.'/previews', $this->previewPayload(), ['Idempotency-Key' => 'expire'])->assertCreated()->json('data.preview.id');
        $this->actingAsApiUser($other)->getJson('/api/mobility/pickup-previews/'.$id)->assertStatus(403); DB::table('mobility_pickup_previews')->where('id', $id)->update(['expires_at' => now()->subMinute()]); $this->actingAsApiUser($host)->getJson('/api/mobility/pickup-previews/'.$id)->assertStatus(410);
    }

    public function test_changed_intent_during_planner_io_does_not_publish(): void
    {
        [$host, $car] = $this->hostFixture(); $rider = $this->memberFixture(); $plan = $this->createPlan($host, $car); $intent = $this->createIntent($rider, $plan); $this->mockPlanner('car', fn () => DB::table('mobility_pickup_intents')->where('id', $intent)->increment('version'));
        $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-intents/'.$intent.'/previews', $this->previewPayload(), ['Idempotency-Key' => 'stale'])->assertStatus(409); $this->assertSame(0, DB::table('mobility_pickup_previews')->count());
    }

    public function test_revoked_rider_during_planner_io_does_not_publish(): void
    {
        [$host, $car] = $this->hostFixture(); $rider = $this->memberFixture(); $plan = $this->createPlan($host, $car); $intent = $this->createIntent($rider, $plan); $this->mockPlanner('car', fn () => DB::table('mobility_memberships')->where('user_id', $rider->id)->update(['status' => 'suspended']));
        $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-intents/'.$intent.'/previews', $this->previewPayload(), ['Idempotency-Key' => 'revoked'])->assertStatus(403); $this->assertSame(0, DB::table('mobility_pickup_previews')->count());
    }

    public function test_changed_graph_fails_closed_without_trip_or_booking_writes(): void
    {
        [$host, $car] = $this->hostFixture(); $rider = $this->memberFixture(); $plan = $this->createPlan($host, $car); $intent = $this->createIntent($rider, $plan); config(['mobility_routing.car_expected_data_version' => $this->version('bike')]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-intents/'.$intent.'/previews', $this->previewPayload(), ['Idempotency-Key' => 'graph'])->assertStatus(409); $this->assertSame(0, DB::table('trips')->count()); $this->assertSame(0, DB::table('trip_passengers')->count());
    }

    public function test_expired_plan_retries_do_not_reveal_invite_or_create_a_new_record(): void
    {
        [$host, $car] = $this->hostFixture();
        $body = $this->planPayload($car, 'car');
        $id = $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-plans', $body, ['Idempotency-Key' => 'expired-plan'])->assertCreated()->json('data.plan.id');
        DB::table('mobility_pickup_plans')->where('id', $id)->update(['expires_at' => now()->subMinute()]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-plans', $body, ['Idempotency-Key' => 'expired-plan'])->assertStatus(410)->assertJsonPath('error.code', 'PLAN_EXPIRED');
        $this->assertSame(1, DB::table('mobility_pickup_plans')->count());
    }

    public function test_foot_graph_change_invalidates_stored_preview_read_and_replay(): void
    {
        [$host, $car] = $this->hostFixture(); $rider = $this->memberFixture();
        $plan = $this->createPlan($host, $car); $intent = $this->createIntent($rider, $plan); $this->mockPlanner('car');
        $id = $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-intents/'.$intent.'/previews', $this->previewPayload(), ['Idempotency-Key' => 'foot-drift'])->assertCreated()->json('data.preview.id');
        config(['mobility_routing.foot_expected_data_version' => str_replace(str_repeat('d', 64), str_repeat('e',64), $this->version('foot'))]);
        $this->actingAsApiUser($rider)->getJson('/api/mobility/pickup-previews/'.$id)->assertStatus(409)->assertJsonPath('error.code', 'PICKUP_GRAPH_MISMATCH');
        $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-intents/'.$intent.'/previews', $this->previewPayload(), ['Idempotency-Key' => 'foot-drift'])->assertStatus(409);
        $this->assertSame(1, DB::table('mobility_pickup_previews')->count());
    }

    public function test_preview_recovery_rejects_legacy_or_inconsistent_walking_preferences(): void
    {
        [$host, $car] = $this->hostFixture(); $rider = $this->memberFixture(); $plan = $this->createPlan($host, $car); $intent = $this->createIntent($rider, $plan); $this->mockPlanner('car');
        $id = $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-intents/'.$intent.'/previews', $this->previewPayload(), ['Idempotency-Key' => 'walk-policy-recovery'])->assertCreated()->json('data.preview.id');
        $snapshot = json_decode(DB::table('mobility_pickup_previews')->where('id', $id)->value('snapshot'), true);
        unset($snapshot['preferences']);
        DB::table('mobility_pickup_previews')->where('id', $id)->update(['snapshot' => json_encode($snapshot)]);
        $this->actingAsApiUser($host)->getJson('/api/mobility/pickup-previews/'.$id)->assertStatus(409)->assertJsonPath('error.code', 'PICKUP_WALK_POLICY_MISSING');
    }

    private function createPlan(User $host, Car $car, string $mode = 'car'): array { $r = $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-plans', $this->planPayload($car, $mode), ['Idempotency-Key' => 'plan-'.uniqid()])->assertCreated(); return ['id' => $r->json('data.plan.id'), 'invite' => $r->json('data.invite_token')]; }
    private function createIntent(User $rider, array $plan): string { return $this->actingAsApiUser($rider)->postJson('/api/mobility/pickup-intents', ['plan_id' => $plan['id'], 'invite_token' => $plan['invite'], 'origin' => ['lat' => 12.95, 'lng' => 77.55], 'max_walk_meters' => 500], ['Idempotency-Key' => 'intent-'.uniqid()])->assertCreated()->json('data.intent.id'); }
    private function planPayload(Car $car, string $mode): array { return ['car_id' => $car->id, 'vehicle_type' => $mode, 'ride_mode' => 'scheduled', 'points' => [['lat' => 12.90, 'lng' => 77.50], ['lat' => 13.00, 'lng' => 77.60]]]; }
    private function previewPayload(): array { return ['expected_plan_version' => 1, 'expected_intent_version' => 1, 'max_detour_meters' => 1000, 'max_detour_seconds' => 300]; }
    private function hostFixture(string $mode = 'car'): array { $host = $this->approvedMember(); $car = Car::factory()->withCatalog()->create(['user_id' => $host->id]); DB::table('mobility_host_applications')->insert(['user_id' => $host->id, 'car_id' => $car->id, 'vehicle_type' => $mode, 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]); return [$host, $car]; }
    private function memberFixture(): User { return $this->approvedMember(); }
    private function approvedMember(): User { $u = User::factory()->create(['active' => true, 'banned' => false]); DB::table('mobility_memberships')->insert(['user_id' => $u->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]); return $u; }
    private function bindPathProvider(): void { $this->app->bind(PathProvider::class, fn () => new class implements PathProvider { public function path(array $points, string $mode, float $timeoutSeconds = 5): array { $version = $mode.'|crop_sha256='.str_repeat($mode === 'foot' ? 'c' : 'a', 64).'|profile_sha256='.str_repeat($mode === 'foot' ? 'd' : 'b', 64); $first = $points[0]; $last = $points[count($points) - 1]; return ['distance_meters' => 1000, 'duration_seconds' => 120, 'provider' => 'fake', 'profile' => $mode, 'data_version' => $version, 'geometry' => [[$first['lng'], $first['lat']], [$last['lng'], $last['lat']]], 'waypoints' => [['distance_meters' => 0, 'location' => [$first['lng'], $first['lat']]], ['distance_meters' => 0, 'location' => [$last['lng'], $last['lat']]]], 'legs' => [['distance_meters' => 1000, 'duration_seconds' => 120]]]; } }); }
    private function mockPlanner(string $mode, ?callable $during = null): void { $mock = \Mockery::mock(PickupPreviewPlanner::class); $mock->shouldReceive('preview')->once()->withArgs(function (array $path, array $origin, string $actualMode, int $walk, int $detourM, int $detourS) use ($mode, $during): bool { $this->assertSame($mode, $actualMode); if ($during) $during(); return true; })->andReturnUsing(fn (array $path, array $origin, string $actualMode, int $walk): array => ['direct' => ['available' => true], 'meeting' => ['available' => false], 'evaluated_candidates' => 0, 'vehicle_data_version' => $this->version($mode), 'foot_data_version' => $this->version('foot'), 'preferences' => ['max_walk_meters' => $walk], 'booking_enabled' => false]); $this->app->instance(PickupPreviewPlanner::class, $mock); }
    private function version(string $mode): string { return $mode.'|crop_sha256='.str_repeat($mode === 'foot' ? 'c' : 'a', 64).'|profile_sha256='.str_repeat($mode === 'foot' ? 'd' : 'b', 64); }
}
