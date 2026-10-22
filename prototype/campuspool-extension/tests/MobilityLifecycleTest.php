<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\TripLiveShare;
use STS\Models\User;
use Tests\TestCase;

class MobilityLifecycleTest extends TestCase
{
    public function test_owner_can_start_and_exactly_replay_a_transition(): void
    {
        [$owner, $trip] = $this->tripFixture();

        $first = $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'start'], ['Idempotency-Key' => 'start-1']);
        $replay = $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'start'], ['Idempotency-Key' => 'start-1']);

        $first->assertOk()->assertJsonPath('state', 'in_progress');
        $replay->assertOk()->assertExactJson($first->json());
        $this->assertSame(1, DB::table('mobility_trip_events')->where('trip_id', $trip->id)->count());
    }

    public function test_reusing_key_with_different_request_conflicts_and_bad_transition_has_no_event(): void
    {
        [$owner, $trip] = $this->tripFixture();
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'start'], ['Idempotency-Key' => 'same'])->assertOk();
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'cancel'], ['Idempotency-Key' => 'same'])->assertStatus(409);
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'start'], ['Idempotency-Key' => 'blocked'])->assertStatus(409);
        $this->assertSame(1, DB::table('mobility_trip_events')->where('trip_id', $trip->id)->count());
    }

    public function test_first_invalid_transition_does_not_create_a_lifecycle_row(): void
    {
        [$owner, $trip] = $this->tripFixture();
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'complete'], ['Idempotency-Key' => 'invalid'])->assertStatus(409);
        $this->assertDatabaseMissing('mobility_trip_lifecycles', ['trip_id' => $trip->id]);
        $this->assertDatabaseMissing('mobility_trip_events', ['trip_id' => $trip->id]);
    }

    public function test_alighted_passenger_cannot_board_again(): void
    {
        [$owner, $trip] = $this->tripFixture();
        $passenger = User::factory()->create();
        Passenger::factory()->create(['trip_id' => $trip->id, 'user_id' => $passenger->id, 'passenger_type' => Passenger::TYPE_PASAJERO, 'request_state' => Passenger::STATE_ACCEPTED]);
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'start'], ['Idempotency-Key' => 's'])->assertOk();
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'board', 'passenger_id' => $passenger->id], ['Idempotency-Key' => 'b'])->assertOk();
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'alight', 'passenger_id' => $passenger->id], ['Idempotency-Key' => 'a'])->assertOk();
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'board', 'passenger_id' => $passenger->id], ['Idempotency-Key' => 'b2'])->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_ALIGHTED');
        $this->assertSame(3, DB::table('mobility_trip_events')->where('trip_id', $trip->id)->count());
        $this->assertSame(3, DB::table('mobility_trip_lifecycles')->where('trip_id', $trip->id)->value('version'));
    }

    public function test_cancelled_legacy_trip_cannot_be_started(): void
    {
        [$owner, $trip] = $this->tripFixture();
        $trip->update(['state' => Trip::STATE_CANCELED]);
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'start'], ['Idempotency-Key' => 'cancelled-start'])->assertStatus(409)->assertJsonPath('error.code', 'LEGACY_TRIP_NOT_STARTABLE');
        $this->assertDatabaseMissing('mobility_trip_lifecycles', ['trip_id' => $trip->id]);
    }

    public function test_outsider_cannot_read_or_mutate_and_completion_requires_alighting(): void
    {
        [$owner, $trip] = $this->tripFixture();
        $outsider = User::factory()->create();
        $this->actingAsApiUser($outsider)->getJson('/api/mobility/trips/'.$trip->id.'/lifecycle')->assertForbidden();
        $this->actingAsApiUser($outsider)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'start'], ['Idempotency-Key' => 'nope'])->assertForbidden();

        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'start'], ['Idempotency-Key' => 'start'])->assertOk();
        $passenger = User::factory()->create();
        Passenger::factory()->create(['trip_id' => $trip->id, 'user_id' => $passenger->id, 'request_state' => Passenger::STATE_ACCEPTED]);
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'complete'], ['Idempotency-Key' => 'complete'])->assertStatus(409)->assertJsonPath('error.code', 'PASSENGERS_NOT_ALIGHTED');
    }

    public function test_cancel_releases_pending_and_accepted_bookings(): void
    {
        [$owner, $trip] = $this->tripFixture();
        $passenger = User::factory()->create();
        Passenger::factory()->create(['trip_id' => $trip->id, 'user_id' => $passenger->id, 'passenger_type' => Passenger::TYPE_PASAJERO, 'request_state' => Passenger::STATE_ACCEPTED]);
        Passenger::factory()->create(['trip_id' => $trip->id, 'user_id' => User::factory()->create()->id, 'passenger_type' => Passenger::TYPE_PASAJERO, 'request_state' => Passenger::STATE_PENDING]);

        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'cancel'], ['Idempotency-Key' => 'cancel'])->assertOk()->assertJsonPath('state', 'cancelled');
        $this->assertSame(0, Passenger::query()->where('trip_id', $trip->id)->whereIn('request_state', [Passenger::STATE_PENDING, Passenger::STATE_ACCEPTED, Passenger::STATE_WAITING_PAYMENT])->count());
        $this->assertSame(1, DB::table('mobility_trip_events')->where('trip_id', $trip->id)->where('action', 'cancel')->count());
    }

    public function test_cancelled_participant_can_read_own_outcome_without_other_booking_data(): void
    {
        [$owner, $trip] = $this->tripFixture();
        $guest = User::factory()->create();
        $other = User::factory()->create();
        foreach ([$guest, $other] as $user) {
            Passenger::factory()->create(['trip_id' => $trip->id, 'user_id' => $user->id,
                'passenger_type' => Passenger::TYPE_PASAJERO, 'request_state' => Passenger::STATE_ACCEPTED]);
        }
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions',
            ['action' => 'cancel'], ['Idempotency-Key' => 'cancel'])->assertOk();
        $this->actingAsApiUser($guest)->getJson('/api/mobility/trips/'.$trip->id.'/lifecycle')
            ->assertOk()->assertJsonPath('state', 'cancelled')->assertJsonCount(1, 'participants')
            ->assertJsonPath('participants.0.passenger_id', $guest->id)
            ->assertJsonPath('participants.0.booking_state', Passenger::STATE_CANCELED)->assertJsonCount(0, 'events');
    }

    public function test_complete_and_cancel_stop_active_live_shares(): void
    {
        [$owner, $trip] = $this->tripFixture();
        $share = TripLiveShare::factory()->create(['trip_id' => $trip->id, 'user_id' => $owner->id, 'is_active' => true, 'lat' => 12.3, 'lng' => 45.6, 'recorded_at' => now(), 'stopped_at' => null]);
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'cancel'], ['Idempotency-Key' => 'cancel-share'])->assertOk();
        $share->refresh();
        $this->assertFalse($share->is_active);
        $this->assertNull($share->lat);
        $this->assertNull($share->lng);
        $this->assertNotNull($share->stopped_at);

        [$owner2, $trip2] = $this->tripFixture();
        $passenger = User::factory()->create();
        Passenger::factory()->create(['trip_id' => $trip2->id, 'user_id' => $passenger->id, 'passenger_type' => Passenger::TYPE_PASAJERO, 'request_state' => Passenger::STATE_ACCEPTED]);
        $share2 = TripLiveShare::factory()->create(['trip_id' => $trip2->id, 'user_id' => $owner2->id, 'is_active' => true, 'lat' => 1.2, 'lng' => 3.4, 'recorded_at' => now(), 'stopped_at' => null]);
        $this->actingAsApiUser($owner2)->postJson('/api/mobility/trips/'.$trip2->id.'/transitions', ['action' => 'start'], ['Idempotency-Key' => 'start-share'])->assertOk();
        $this->actingAsApiUser($owner2)->postJson('/api/mobility/trips/'.$trip2->id.'/transitions', ['action' => 'board', 'passenger_id' => $passenger->id], ['Idempotency-Key' => 'board-share'])->assertOk();
        $this->actingAsApiUser($owner2)->postJson('/api/mobility/trips/'.$trip2->id.'/transitions', ['action' => 'alight', 'passenger_id' => $passenger->id], ['Idempotency-Key' => 'alight-share'])->assertOk();
        $this->actingAsApiUser($owner2)->postJson('/api/mobility/trips/'.$trip2->id.'/transitions', ['action' => 'complete'], ['Idempotency-Key' => 'complete-share'])->assertOk();
        $this->assertFalse($share2->fresh()->is_active);
    }

    public function test_board_then_alight_and_complete(): void
    {
        [$owner, $trip] = $this->tripFixture();
        $passenger = User::factory()->create();
        $booking = Passenger::factory()->create(['trip_id' => $trip->id, 'user_id' => $passenger->id, 'passenger_type' => Passenger::TYPE_PASAJERO, 'request_state' => Passenger::STATE_ACCEPTED]);
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'start'], ['Idempotency-Key' => 's'])->assertOk();
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'board', 'passenger_id' => $passenger->id], ['Idempotency-Key' => 'b'])->assertOk();
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'alight', 'passenger_id' => $passenger->id], ['Idempotency-Key' => 'a'])->assertOk();
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'complete'], ['Idempotency-Key' => 'c'])->assertOk()->assertJsonPath('state', 'completed');
        $this->assertDatabaseHas('mobility_trip_participants', ['trip_id' => $trip->id, 'booking_id' => $booking->id, 'state' => 'alighted']);
    }

    private function tripFixture(): array
    {
        $owner = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $owner->id]);

        return [$owner, $trip];
    }
}
