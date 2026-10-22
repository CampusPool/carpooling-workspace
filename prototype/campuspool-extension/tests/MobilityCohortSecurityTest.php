<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Pilot\PilotDemoRoutes;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

final class MobilityCohortSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('MOBILITY_COHORT_REQUIRED=true');
        putenv('MOBILITY_COLLEGE_CODE=CAMPUS-BLR');
    }

    protected function tearDown(): void
    {
        putenv('MOBILITY_COHORT_REQUIRED');
        putenv('MOBILITY_COLLEGE_CODE');
        parent::tearDown();
    }

    public function test_anonymous_unconfigured_banned_admin_and_claim_arrays_fail_closed(): void
    {
        $this->getJson('/api/trips')->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_RESTRICTED');
        $user = User::factory()->create(['active' => true, 'banned' => false]);
        $this->actingAsApiUser($user)->postJson('/api/mobility/membership/apply', ['college_code' => [], 'affiliation' => []])->assertStatus(422)->assertJsonPath('error.code', 'APPLICATION_INPUT_INVALID');
        putenv('MOBILITY_COLLEGE_CODE');
        $this->actingAsApiUser($user)->getJson('/api/mobility/membership/me')->assertStatus(503)->assertJsonPath('error.code', 'COHORT_NOT_CONFIGURED');
        putenv('MOBILITY_COLLEGE_CODE=CAMPUS-BLR');
        $admin = User::factory()->create(['active' => true, 'banned' => true, 'is_admin' => true]);
        $this->actingAsApiUser($admin)->getJson('/api/mobility/operator/memberships')->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_RESTRICTED');
    }

    public function test_reviewed_membership_for_wrong_college_cannot_discover_and_decision_arrays_are_rejected(): void
    {
        $user = User::factory()->create(['active' => true, 'banned' => false]);
        DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => 'OTHER-COLLEGE', 'affiliation' => 'student', 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsApiUser($user)->getJson('/api/trips')->assertForbidden()->assertJsonPath('error.code', 'MEMBERSHIP_REQUIRED');
        $admin = User::factory()->create(['active' => true, 'banned' => false, 'is_admin' => true]);
        $this->actingAsApiUser($admin)->postJson('/api/mobility/operator/memberships/'.$user->id.'/decision', ['expected_version' => [], 'status' => [], 'reason' => []])->assertStatus(422)->assertJsonPath('error.code', 'DECISION_INVALID');
    }

    public function test_host_application_requires_owned_vehicle_and_type_replay_conflicts(): void
    {
        $host = $this->approvedMember();
        $other = User::factory()->create(['active' => true, 'banned' => false]);
        $otherCar = Car::factory()->withCatalog()->create(['user_id' => $other->id]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/host-applications', ['car_id' => $otherCar->id, 'vehicle_type' => 'car'])->assertForbidden()->assertJsonPath('error.code', 'VEHICLE_NOT_ELIGIBLE');
        $car = Car::factory()->withCatalog()->create(['user_id' => $host->id]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/host-applications', ['car_id' => $car->id, 'vehicle_type' => 'car'])->assertCreated();
        $this->actingAsApiUser($host)->postJson('/api/mobility/host-applications', ['car_id' => $car->id, 'vehicle_type' => 'bike'])->assertStatus(409)->assertJsonPath('error.code', 'HOST_APPLICATION_CONFLICT');
    }

    public function test_vehicle_edit_invalidates_approved_fingerprint_and_canonical_publish_checks_bike_capacity(): void
    {
        $host = $this->approvedMember(['identity_validated' => true, 'nro_doc' => 'SEC123', 'mobile_phone' => '0000000000']);
        $car = Car::factory()->withCatalog()->create(['user_id' => $host->id]);
        $application = DB::table('mobility_host_applications')->insertGetId(['user_id' => $host->id, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue(CohortPolicy::hostApproved($host->id, $car->id, 'car'));
        $car->description = 'edited after approval';
        $car->save();
        $this->assertFalse(CohortPolicy::hostApproved($host->id, $car->id, 'car'));
        DB::table('mobility_host_applications')->where('id', $application)->update(['vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'vehicle_type' => 'bike']);
        PilotDemoRoutes::seed();
        $payload = ['is_passenger' => 0, 'from_town' => 'Synthetic Stop A', 'to_town' => 'Synthetic Stop B', 'punto_partida' => 'Synthetic Stop A', 'punto_llegada' => 'Synthetic Stop B', 'trip_date' => now('Asia/Kolkata')->addDays(2)->format('Y-m-d H:i:s'), 'total_seats' => 2, 'friendship_type_id' => 2, 'estimated_time' => '00:15', 'distance' => 3.5, 'co2' => 0, 'description' => 'security', 'points' => PilotDemoRoutes::points('demo-a-b'), 'car_id' => $car->id, 'vehicle_type' => 'bike'];
        $this->actingAsApiUser($host)->postJson('/api/trips', $payload)->assertStatus(422)->assertJsonPath('error.code', 'BIKE_CAPACITY_EXCEEDED');
    }

    public function test_legacy_publish_cannot_bypass_trusted_quote_with_an_approved_vehicle(): void
    {
        $host = $this->approvedMember(['identity_validated' => true, 'nro_doc' => 'SEC456', 'mobile_phone' => '0000000000']);
        $car = Car::factory()->withCatalog()->create(['user_id' => $host->id]);
        DB::table('mobility_host_applications')->insert(['user_id' => $host->id, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);
        PilotDemoRoutes::seed();
        $payload = ['is_passenger' => 0, 'from_town' => 'Synthetic Stop A', 'to_town' => 'Synthetic Stop B', 'punto_partida' => 'Synthetic Stop A', 'punto_llegada' => 'Synthetic Stop B', 'trip_date' => now('Asia/Kolkata')->addDays(2)->format('Y-m-d H:i:s'), 'total_seats' => 1, 'friendship_type_id' => 2, 'estimated_time' => '00:15', 'distance' => 3.5, 'co2' => 0, 'description' => 'security', 'points' => PilotDemoRoutes::points('demo-a-b'), 'car_id' => $car->id, 'vehicle_type' => 'car'];
        $this->actingAsApiUser($host)->postJson('/api/trips', $payload)->assertStatus(409)->assertJsonPath('error.code', 'TRUSTED_ROUTE_QUOTE_REQUIRED');
        $this->assertDatabaseMissing('trips', ['user_id' => $host->id, 'car_id' => $car->id]);
    }

    public function test_legacy_object_reads_and_live_share_creation_are_scoped(): void
    {
        $owner = $this->approvedMember();
        $other = $this->approvedMember();
        $trip = Trip::factory()->create(['user_id' => $owner->id]);
        $this->actingAsApiUser($other)->getJson('/api/trips/'.$trip->id.'/requests')->assertForbidden()->assertJsonPath('error.code', 'TRIP_FORBIDDEN');
        $this->actingAsApiUser($other)->getJson('/api/trips/'.$trip->id.'/passengers')->assertForbidden()->assertJsonPath('error.code', 'TRIP_FORBIDDEN');
        $this->actingAsApiUser($owner)->postJson('/api/trips/'.$trip->id.'/live-share/start')->assertForbidden()->assertJsonPath('error.code', 'LIVE_SHARE_DISABLED');
    }

    public function test_membership_decision_matrix_rejects_same_state_and_rejected_reactivation(): void
    {
        $applicant = User::factory()->create(['active' => true, 'banned' => false]);
        $admin = User::factory()->create(['active' => true, 'banned' => false, 'is_admin' => true]);
        DB::table('mobility_memberships')->insert(['user_id' => $applicant->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'pending', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsApiUser($admin)->postJson('/api/mobility/operator/memberships/'.$applicant->id.'/decision', ['expected_version' => 1, 'status' => 'approved', 'reason' => 'review'])->assertOk();
        $this->actingAsApiUser($admin)->postJson('/api/mobility/operator/memberships/'.$applicant->id.'/decision', ['expected_version' => 2, 'status' => 'approved', 'reason' => 'duplicate'])->assertStatus(422)->assertJsonPath('error.code', 'DECISION_INVALID');
        $this->actingAsApiUser($admin)->postJson('/api/mobility/operator/memberships/'.$applicant->id.'/decision', ['expected_version' => 2, 'status' => 'suspended', 'reason' => 'pause'])->assertOk();
        $this->actingAsApiUser($admin)->postJson('/api/mobility/operator/memberships/'.$applicant->id.'/decision', ['expected_version' => 3, 'status' => 'approved', 'reason' => 'return'])->assertOk();
    }

    public function test_host_application_replay_has_one_audit_row_and_decision_is_college_isolated(): void
    {
        $host = $this->approvedMember();
        $admin = User::factory()->create(['active' => true, 'banned' => false, 'is_admin' => true]);
        $car = Car::factory()->withCatalog()->create(['user_id' => $host->id]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/host-applications', ['car_id' => $car->id, 'vehicle_type' => 'car'])->assertCreated();
        $this->actingAsApiUser($host)->postJson('/api/mobility/host-applications', ['car_id' => $car->id, 'vehicle_type' => 'car'])->assertOk();
        $id = DB::table('mobility_host_applications')->where('user_id', $host->id)->value('id');
        $this->assertSame(1, DB::table('mobility_host_application_audits')->where('application_id', $id)->count());
        DB::table('mobility_memberships')->where('user_id', $host->id)->update(['college_code' => 'OTHER-COLLEGE']);
        $this->actingAsApiUser($admin)->postJson('/api/mobility/operator/host-applications/'.$id.'/decision', ['expected_version' => 1, 'status' => 'approved', 'reason' => 'wrong college'])->assertStatus(403)->assertJsonPath('error.code', 'MEMBERSHIP_REQUIRED');
    }

    public function test_banned_or_inactive_membership_target_cannot_be_approved(): void
    {
        $admin = User::factory()->create(['active' => true, 'banned' => false, 'is_admin' => true]);
        foreach ([['active' => false, 'banned' => false], ['active' => true, 'banned' => true]] as $attrs) {
            $target = User::factory()->create($attrs);
            DB::table('mobility_memberships')->insert(['user_id' => $target->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'pending', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $this->actingAsApiUser($admin)->postJson('/api/mobility/operator/memberships/'.$target->id.'/decision', ['expected_version' => 1, 'status' => 'approved', 'reason' => 'restricted target'])->assertStatus(403)->assertJsonPath('error.code', 'TARGET_RESTRICTED');
        }
    }

    private function approvedMember(array $extra = []): User
    {
        $user = User::factory()->create(array_merge(['active' => true, 'banned' => false], $extra));
        DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }
}
