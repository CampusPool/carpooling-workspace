<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

final class MobilityCohortAdmissionTest extends TestCase
{
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['MOBILITY_COHORT_REQUIRED' => 'true', 'MOBILITY_COLLEGE_CODE' => 'CAMPUS-BLR'] as $key => $value) {
            $this->previous[$key] = getenv($key);
            putenv($key.'='.$value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
        parent::tearDown();
    }

    public function test_legacy_admission_requires_quote_but_existing_accepted_rider_can_recover_after_suspension(): void
    {
        [$host, $car, $trip] = $this->hostTrip();
        $rider = $this->member();
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$trip->id.'/requests')->assertStatus(409)->assertJsonPath('error.code', 'TRUSTED_ROUTE_QUOTE_REQUIRED');
        // Historical accepted obligations are retained, including cancellation after suspension.
        Passenger::factory()->create(['trip_id' => $trip->id, 'user_id' => $rider->id, 'request_state' => Passenger::STATE_ACCEPTED]);
        $this->assertDatabaseHas('trip_passengers', ['trip_id' => $trip->id, 'user_id' => $rider->id, 'request_state' => Passenger::STATE_ACCEPTED]);
        DB::table('mobility_memberships')->where('user_id', $rider->id)->update(['status' => 'suspended']);
        $this->actingAsApiUser($rider)->getJson('/api/users/my-trips')->assertOk();
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$trip->id.'/requests/'.$rider->id.'/cancel')->assertOk();
        $this->assertDatabaseHas('trip_passengers', ['trip_id' => $trip->id, 'user_id' => $rider->id, 'request_state' => Passenger::STATE_CANCELED]);
    }

    public function test_autoaccept_does_not_admit_a_rider_for_a_suspended_or_unapproved_host(): void
    {
        [$host, $car, $trip] = $this->hostTrip();
        $host->forceFill(['autoaccept_requests' => true])->save();
        $rider = $this->member();
        DB::table('mobility_host_applications')->where('user_id', $host->id)->update(['status' => 'suspended']);
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$trip->id.'/requests')->assertForbidden()->assertJsonPath('error.code', 'HOST_APPROVAL_REQUIRED');
        $this->assertDatabaseMissing('trip_passengers', ['trip_id' => $trip->id, 'user_id' => $rider->id]);
    }

    public function test_banned_rider_cannot_be_accepted_despite_an_old_approved_membership(): void
    {
        [$host, $car, $trip] = $this->hostTrip();
        $rider = $this->member();
        Passenger::factory()->create(['trip_id' => $trip->id, 'user_id' => $rider->id, 'request_state' => Passenger::STATE_PENDING]);
        $rider->forceFill(['banned' => true])->save();
        $this->actingAsApiUser($host)->postJson('/api/trips/'.$trip->id.'/requests/'.$rider->id.'/accept')->assertForbidden()->assertJsonPath('error.code', 'RIDER_MEMBERSHIP_REQUIRED');
        $this->assertDatabaseMissing('trip_passengers', ['trip_id' => $trip->id, 'user_id' => $rider->id, 'request_state' => Passenger::STATE_ACCEPTED]);
    }

    public function test_bike_seat_increment_and_type_change_cannot_bypass_the_exact_vehicle_approval(): void
    {
        [$host, $car, $trip] = $this->hostTrip('bike');
        $this->actingAsApiUser($host)->postJson('/api/trips/'.$trip->id.'/changeSeats', ['increment' => 1])->assertUnprocessable()->assertJsonPath('error.code', 'BIKE_CAPACITY_EXCEEDED');
        $this->actingAsApiUser($host)->putJson('/api/trips/'.$trip->id, ['car_id' => $car->id, 'vehicle_type' => 'car'])->assertUnprocessable()->assertJsonPath('error.code', 'VEHICLE_TYPE_INVALID');
        $this->assertSame(1, (int) $trip->fresh()->total_seats);
        $this->actingAsApiUser($host)->postJson('/api/trips/'.$trip->id.'/changeSeats', ['increment' => ['forged']])->assertUnprocessable();
    }

    public function test_operator_can_suspend_an_approval_after_vehicle_details_become_invalid(): void
    {
        [$host, $car, $trip] = $this->hostTrip();
        $admin = User::factory()->create(['active' => true, 'banned' => false, 'is_admin' => true]);
        $application = DB::table('mobility_host_applications')->where('user_id', $host->id)->first();
        $car->forceFill(['patente' => 'CHANGED'])->save();
        $this->assertFalse(CohortPolicy::hostApproved($host->id, $car->id));
        $this->actingAsApiUser($admin)->postJson('/api/mobility/operator/host-applications/'.$application->id.'/decision', [
            'expected_version' => 2, 'status' => 'suspended', 'reason' => 'Vehicle changed; review required',
        ])->assertOk()->assertJsonPath('status', 'suspended')->assertJsonPath('version', 3);
        $this->assertDatabaseHas('mobility_host_application_audits', ['application_id' => $application->id, 'version' => 3, 'new_status' => 'suspended']);
    }

    public function test_discovery_excludes_unapproved_hosts_and_changed_vehicle_snapshots(): void
    {
        [$host, $car, $trip] = $this->hostTrip();
        $trip->forceFill(['friendship_type_id' => Trip::PRIVACY_PUBLIC])->save();
        $unapproved = User::factory()->create(['active' => true]);
        Trip::factory()->create(['user_id' => $unapproved->id, 'friendship_type_id' => Trip::PRIVACY_PUBLIC]);
        $rider = $this->member();
        $response = $this->actingAsApiUser($rider)->getJson('/api/trips?page_size=100&is_passenger=false&history=false')->assertOk();
        $this->assertSame([$trip->id], array_column($response->json('data'), 'id'));
        $car->forceFill(['patente' => 'CHANGED'])->save();
        $this->actingAsApiUser($rider)->getJson('/api/trips?page_size=100&is_passenger=false&history=false')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    private function member(): User
    {
        $user = User::factory()->create(['active' => true, 'banned' => false, 'identity_validated' => true, 'autoaccept_requests' => false]);
        DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    private function hostTrip(string $type = 'car'): array
    {
        $host = $this->member();
        $car = Car::factory()->withCatalog()->create(['user_id' => $host->id]);
        DB::table('mobility_host_applications')->insert(['user_id' => $host->id, 'car_id' => $car->id, 'vehicle_type' => $type, 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $trip = Trip::factory()->create(['user_id' => $host->id, 'car_id' => $car->id, 'total_seats' => 1]);

        return [$host, $car, $trip];
    }
}
