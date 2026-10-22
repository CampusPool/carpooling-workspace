<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Models\Car;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

final class MobilityCohortGuardTest extends TestCase
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

    public function test_pending_member_cannot_discover_or_request_and_suspended_rider_can_cancel(): void
    {
        $pending = User::factory()->create(['active' => true, 'banned' => false]);
        $this->actingAsApiUser($pending)->getJson('/api/trips')->assertForbidden()->assertJsonPath('error.code', 'MEMBERSHIP_REQUIRED');
        $owner = User::factory()->create(['active' => true, 'banned' => false]);
        $trip = Trip::factory()->create(['user_id' => $owner->id]);
        $booking = Passenger::factory()->create(['trip_id' => $trip->id, 'user_id' => $pending->id, 'request_state' => Passenger::STATE_ACCEPTED]);
        DB::table('mobility_memberships')->insert(['user_id' => $pending->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'suspended', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsApiUser($pending)->postJson('/api/trips/'.$trip->id.'/requests/'.$pending->id.'/cancel')->assertOk();
        $this->assertSame(Passenger::STATE_CANCELED, $booking->fresh()->request_state);
    }

    public function test_banned_user_and_public_live_link_are_denied(): void
    {
        $banned = User::factory()->create(['active' => true, 'banned' => true]);
        $this->actingAsApiUser($banned)->getJson('/api/trips')->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_RESTRICTED');
        $this->getJson('/api/live/anything')->assertForbidden()->assertJsonPath('error.code', 'PUBLIC_LIVE_LINK_DISABLED');
    }

    public function test_direct_legacy_repository_cannot_bypass_trusted_quote_admission(): void
    {
        $rider = User::factory()->create(['active' => true]);
        $trip = Trip::factory()->create();
        $this->expectException(\STS\Mobility\Segments\SegmentException::class);
        $this->expectExceptionMessage('TRUSTED_ROUTE_QUOTE_REQUIRED');
        app(\STS\Repository\PassengersRepository::class)->newRequest($trip->id, $rider);
    }

    public function test_host_accept_requires_approved_membership_and_host_application(): void
    {
        $host = User::factory()->create(['active' => true, 'banned' => false]);
        $rider = User::factory()->create(['active' => true, 'banned' => false]);
        DB::table('mobility_memberships')->insert(['user_id' => $host->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mobility_memberships')->insert(['user_id' => $rider->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $car = Car::factory()->withCatalog()->create(['user_id' => $host->id]);
        $trip = Trip::factory()->create(['user_id' => $host->id, 'car_id' => $car->id, 'total_seats' => 1]);
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$trip->id.'/requests')->assertForbidden()->assertJsonPath('error.code', 'HOST_APPROVAL_REQUIRED');
        // A pending request may predate an operator's suspension of the host.
        Passenger::factory()->create(['trip_id' => $trip->id, 'user_id' => $rider->id, 'request_state' => Passenger::STATE_PENDING]);
        $this->actingAsApiUser($host)->postJson('/api/trips/'.$trip->id.'/requests/'.$rider->id.'/accept')->assertForbidden()->assertJsonPath('error.code', 'HOST_APPROVAL_REQUIRED');
        DB::table('mobility_host_applications')->insert(['user_id' => $host->id, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => \STS\Mobility\Trust\CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsApiUser($host)->postJson('/api/trips/'.$trip->id.'/requests/'.$rider->id.'/accept')->assertStatus(409)->assertJsonPath('error.code', 'TRUSTED_ROUTE_QUOTE_REQUIRED');
    }
}
