<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

class MobilityDropoffTest extends TestCase
{
    public function test_only_the_booked_rider_can_confirm_after_alighting(): void
    {
        [$owner, $rider, $trip, $booking] = $this->fixture();
        $this->confirm($owner, $trip)->assertForbidden();
        $this->confirm(User::factory()->create(), $trip)->assertForbidden();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->confirm($admin, $trip)->assertForbidden();
        $this->confirm($rider, $trip)->assertStatus(409)->assertJsonPath('error.code', 'DROPOFF_NOT_READY');
        $this->assertDatabaseCount('mobility_dropoff_confirmations', 0);
        $this->transition($owner, $trip, 'start');
        $this->transition($owner, $trip, 'board', $rider);
        $this->confirm($rider, $trip)->assertStatus(409);
        $this->transition($owner, $trip, 'alight', $rider);
        $this->confirm($rider, $trip)->assertOk()->assertJsonPath('booking_id', $booking->id)
            ->assertJsonPath('user_id', $rider->id)->assertJsonPath('confirmation', 'rider_dropoff');
        $this->assertDatabaseCount('mobility_dropoff_confirmations', 1);
        $this->assertDatabaseHas('mobility_dropoff_confirmations', ['booking_id' => $booking->id, 'user_id' => $rider->id]);
    }

    public function test_confirmation_replays_after_completion_and_cannot_be_overwritten(): void
    {
        [$owner, $rider, $trip] = $this->fixture();
        $this->transition($owner, $trip, 'start');
        $this->transition($owner, $trip, 'board', $rider);
        $this->transition($owner, $trip, 'alight', $rider);
        $first = $this->confirm($rider, $trip)->assertOk();
        $this->transition($owner, $trip, 'complete');
        $this->confirm($rider, $trip)->assertOk()->assertExactJson($first->json());
        $this->confirm($rider, $trip, 'different-key')->assertStatus(409)->assertJsonPath('error.code', 'DROPOFF_ALREADY_CONFIRMED');
        $this->assertDatabaseCount('mobility_dropoff_confirmations', 1);
        $receipt = DB::table('mobility_dropoff_confirmations')->first();
        $this->assertSame('alight', DB::table('mobility_trip_events')->where('id', $receipt->confirmed_after_event_id)->value('action'));
    }

    public function test_rider_can_confirm_after_completion_but_driver_cannot_supply_rider_identity(): void
    {
        [$owner, $rider, $trip] = $this->fixture();
        $this->transition($owner, $trip, 'start');
        $this->transition($owner, $trip, 'board', $rider);
        $this->transition($owner, $trip, 'alight', $rider);
        $this->transition($owner, $trip, 'complete');
        $this->actingAsApiUser($owner)->postJson($this->path($trip), ['user_id' => $rider->id], ['Idempotency-Key' => 'spoof'])->assertForbidden();
        $this->assertDatabaseCount('mobility_dropoff_confirmations', 0);
        $this->confirm($rider, $trip)->assertOk();
    }

    public function test_pending_cancelled_and_missing_evidence_do_not_confirm(): void
    {
        [$owner, $rider, $trip, $booking] = $this->fixture();
        $booking->update(['request_state' => Passenger::STATE_PENDING]);
        $this->confirm($rider, $trip)->assertForbidden();
        $booking->update(['request_state' => Passenger::STATE_ACCEPTED]);
        $this->transition($owner, $trip, 'cancel');
        $this->confirm($rider, $trip)->assertForbidden();
        $this->assertDatabaseCount('mobility_dropoff_confirmations', 0);

        [$owner, $rider, $trip, $booking] = $this->fixture();
        DB::table('mobility_trip_lifecycles')->insert(['trip_id' => $trip->id, 'state' => 'completed', 'version' => 0]);
        DB::table('mobility_trip_participants')->insert(['trip_id' => $trip->id, 'booking_id' => $booking->id, 'user_id' => $rider->id, 'state' => 'alighted']);
        $this->confirm($rider, $trip)->assertStatus(409)->assertJsonPath('error.code', 'DROPOFF_EVIDENCE_MISSING');
        $this->assertDatabaseCount('mobility_dropoff_confirmations', 0);
    }

    public function test_confirmation_requires_a_valid_retry_key(): void
    {
        [, $rider, $trip] = $this->fixture();
        $this->actingAsApiUser($rider)->postJson($this->path($trip))->assertStatus(422);
        foreach ([' ', str_repeat('x', 101), "bad\nkey"] as $key) {
            $this->confirm($rider, $trip, $key)->assertStatus(422);
        }
        $this->assertDatabaseCount('mobility_dropoff_confirmations', 0);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $rider = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $owner->id]);
        $booking = Passenger::factory()->aceptado()->create(['trip_id' => $trip->id, 'user_id' => $rider->id]);

        return [$owner, $rider, $trip, $booking];
    }

    private function path(Trip $trip): string
    {
        return '/api/mobility/trips/'.$trip->id.'/dropoff-confirmation';
    }

    private function confirm(User $rider, Trip $trip, string $key = 'dropoff-1')
    {
        return $this->actingAsApiUser($rider)->postJson($this->path($trip), [], ['Idempotency-Key' => $key]);
    }

    private function transition(User $owner, Trip $trip, string $action, ?User $rider = null): void
    {
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions',
            ['action' => $action, 'passenger_id' => $rider?->id], ['Idempotency-Key' => $action])->assertOk();
    }
}
