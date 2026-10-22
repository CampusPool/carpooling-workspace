<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Trust\CohortPolicy;
use STS\Mobility\Trust\DriverOnboardingPolicy;

use STS\Models\Car;
use STS\Models\User;
use Tests\TestCase;

final class MobilityDriverProfileBridgeTest extends TestCase
{
    private string $college = 'POLICY-'.'TEST';
    private array $prior = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['MOBILITY_COHORT_REQUIRED','MOBILITY_COLLEGE_CODE','MOBILITY_DRIVER_REQUIRED_DOCUMENTS'] as $key) $this->prior[$key] = getenv($key);
        putenv('MOBILITY_COHORT_REQUIRED=true');
        putenv('MOBILITY_COLLEGE_CODE='.$this->college);
        putenv('MOBILITY_DRIVER_REQUIRED_DOCUMENTS=driver_license,profile_photo,rc,insurance');
    }

    protected function tearDown(): void { foreach ($this->prior as $key=>$value) putenv($value === false ? $key : $key.'='.$value); parent::tearDown(); }

    public function test_actual_approved_onboarding_fixture_bridges_profile_completion_without_legacy_identity_fields(): void
    {
        [$user, $car, $onboarding] = $this->approvedFixture();

        $this->assertFalse((bool) $user->identity_validated);
        $this->assertFalse((bool) $user->driver_is_verified);
        $this->assertTrue(DriverOnboardingPolicy::canonicalProfileComplete($user->id, $car->id));
    }

    public function test_expired_required_document_or_different_owned_car_cannot_bridge_profile_completion(): void
    {
        [$user, $car] = $this->approvedFixture(['insurance' => now()->subDay()->toDateString()]);
        $other = Car::factory()->withCatalog()->create(['user_id' => $user->id]);

        $this->assertFalse(DriverOnboardingPolicy::canonicalProfileComplete($user->id, $car->id));
        $this->assertFalse(DriverOnboardingPolicy::canonicalProfileComplete($user->id, $other->id));
    }

    public function test_trusted_canonical_creation_uses_manual_profile_without_inventing_identity_fields(): void
    {
        [$user, $car] = $this->approvedFixture();
        $user->forceFill(['image'=>null,'description'=>null,'nro_doc'=>null,'mobile_phone'=>null])->save();
        $id = (string) \Illuminate\Support\Str::uuid();
        $points = [
            ['address'=>'Private pickup','lat'=>12.9716,'lng'=>77.5946,'json_address'=>['private_quote_id'=>$id,'role'=>'pickup']],
            ['address'=>'Private dropoff','lat'=>12.9816,'lng'=>77.6046,'json_address'=>['private_quote_id'=>$id,'role'=>'dropoff']],
        ];
        $data = ['is_passenger'=>0,'car_id'=>$car->id,'state'=>\STS\Models\Trip::STATE_READY,
            'from_town'=>'Private pickup','to_town'=>'Private dropoff','punto_partida'=>'Private pickup','punto_llegada'=>'Private dropoff',
            'trip_date'=>now()->addDay()->format('Y-m-d H:i:s'),'total_seats'=>1,'friendship_type_id'=>2,
            'estimated_time'=>'00:05:00','distance'=>1.5,'co2'=>0,'description'=>'','points'=>$points,'seat_price_cents'=>3200];
        $manager = app(\STS\Services\Logic\TripsManager::class);
        $this->assertNull($manager->create($user,$data), 'Legacy profile checks must still apply without a trusted context.');
        \STS\Mobility\Routing\TrustedRouteContext::push($points,['endpoint_schema'=>'actor-private-v1','private_quote_id'=>$id,'points'=>$points,'distance_meters'=>1500,'duration_seconds'=>300,'seat_price_minor'=>3200]);
        try { $trip = $manager->create($user,$data); }
        finally { \STS\Mobility\Routing\TrustedRouteContext::reset(); }
        $this->assertNotNull($trip,json_encode($manager->getErrors()));
        $this->assertNull($user->fresh()->nro_doc);
        $this->assertFalse((bool)$user->fresh()->identity_validated);
        $this->assertFalse((bool)$user->fresh()->driver_is_verified);
    }

    private function approvedFixture(array $expiryOverrides = []): array
    {
        $user = User::factory()->create(['active' => true, 'banned' => false, 'identity_validated' => false, 'driver_is_verified' => false]);
        $car = Car::factory()->withCatalog()->create(['user_id' => $user->id]);
        DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => $this->college, 'affiliation'=>'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $applicationId = DB::table('mobility_host_applications')->insertGetId(['user_id' => $user->id, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $onboardingId = DB::table('mobility_driver_onboardings')->insertGetId(['user_id' => $user->id, 'host_application_id' => $applicationId, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'profile_display_name' => 'Synthetic approved driver', 'profile_phone' => null, 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (DriverOnboardingPolicy::requiredTypes() as $type) DB::table('mobility_driver_documents')->insert(['onboarding_id' => $onboardingId, 'document_type' => $type, 'version' => 1, 'storage_path' => 'synthetic/'.$type, 'mime_type' => 'application/pdf', 'byte_size' => 1, 'expires_at' => $expiryOverrides[$type] ?? now()->addYear()->toDateString(), 'status' => 'accepted', 'submitted_by' => $user->id, 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $car, $onboardingId];
    }
}
