<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

final class MobilityTripCommunicationHttpTest extends TestCase
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

    public function test_driver_and_accepted_rider_can_read_and_send_canonical_chat_without_precise_coordinates(): void
    {
        [$driver, $trip, $rider] = $this->fixture();

        $this->actingAsApiUser($driver)->getJson($this->messagesPath($trip).'?after=0')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'trip_id', 'messages',
                'participants' => [['id', 'name']], 'can_send', 'blocked', 'unread_count',
            ]])
            ->assertJsonPath('data.trip_id', $trip->id)
            ->assertJsonPath('data.can_send', true)
            ->assertJsonPath('data.blocked', false)
            ->assertJsonMissingPath('data.location')
            ->assertJsonMissingPath('data.messages.0.lat');

        $sent = $this->actingAsApiUser($rider)->postJson($this->messagesPath($trip), ['text' => 'Meet at the agreed pickup point.'], $this->key('send'))
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'user_id', 'text', 'created_at']])
            ->assertJsonPath('data.user_id', $rider->id)
            ->assertJsonPath('data.text', 'Meet at the agreed pickup point.');

        $messageId = $sent->json('data.id');
        $this->actingAsApiUser($driver)->getJson($this->messagesPath($trip).'?after=0')
            ->assertOk()->assertJsonPath('data.messages.0.id', $messageId)
            ->assertJsonPath('data.messages.0.text', 'Meet at the agreed pickup point.');
    }

    public function test_message_idempotency_replays_exactly_and_conflicts_on_changed_text(): void
    {
        [$driver, $trip] = $this->fixture();
        $first = $this->actingAsApiUser($driver)->postJson($this->messagesPath($trip), ['text' => 'First message'], $this->key('message-replay'))->assertOk();

        $this->actingAsApiUser($driver)->postJson($this->messagesPath($trip), ['text' => 'First message'], $this->key('message-replay'))
            ->assertOk()->assertExactJson($first->json());
        $this->actingAsApiUser($driver)->postJson($this->messagesPath($trip), ['text' => 'Changed message'], $this->key('message-replay'))
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->actingAsApiUser($driver)->postJson($this->messagesPath($trip), ['text' => 'Missing key'])
            ->assertStatus(422);
    }

    public function test_unread_notifications_and_read_through_are_scoped_to_the_trip(): void
    {
        [$driver, $trip, $rider] = $this->fixture();
        [$otherDriver, $otherTrip] = $this->fixture();
        $first = $this->send($driver, $trip, 'trip-one-a', 'trip-one-a');
        $second = $this->send($driver, $trip, 'trip-one-b', 'trip-one-b');
        $this->send($otherDriver, $otherTrip, 'trip-two', 'trip-two');

        $this->actingAsApiUser($rider)->getJson('/api/mobility/notifications')
            ->assertOk()->assertJsonStructure(['data' => ['unread_messages', 'trips' => [['trip_id', 'unread_count', 'last_message_id']]]])
            ->assertJsonPath('data.unread_messages', 2);

        $this->actingAsApiUser($rider)->postJson($this->messagesPath($trip, 'read'), ['through_id' => $first], $this->key('read-one'))
            ->assertOk()->assertJsonPath('data.read_through_id', $first);
        $this->actingAsApiUser($rider)->getJson($this->messagesPath($trip).'?after=0')
            ->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->actingAsApiUser($rider)->postJson($this->messagesPath($trip, 'read'), ['through_id' => $second], $this->key('read-two'))
            ->assertOk()->assertJsonPath('data.read_through_id', $second);
        $this->actingAsApiUser($rider)->getJson('/api/mobility/notifications')
            ->assertOk()->assertJsonPath('data.unread_messages', 0);
    }

    public function test_blocking_both_directions_blocks_new_group_sends_unblocks_and_preserves_history(): void
    {
        [$driver, $trip, $rider] = $this->fixture();
        $beforeTrip = $trip->fresh()->only(['id', 'state', 'user_id']);
        $beforeBooking = Passenger::query()->where('trip_id', $trip->id)->where('user_id', $rider->id)->firstOrFail()->only(['id', 'request_state']);
        $message = $this->send($driver, $trip, 'before-block', 'before-block');

        $this->actingAsApiUser($rider)->postJson($this->blocksPath($trip), ['target_user_id' => $driver->id, 'blocked' => true], $this->key('block-rider-driver'))
            ->assertOk()->assertJsonPath('data.target_user_id', $driver->id)->assertJsonPath('data.blocked', true);
        $this->actingAsApiUser($driver)->postJson($this->messagesPath($trip), ['text' => 'blocked'], $this->key('blocked-driver'))
            ->assertStatus(403);
        $this->actingAsApiUser($rider)->postJson($this->messagesPath($trip), ['text' => 'blocked'], $this->key('blocked-rider'))
            ->assertStatus(403);

        $this->actingAsApiUser($driver)->postJson($this->blocksPath($trip), ['target_user_id' => $rider->id, 'blocked' => false], $this->key('unblock-driver-rider'))
            ->assertOk()->assertJsonPath('data.blocked', false);
        $this->actingAsApiUser($driver)->getJson($this->messagesPath($trip).'?after=0')
            ->assertOk()->assertJsonPath('data.messages.0.id', $message);
        $this->actingAsApiUser($driver)->postJson($this->messagesPath($trip), ['text' => 'still blocked'], $this->key('still-blocked'))->assertStatus(403);
        $this->actingAsApiUser($rider)->postJson($this->blocksPath($trip), ['target_user_id' => $driver->id, 'blocked' => false], $this->key('unblock-own'))->assertOk();
        $this->send($driver, $trip, 'after-unblock', 'after-unblock');
        $this->assertSame($beforeTrip, $trip->fresh()->only(['id', 'state', 'user_id']));
        $this->assertSame($beforeBooking, Passenger::query()->where('id', $beforeBooking['id'])->firstOrFail()->only(['id', 'request_state']));
    }

    public function test_terminal_trip_allows_history_but_rejects_new_sends(): void
    {
        [$driver, $trip] = $this->fixture();
        $message = $this->send($driver, $trip, 'terminal-history', 'terminal-history');
        $trip->update(['state' => Trip::STATE_CANCELED]);

        $this->actingAsApiUser($driver)->getJson($this->messagesPath($trip).'?after=0')
            ->assertOk()->assertJsonPath('data.messages.0.id', $message);
        $this->actingAsApiUser($driver)->postJson($this->messagesPath($trip), ['text' => 'too late'], $this->key('terminal-send'))
            ->assertStatus(409);
    }

    public function test_pending_rider_and_outsider_cannot_read_or_send(): void
    {
        [$driver, $trip, $accepted] = $this->fixture();
        $pending = $this->member();
        Passenger::factory()->create(['trip_id' => $trip->id, 'user_id' => $pending->id, 'passenger_type' => Passenger::TYPE_PASAJERO, 'request_state' => Passenger::STATE_PENDING]);
        $outsider = $this->member();

        foreach ([$pending, $outsider] as $actor) {
            $this->actingAsApiUser($actor)->getJson($this->messagesPath($trip).'?after=0')->assertStatus(403);
            $this->actingAsApiUser($actor)->postJson($this->messagesPath($trip), ['text' => 'outside'], $this->key('denied-'.$actor->id))->assertStatus(403);
        }
        $this->actingAsApiUser($accepted)->getJson($this->messagesPath($trip).'?after=0')->assertOk();
        $this->assertNotSame($driver->id, $outsider->id);
    }

    public function test_legacy_conversation_route_cannot_bypass_canonical_chat(): void
    {
        [$driver, $trip, $rider] = $this->fixture();
        foreach ([$driver, $rider, $this->member()] as $actor) {
            $this->actingAsApiUser($actor)->getJson('/api/conversations/'.$trip->id)
                ->assertStatus(409)->assertJsonPath('error.code', 'CANONICAL_CHAT_REQUIRED');
            $this->actingAsApiUser($actor)->postJson('/api/conversations/'.$trip->id.'/send', ['message' => 'legacy'])
                ->assertStatus(409)->assertJsonPath('error.code', 'CANONICAL_CHAT_REQUIRED');
        }
    }

    public function test_group_chat_adds_accepted_riders_and_removes_cancelled_participants(): void
    {
        [$driver, $trip, $rider] = $this->fixture();
        $third = $this->member();
        $booking = Passenger::factory()->create(['trip_id' => $trip->id, 'user_id' => $third->id, 'passenger_type' => Passenger::TYPE_PASAJERO, 'request_state' => Passenger::STATE_ACCEPTED]);
        $this->send($driver, $trip, 'group', 'group');
        $this->actingAsApiUser($third)->getJson($this->messagesPath($trip))->assertOk()->assertJsonCount(3, 'data.participants');
        $booking->update(['request_state' => Passenger::STATE_CANCELED]);
        $this->actingAsApiUser($third)->getJson($this->messagesPath($trip))->assertStatus(403);
        $this->send($rider, $trip, 'still accepted', 'still-accepted');
        $conversation = DB::table('mobility_trip_conversations')->where('trip_id', $trip->id)->value('conversation_id');
        $this->assertDatabaseMissing('conversations_users', ['conversation_id' => $conversation, 'user_id' => $third->id]);
    }

    public function test_safety_block_remains_available_after_trip_completion(): void
    {
        [$driver, $trip, $rider] = $this->fixture();
        $trip->update(['state' => Trip::STATE_CANCELED]);
        $this->actingAsApiUser($rider)->postJson($this->blocksPath($trip), ['target_user_id' => $driver->id, 'blocked' => true], $this->key('after-trip-block'))->assertOk();
        $this->actingAsApiUser($driver)->getJson($this->messagesPath($trip))->assertOk()->assertJsonPath('data.can_send', false)->assertJsonPath('data.participants.0.blocked_by_you', false);
    }

    public function test_message_history_pages_latest_earlier_and_newer_without_cross_trip_read_acknowledgement(): void
    {
        [$driver, $trip] = $this->fixture();
        $first = $this->send($driver, $trip, 'first', 'history-first');
        $conversation = DB::table('mobility_trip_conversations')->where('trip_id', $trip->id)->value('conversation_id');
        $rows = [];
        for ($i = 0; $i < 55; $i++) $rows[] = ['conversation_id' => $conversation, 'user_id' => $driver->id, 'text' => 'Synthetic history '.$i, 'estado' => 0, 'is_system' => false, 'created_at' => now(), 'updated_at' => now()];
        DB::table('messages')->insert($rows);
        $latest = $this->actingAsApiUser($driver)->getJson($this->messagesPath($trip))->assertOk()->assertJsonCount(50, 'data.messages')->json('data');
        $this->assertGreaterThan($first, $latest['messages'][0]['id']);
        $this->actingAsApiUser($driver)->getJson($this->messagesPath($trip).'?before='.$latest['older_before'])->assertOk()->assertJsonCount(6, 'data.messages')->assertJsonPath('data.messages.0.id', $first);
        $this->actingAsApiUser($driver)->getJson($this->messagesPath($trip).'?after='.$latest['messages'][49]['id'])->assertOk()->assertJsonCount(0, 'data.messages');
        $this->actingAsApiUser($driver)->getJson($this->messagesPath($trip).'?after=0&before='.$first)->assertStatus(422);
        [$otherDriver, $otherTrip] = $this->fixture();
        $foreign = $this->send($otherDriver, $otherTrip, 'other trip', 'foreign-message');
        $this->actingAsApiUser($driver)->postJson($this->messagesPath($trip, 'read'), ['through_id' => $foreign])->assertStatus(404);
    }

    private function fixture(): array
    {
        $driver = $this->member();
        $trip = Trip::factory()->create(['user_id' => $driver->id]);
        $acceptedPassenger = $this->member();
        Passenger::factory()->create([
            'trip_id' => $trip->id,
            'user_id' => $acceptedPassenger->id,
            'passenger_type' => Passenger::TYPE_PASAJERO,
            'request_state' => Passenger::STATE_ACCEPTED,
        ]);

        return [$driver, $trip, $acceptedPassenger];
    }

    private function member(): User
    {
        $user = User::factory()->create(['active' => true, 'banned' => false]);
        DB::table('mobility_memberships')->insert([
            'user_id' => $user->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student',
            'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function messagesPath(Trip $trip, string $suffix = ''): string
    {
        return '/api/mobility/trips/'.$trip->id.'/messages'.($suffix === '' ? '' : '/'.$suffix);
    }

    private function blocksPath(Trip $trip): string
    {
        return '/api/mobility/trips/'.$trip->id.'/blocks';
    }

    private function key(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    private function send(User $actor, Trip $trip, string $text, string $key): int
    {
        return (int) $this->actingAsApiUser($actor)->postJson($this->messagesPath($trip), ['text' => $text], $this->key($key))->assertOk()->json('data.id');
    }
}
