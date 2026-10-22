<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Models\User;
use Tests\TestCase;

final class MobilityCohortHttpTest extends TestCase
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

    public function test_application_is_pending_idempotent_and_operator_decision_is_versioned(): void
    {
        $applicant = User::factory()->create(['active' => true, 'banned' => false]);
        $admin = User::factory()->create(['active' => true, 'banned' => false, 'is_admin' => true]);
        $path = '/api/mobility/membership/apply';
        $first = $this->actingAsApiUser($applicant)->postJson($path, ['college_code' => 'CAMPUS-BLR', 'affiliation' => 'student'])->assertCreated();
        $first->assertJsonPath('status', 'pending')->assertJsonPath('version', 1);
        $this->actingAsApiUser($applicant)->postJson($path, ['college_code' => 'CAMPUS-BLR', 'affiliation' => 'student'])->assertOk()->assertJsonPath('membership_id', $first->json('membership_id'));
        $this->actingAsApiUser($applicant)->postJson($path, ['college_code' => 'CAMPUS-BLR', 'affiliation' => 'staff'])->assertStatus(409)->assertJsonPath('error.code', 'MEMBERSHIP_CONFLICT');
        $this->actingAsApiUser($admin)->postJson('/api/mobility/operator/memberships/'.$applicant->id.'/decision', ['expected_version' => 1, 'status' => 'approved', 'reason' => 'Roster checked'])->assertOk()->assertJsonPath('version', 2);
        $this->actingAsApiUser($admin)->postJson('/api/mobility/operator/memberships/'.$applicant->id.'/decision', ['expected_version' => 1, 'status' => 'suspended', 'reason' => 'stale review'])->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->assertDatabaseHas('mobility_membership_audits', ['user_id' => $applicant->id, 'new_status' => 'approved', 'version' => 2]);
    }

    public function test_self_review_and_wrong_college_fail_closed(): void
    {
        $user = User::factory()->create(['active' => true, 'banned' => false, 'is_admin' => true]);
        $this->actingAsApiUser($user)->postJson('/api/mobility/membership/apply', ['college_code' => 'OTHER', 'affiliation' => 'student'])->assertStatus(422);
        $this->actingAsApiUser($user)->postJson('/api/mobility/membership/apply', ['college_code' => 'CAMPUS-BLR', 'affiliation' => 'student'])->assertCreated();
        $this->actingAsApiUser($user)->postJson('/api/mobility/operator/memberships/'.$user->id.'/decision', ['expected_version' => 1, 'status' => 'approved', 'reason' => 'self review'])->assertStatus(403)->assertJsonPath('error.code', 'SELF_REVIEW_FORBIDDEN');
    }

    public function test_membership_me_does_not_expose_review_reason(): void
    {
        $user = User::factory()->create(['active' => true, 'banned' => false]);
        DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'rejected', 'version' => 2, 'review_reason' => 'private operator note', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsApiUser($user)->getJson('/api/mobility/membership/me')->assertOk()->assertJsonPath('membership.status', 'rejected')->assertJsonMissingPath('membership.review_reason');
    }
}
