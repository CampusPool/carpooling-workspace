<?php

namespace Tests\Feature\Mobility;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use STS\Models\Car;
use STS\Models\User;
use STS\Mobility\Trust\CohortPolicy;
use Tests\TestCase;

final class DriverOnboardingHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('MOBILITY_COHORT_REQUIRED=true');
        putenv('MOBILITY_COLLEGE_CODE=CAMPUS-BLR');
        putenv('MOBILITY_DRIVER_DOCUMENTS_REQUIRED=true');
        Storage::fake('local');

    }

    protected function tearDown(): void
    {
        putenv('MOBILITY_COHORT_REQUIRED');
        putenv('MOBILITY_COLLEGE_CODE');
        putenv('MOBILITY_DRIVER_DOCUMENTS_REQUIRED');
        parent::tearDown();
    }

    public function test_submission_requires_current_checklist_and_manual_approval_controls_publish(): void
    {
        $applicant = User::factory()->create(['active' => true, 'banned' => false]);
        $operator = User::factory()->create(['active' => true, 'banned' => false, 'is_admin' => true]);
        DB::table('mobility_memberships')->insert(['user_id' => $applicant->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $car = Car::factory()->withCatalog()->create(['user_id' => $applicant->id]);
        $created = $this->actingAsApiUser($applicant)->postJson('/api/mobility/driver-onboarding', ['car_id' => $car->id, 'vehicle_type' => 'car', 'profile_display_name' => 'Synthetic Driver'])->assertCreated();
        $id = $created->json('onboarding_id');
        $this->actingAsApiUser($applicant)->post('/api/mobility/driver-onboarding/'.$id.'/documents', ['document_type' => 'rc', 'document' => UploadedFile::fake()->createWithContent('stale.pdf', "%PDF-synthetic\n"), 'expires_at' => now()->addYear()->toDateString(), 'expected_version' => 99])->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $types = ['driver_license', 'profile_photo', 'rc', 'insurance', 'permit', 'fitness', 'puc', 'identity_or_address', 'ownership_noc'];
        $onboardingVersion = 1;
        foreach ($types as $type) {
            $payload = ['document_type' => $type, 'document' => UploadedFile::fake()->createWithContent($type.'.pdf', "%PDF-synthetic\n"), 'expected_version' => $onboardingVersion];
            if (in_array($type, ['driver_license', 'rc', 'insurance', 'permit', 'fitness', 'puc'], true)) $payload['expires_at'] = now()->addYear()->toDateString();
            $onboardingVersion = $this->actingAsApiUser($applicant)->post('/api/mobility/driver-onboarding/'.$id.'/documents', $payload)->assertCreated()->json('onboarding_version');
        }
        $onboardingVersion = $this->actingAsApiUser($applicant)->postJson('/api/mobility/driver-onboarding/'.$id.'/submit', ['expected_version' => $onboardingVersion])->assertOk()->json('version');
        $inactive = User::factory()->create(['active' => false, 'banned' => false, 'is_admin' => true]);
        $this->actingAsApiUser($inactive)->postJson('/api/mobility/operator/driver-onboarding/'.$id.'/review', ['expected_version' => $onboardingVersion, 'status' => 'approved', 'reason' => 'inactive'])->assertUnauthorized();
        $this->actingAsApiUser($operator)->postJson('/api/mobility/operator/driver-onboarding/'.$id.'/review', ['expected_version' => $onboardingVersion, 'status' => 'needs_resubmission', 'document_type' => 'insurance', 'reason' => 'Upload a clearer synthetic image'])->assertOk();
        $this->actingAsApiUser($applicant)->getJson('/api/mobility/driver-onboarding/me')->assertJsonPath('data.0.review_reason', 'Upload a clearer synthetic image')->assertJsonPath('data.0.checklist.3.review_reason', 'Upload a clearer synthetic image');
        $onboardingVersion = $this->actingAsApiUser($applicant)->post('/api/mobility/driver-onboarding/'.$id.'/documents', ['document_type' => 'insurance', 'document' => UploadedFile::fake()->createWithContent('insurance-new.pdf', "%PDF-synthetic\n"), 'expires_at' => now()->addYear()->toDateString(), 'expected_version' => $onboardingVersion + 1])->assertCreated()->json('onboarding_version');
        $onboardingVersion = $this->actingAsApiUser($applicant)->postJson('/api/mobility/driver-onboarding/'.$id.'/submit', ['expected_version' => $onboardingVersion])->assertOk()->json('version');
        $this->actingAsApiUser($operator)->postJson('/api/mobility/operator/driver-onboarding/'.$id.'/review', ['expected_version' => $onboardingVersion, 'status' => 'approved', 'reason' => 'Synthetic checklist inspected'])->assertOk();
        $this->assertTrue(CohortPolicy::hostApproved($applicant->id, $car->id, 'car'));
        $this->assertDatabaseHas('mobility_driver_onboarding_audits', ['onboarding_id' => $id, 'new_status' => 'approved']);
        $this->actingAsApiUser($operator)->postJson('/api/mobility/operator/host-applications/'.DB::table('mobility_host_applications')->where('user_id', $applicant->id)->value('id').'/decision', ['expected_version' => 2, 'status' => 'rejected', 'reason' => 'legacy bypass'])->assertStatus(409)->assertJsonPath('error.code', 'DRIVER_ONBOARDING_REQUIRED');
    }

    public function test_legacy_host_application_is_closed_when_document_workflow_is_required(): void
    {
        $applicant = User::factory()->create(['active' => true, 'banned' => false]);
        DB::table('mobility_memberships')->insert(['user_id' => $applicant->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $car = Car::factory()->withCatalog()->create(['user_id' => $applicant->id]);
        $this->actingAsApiUser($applicant)->postJson('/api/mobility/host-applications', ['car_id' => $car->id, 'vehicle_type' => 'car'])
            ->assertStatus(409)->assertJsonPath('error.code', 'DRIVER_ONBOARDING_REQUIRED');
    }
}
