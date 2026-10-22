<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\User;
use Tests\TestCase;

/** DB state/permission failures use injected stored route fixtures, not real maps. */
final class MobilityPickupAgreementTest extends TestCase
{
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['MOBILITY_COHORT_REQUIRED', 'MOBILITY_COLLEGE_CODE'] as $name) $this->previous[$name] = getenv($name);
        putenv('MOBILITY_COHORT_REQUIRED=true'); putenv('MOBILITY_COLLEGE_CODE=CAMPUS-BLR');
        foreach (['car', 'bike', 'foot'] as $mode) config(['mobility_routing.'.$mode.'_expected_data_version' => $this->version($mode)]);
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $name => $value) putenv($value === false ? $name : $name.'='.$value);
        parent::tearDown();
    }

    public function test_both_choices_all_labels_confirm_exact_bytes_without_booking_authority(): void
    {
        foreach (['car', 'bike'] as $mode) foreach (['scheduled', 'on_demand'] as $rideMode) foreach (['DIRECT_RIDER_PICKUP', 'ON_ROUTE_MEETING_POINT'] as $choice) {
            $f = $this->fixture($mode, $rideMode); $selection = $this->select($f, $choice)->assertCreated()->json('data.selection');
            $this->assertSame($choice, $selection['terms']['selected_option']['choice']);
            $this->assertSame($mode, $selection['terms']['vehicle_type']); $this->assertSame($rideMode, $selection['terms']['ride_mode']);
            $this->assertFalse($selection['booking_enabled']); $this->assertSame('pickup_only', $selection['scope']);
            $row = DB::table('mobility_pickup_selections')->where('id', $selection['id'])->first();
            $this->assertSame(hash('sha256', $row->terms), $selection['terms_hash']);
            $response = $this->action($f['rider'], $selection, 'confirm')->assertOk()->assertJsonPath('data.selection.state', 'confirmed');
            $this->assertTrue($response->headers->hasCacheControlDirective('private')); $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            $confirmed = DB::table('mobility_pickup_selections')->where('id', $selection['id'])->first();
            $this->assertSame($row->terms, $confirmed->terms); $this->assertSame($row->terms_hash, $confirmed->terms_hash);
            $this->assertSame($f['intent'], $confirmed->active_intent_id); $this->assertNull($confirmed->closed_at);
            $this->actingAsApiUser($f['rider'])->getJson('/api/mobility/pickup-selections/'.$selection['id'])->assertOk();
        }
        foreach (['trips', 'trip_passengers', 'mobility_route_quotes', 'mobility_pool_publications', 'mobility_dispatch_requests', 'mobility_financial_postings'] as $table) $this->assertSame(0, DB::table($table)->count());
    }

    public function test_participant_scope_and_driver_cannot_confirm_for_rider(): void
    {
        $f = $this->fixture(); $s = $this->select($f)->assertCreated()->json('data.selection'); $other = $this->member();
        $this->actingAsApiUser($other)->getJson('/api/mobility/pickup-selections/'.$s['id'])->assertForbidden();
        $this->action($f['host'], $s, 'confirm')->assertForbidden(); $this->action($f['host'], $s, 'decline')->assertForbidden();
        $this->action($other, $s, 'revoke')->assertForbidden();
        $this->actingAsApiUser($f['rider'])->postJson('/api/mobility/pickup-previews/'.$f['preview'].'/selections', $this->selectBody(), ['Idempotency-Key' => 'wrong-rider'])->assertForbidden();
    }

    public function test_strict_fields_and_exact_acknowledgment_hash_and_version_required(): void
    {
        $f = $this->fixture();
        $this->actingAsApiUser($f['host'])->postJson('/api/mobility/pickup-previews/'.$f['preview'].'/selections', $this->selectBody() + ['fare' => 1], ['Idempotency-Key' => 'bad-select'])->assertStatus(422);
        $s = $this->select($f)->assertCreated()->json('data.selection');
        foreach ([['access_acknowledged' => false], ['terms_hash' => str_repeat('0', 64)], ['expected_version' => 2], ['lat' => 12.95]] as $change) {
            $this->actingAsApiUser($f['rider'])->postJson('/api/mobility/pickup-selections/'.$s['id'].'/confirm', array_replace($this->binding($s, true), $change), ['Idempotency-Key' => 'bad-'.Str::uuid()])->assertStatus(isset($change['access_acknowledged']) || isset($change['lat']) ? 422 : 409);
        }
        $this->assertSame(0, DB::table('mobility_pickup_selection_actions')->count());
    }

    public function test_selection_replay_cannot_cross_preview_or_supersede_newer_consent(): void
    {
        $f = $this->fixture(); $s = $this->select($f, key: 'select-once')->assertCreated()->json('data.selection');
        $this->select($f, key: 'select-once')->assertOk()->assertJsonPath('data.selection.id', $s['id']);
        $second = $this->select($f, 'ON_ROUTE_MEETING_POINT', 'select-new')->assertCreated()->json('data.selection');
        $this->assertSame(2, $second['version']);
        $this->select($f, key: 'select-once')->assertStatus(409);
        $this->assertSame('superseded', DB::table('mobility_pickup_selections')->where('id', $s['id'])->value('state'));
        $f2 = $this->fixtureForSameActors($f);
        $this->select($f2, key: 'select-new')->assertStatus(409);
        $this->assertSame(2, DB::table('mobility_pickup_selections')->count());
    }

    public function test_action_replay_is_bound_to_selection_and_kind_and_does_not_revive_revoked(): void
    {
        $f = $this->fixture(); $s = $this->select($f)->assertCreated()->json('data.selection');
        $first = $this->action($f['rider'], $s, 'confirm', 'one-action')->assertOk()->json('data.selection.rider_confirmed_at');
        $this->action($f['rider'], $s, 'confirm', 'one-action')->assertOk()->assertJsonPath('data.selection.rider_confirmed_at', $first);
        $this->action($f['rider'], $s, 'confirm', 'another-confirm')->assertOk()->assertJsonPath('data.selection.rider_confirmed_at', $first);
        $this->action($f['rider'], $s, 'revoke', 'one-action')->assertStatus(409);
        $this->action($f['host'], $s, 'revoke')->assertOk()->assertJsonPath('data.selection.state', 'revoked');
        $this->action($f['rider'], $s, 'confirm', 'one-action')->assertOk()->assertJsonPath('data.selection.state', 'revoked');
        $this->assertNull(DB::table('mobility_pickup_selections')->where('id', $s['id'])->value('active_intent_id'));
        $s2 = $this->select($f, key: 'second')->assertCreated()->json('data.selection');
        $this->action($f['rider'], $s2, 'confirm', 'one-action')->assertStatus(409);
        $this->assertSame('pending', DB::table('mobility_pickup_selections')->where('id', $s2['id'])->value('state'));
    }

    public function test_expired_selection_rejects_reads_confirms_and_retries_but_allows_revoke(): void
    {
        $f = $this->fixture(); $s = $this->select($f, key: 'expire')->assertCreated()->json('data.selection');
        DB::table('mobility_pickup_selections')->where('id', $s['id'])->update(['expires_at' => now()->subSecond()]);
        $this->actingAsApiUser($f['rider'])->getJson('/api/mobility/pickup-selections/'.$s['id'])->assertStatus(410);
        $this->action($f['rider'], $s, 'confirm')->assertStatus(410); $this->select($f, key: 'expire')->assertStatus(410);
        $this->action($f['rider'], $s, 'revoke')->assertOk()->assertJsonPath('data.selection.state', 'revoked');
    }

    public function test_all_source_revisions_and_graph_changes_fail_closed_without_consuming_actions(): void
    {
        foreach (['plan', 'intent', 'preview', 'graph'] as $change) {
            $f = $this->fixture(); $s = $this->select($f)->assertCreated()->json('data.selection');
            if ($change === 'plan') DB::table('mobility_pickup_plans')->where('id', $f['plan'])->increment('version');
            if ($change === 'intent') DB::table('mobility_pickup_intents')->where('id', $f['intent'])->increment('version');
            if ($change === 'preview') DB::table('mobility_pickup_previews')->where('id', $f['preview'])->increment('intent_version');
            if ($change === 'graph') config(['mobility_routing.foot_expected_data_version' => str_replace(str_repeat('d', 64), str_repeat('e', 64), $this->version('foot'))]);
            $this->action($f['rider'], $s, 'confirm')->assertStatus(409);
            $this->action($f['rider'], $s, 'revoke')->assertOk();
            config(['mobility_routing.foot_expected_data_version' => $this->version('foot')]);
        }
    }

    public function test_revocation_of_membership_and_host_approval_is_checked_before_retry(): void
    {
        foreach (['rider', 'host'] as $role) {
            $f = $this->fixture(); $s = $this->select($f)->assertCreated()->json('data.selection');
            $this->action($f['rider'], $s, 'confirm', 'confirmed-'.$role)->assertOk();
            if ($role === 'rider') DB::table('mobility_memberships')->where('user_id', $f['rider']->id)->update(['status' => 'suspended']);
            else DB::table('mobility_host_applications')->where('car_id', $f['car']->id)->update(['status' => 'rejected']);
            $this->action($f['rider'], $s, 'confirm', 'confirmed-'.$role)->assertForbidden();
        }
    }

    public function test_tampered_terms_or_preview_never_reaches_confirmation(): void
    {
        foreach (['terms', 'preview'] as $change) {
            $f = $this->fixture(); $s = $this->select($f)->assertCreated()->json('data.selection');
            if ($change === 'terms') DB::table('mobility_pickup_selections')->where('id', $s['id'])->update(['terms' => '{}']);
            else DB::table('mobility_pickup_previews')->where('id', $f['preview'])->update(['snapshot' => '{}']);
            $this->action($f['rider'], $s, 'confirm')->assertStatus(409);
        }
        $this->assertSame(0, DB::table('mobility_pickup_selection_actions')->count());
    }

    public function test_unavailable_null_wrong_graph_and_wrong_snap_options_cannot_be_selected(): void
    {
        foreach (['unavailable', 'null', 'graph', 'snap', 'geometry', 'endpoint'] as $change) {
            $f = $this->fixture(); $snapshot = json_decode(DB::table('mobility_pickup_previews')->where('id', $f['preview'])->value('snapshot'), true);
            if ($change === 'unavailable') $snapshot['direct']['available'] = false;
            if ($change === 'null') $snapshot['direct']['path'] = null;
            if ($change === 'graph') $snapshot['direct']['path']['data_version'] = $this->version('bike');
            if ($change === 'snap') $snapshot['direct']['vehicle_pickup_location'] = [77.8, 13.0];
            if ($change === 'geometry') $snapshot['direct']['path']['geometry'][0] = [77.8,13.0];
            if ($change === 'endpoint') { $snapshot['direct']['path']['geometry'][0] = [77.8,13.0]; $snapshot['direct']['path']['waypoints'][0]['location'] = [77.8,13.0]; }
            DB::table('mobility_pickup_previews')->where('id', $f['preview'])->update(['snapshot' => json_encode($snapshot)]);
            $this->select($f)->assertStatus(422);
        }
        $this->assertSame(0, DB::table('mobility_pickup_selections')->count());
    }

    public function test_walking_policy_is_required_and_total_access_cannot_exceed_intent_preference(): void
    {
        $legacy = $this->fixture();
        $snapshot = json_decode(DB::table('mobility_pickup_previews')->where('id', $legacy['preview'])->value('snapshot'), true);
        unset($snapshot['preferences']);
        DB::table('mobility_pickup_previews')->where('id', $legacy['preview'])->update(['snapshot' => json_encode($snapshot)]);
        $this->select($legacy, 'ON_ROUTE_MEETING_POINT')->assertStatus(409)->assertJsonPath('error.code', 'PICKUP_WALK_POLICY_MISSING');

        $overLimit = $this->fixture();
        $snapshot = json_decode(DB::table('mobility_pickup_previews')->where('id', $overLimit['preview'])->value('snapshot'), true);
        $snapshot['meeting']['total_access_meters'] = 501;
        DB::table('mobility_pickup_previews')->where('id', $overLimit['preview'])->update(['snapshot' => json_encode($snapshot)]);
        $this->select($overLimit, 'ON_ROUTE_MEETING_POINT')->assertStatus(409)->assertJsonPath('error.code', 'PICKUP_WALK_LIMIT_EXCEEDED');
        $this->assertSame(0, DB::table('mobility_pickup_selections')->count());
    }

    public function test_direct_selection_binds_vehicle_snap_to_route_and_geometric_pickup(): void
    {
        foreach (['path_snap', 'underreported_geometric_snap'] as $change) {
            $f = $this->fixture();
            $snapshot = json_decode(DB::table('mobility_pickup_previews')->where('id', $f['preview'])->value('snapshot'), true);
            if ($change === 'path_snap') {
                $snapshot['direct']['vehicle_snap_meters'] = 1;
            } else {
                $pickup = [77.551, 12.95];
                $snapshot['direct']['vehicle_pickup_location'] = $pickup;
                $snapshot['direct']['path']['geometry'][2] = $pickup;
                $snapshot['direct']['path']['waypoints'][1]['location'] = $pickup;
            }
            DB::table('mobility_pickup_previews')->where('id', $f['preview'])->update(['snapshot' => json_encode($snapshot)]);
            $this->select($f)->assertStatus(422)->assertJsonPath('error.code', 'MALFORMED_OPTION');
        }
        $this->assertSame(0, DB::table('mobility_pickup_selections')->count());
    }

    public function test_confirmation_rechecks_current_walking_preference_after_actor_selection(): void
    {
        $f = $this->fixture(); $s = $this->select($f, 'ON_ROUTE_MEETING_POINT')->assertCreated()->json('data.selection');
        DB::table('mobility_pickup_intents')->where('id', $f['intent'])->update(['max_walk_meters' => 1000]);
        $this->action($f['rider'], $s, 'confirm')->assertStatus(409)->assertJsonPath('error.code', 'PICKUP_WALK_PREFERENCE_MISMATCH');
        $this->assertSame(0, DB::table('mobility_pickup_selection_actions')->count());
    }

    public function test_confirmed_consent_cannot_decline_and_new_choice_requires_new_confirmation(): void
    {
        $f = $this->fixture(); $s = $this->select($f)->assertCreated()->json('data.selection');
        $this->action($f['rider'], $s, 'confirm')->assertOk(); $this->action($f['rider'], $s, 'decline')->assertStatus(409);
        $s2 = $this->select($f, 'ON_ROUTE_MEETING_POINT')->assertCreated()->json('data.selection');
        $this->assertSame('pending', $s2['state']); $this->assertNull($s2['rider_confirmed_at']);
        $this->action($f['rider'], $s, 'confirm', 'new-old-key')->assertStatus(409);
        $this->action($f['rider'], $s2, 'decline')->assertOk();
        $this->assertSame(0, DB::table('mobility_pickup_selections')->whereNotNull('active_intent_id')->count());
    }

    private function select(array $f, string $choice = 'DIRECT_RIDER_PICKUP', ?string $key = null)
    {
        return $this->actingAsApiUser($f['host'])->postJson('/api/mobility/pickup-previews/'.$f['preview'].'/selections', array_replace($this->selectBody(), ['choice' => $choice]), ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }
    private function selectBody(): array { return ['choice' => 'DIRECT_RIDER_PICKUP', 'expected_plan_version' => 1, 'expected_intent_version' => 1]; }
    private function binding(array $s, bool $confirm = false): array { return ['expected_version' => $s['version'], 'terms_hash' => $s['terms_hash']] + ($confirm ? ['access_acknowledged' => true] : []); }
    private function action(User $actor, array $s, string $kind, ?string $key = null) { return $this->actingAsApiUser($actor)->postJson('/api/mobility/pickup-selections/'.$s['id'].'/'.$kind, $this->binding($s, $kind === 'confirm'), ['Idempotency-Key' => $key ?? (string) Str::uuid()]); }
    private function fixtureForSameActors(array $f): array { return $this->fixture('car', 'scheduled', $f); }
    private function fixture(string $mode = 'car', string $rideMode = 'scheduled', ?array $reuse = null): array
    {
        $host = $reuse['host'] ?? $this->member(); $rider = $reuse['rider'] ?? $this->member(); $car = $reuse['car'] ?? Car::factory()->withCatalog()->create(['user_id' => $host->id]);
        if (! $reuse) DB::table('mobility_host_applications')->insert(['user_id' => $host->id, 'car_id' => $car->id, 'vehicle_type' => $mode, 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $plan = (string) Str::uuid(); $intent = (string) Str::uuid(); $preview = (string) Str::uuid();
        $points = [['lat' => 12.90, 'lng' => 77.50], ['lat' => 13.00, 'lng' => 77.60]]; $origin = ['lat' => 12.95, 'lng' => 77.55];
        $vehicle = $this->path($mode, [[77.5,12.9],[77.55,12.95],[77.6,13.0]]); $foot = $this->path('foot', [[77.55,12.95],[77.55,12.95]]);
        $direct = ['available' => true, 'choice' => 'DIRECT_RIDER_PICKUP', 'vehicle_pickup_location' => [77.55,12.95], 'vehicle_snap_meters' => 0, 'path' => $vehicle, 'detour' => ['added_distance_meters' => 100, 'added_duration_seconds' => 10], 'access_verified' => false, 'requires_pickup_confirmation' => true];
        $meeting = ['available' => true, 'choice' => 'ON_ROUTE_MEETING_POINT', 'vehicle_pickup_location' => [77.55,12.95], 'pedestrian_pickup_location' => [77.55,12.95], 'vehicle_path' => $vehicle, 'walking_path' => $foot, 'walking_distance_meters' => 100, 'walking_duration_seconds' => 120, 'unmapped_access_gap_meters' => 0, 'handoff_gap_meters' => 0.0, 'total_access_meters' => 100, 'detour' => ['added_distance_meters' => 100, 'added_duration_seconds' => 10], 'access_verified' => false, 'requires_pickup_confirmation' => true];
        DB::table('mobility_pickup_plans')->insert(['id' => $plan, 'user_id' => $host->id, 'college_code' => 'CAMPUS-BLR', 'car_id' => $car->id, 'vehicle_type' => $mode, 'ride_mode' => $rideMode, 'points' => json_encode($points), 'path_snapshot' => json_encode($this->path($mode, [[77.5,12.9],[77.6,13.0]])), 'path_data_version' => $this->version($mode), 'version' => 1, 'create_key_hash' => hash('sha256', $plan), 'request_hash' => hash('sha256', $plan), 'invite_hash' => hash('sha256', $plan), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mobility_pickup_intents')->insert(['id' => $intent, 'plan_id' => $plan, 'rider_id' => $rider->id, 'driver_id' => $host->id, 'plan_version' => 1, 'origin' => json_encode($origin), 'max_walk_meters' => 500, 'version' => 1, 'create_key_hash' => hash('sha256', $intent), 'request_hash' => hash('sha256', $intent), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mobility_pickup_previews')->insert(['id' => $preview, 'plan_id' => $plan, 'intent_id' => $intent, 'plan_version' => 1, 'intent_version' => 1, 'snapshot' => json_encode(['direct' => $direct, 'meeting' => $meeting, 'vehicle_data_version' => $this->version($mode), 'foot_data_version' => $this->version('foot'), 'preferences' => ['max_walk_meters' => 500]]), 'create_key_hash' => hash('sha256', $preview), 'request_hash' => hash('sha256', $preview), 'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now()]);
        return compact('host', 'rider', 'car', 'plan', 'intent', 'preview');
    }
    private function path(string $mode, array $locations): array
    {
        $legs = array_fill(0, count($locations) - 1, ['distance_meters' => 100, 'duration_seconds' => 120]);
        return ['provider' => 'osrm', 'profile' => 'driving', 'data_version' => $this->version($mode), 'distance_meters' => 100 * count($legs), 'duration_seconds' => 120 * count($legs), 'geometry' => $locations, 'waypoints' => array_map(fn ($location) => ['location' => $location, 'distance_meters' => 0], $locations), 'legs' => $legs];
    }
    private function member(): User { $u = User::factory()->create(['active' => true, 'banned' => false]); DB::table('mobility_memberships')->insert(['user_id' => $u->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]); return $u; }
    private function version(string $mode): string { return $mode.'|crop_sha256='.str_repeat($mode === 'foot' ? 'c' : 'a', 64).'|profile_sha256='.str_repeat($mode === 'foot' ? 'd' : 'b', 64); }
}
