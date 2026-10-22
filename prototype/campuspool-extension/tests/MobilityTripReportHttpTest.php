<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\SupportTicketReply;
use STS\Models\Trip;
use STS\Models\User;
use STS\Services\SupportTicketService;
use Tests\TestCase;

class MobilityTripReportHttpTest extends TestCase
{
    public function test_report_allowance_is_per_actor_with_the_same_five_attempt_bound(): void
    {
        $owner = User::factory()->create();
        $rider = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $owner->id]);
        Passenger::factory()->aceptado()->create(['trip_id' => $trip->id, 'user_id' => $rider->id]);
        $path = '/api/mobility/trips/'.$trip->id.'/reports';
        $payload = ['subject' => 'Synthetic report allowance', 'message_markdown' => 'Synthetic rate boundary'];
        for ($index = 0; $index < 5; $index++) {
            $this->actingAsApiUser($rider)->postJson($path, $payload, ['Idempotency-Key' => 'same-case'])->assertOk();
        }
        $this->actingAsApiUser($rider)->postJson($path, $payload, ['Idempotency-Key' => 'same-case'])->assertStatus(429);
        $this->actingAsApiUser($owner)->postJson($path, $payload, ['Idempotency-Key' => 'owner-case'])->assertOk();
        $this->assertDatabaseCount('support_tickets', 2);
    }

    public function test_conductor_rows_and_short_trimmed_subjects_cannot_create_reports(): void
    {
        $owner = User::factory()->create();
        $conductor = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $owner->id]);
        Passenger::factory()->create(['trip_id' => $trip->id, 'user_id' => $conductor->id,
            'passenger_type' => Passenger::TYPE_CONDUCTOR, 'request_state' => Passenger::STATE_ACCEPTED]);
        $path = '/api/mobility/trips/'.$trip->id.'/reports';
        $this->actingAsApiUser($conductor)->postJson($path, ['subject' => 'Valid subject', 'message_markdown' => 'Synthetic report'], ['Idempotency-Key' => 'conductor'])->assertForbidden();
        $this->actingAsApiUser($owner)->postJson($path, ['subject' => ' a ', 'message_markdown' => 'Synthetic report'], ['Idempotency-Key' => 'short'])->assertStatus(422);
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_report_requires_participant_and_replays_safely(): void
    {
        $owner = User::factory()->create();
        $rider = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $owner->id]);
        Passenger::factory()->aceptado()->create(['trip_id' => $trip->id, 'user_id' => $rider->id]);
        $payload = ['subject' => 'Unsafe pickup', 'message_markdown' => 'The pickup point was blocked.'];
        $headers = ['Idempotency-Key' => 'report-1'];
        $first = $this->actingAsApiUser($rider)->postJson('/api/mobility/trips/'.$trip->id.'/reports', $payload, $headers)->assertOk();
        $this->actingAsApiUser($rider)->postJson('/api/mobility/trips/'.$trip->id.'/reports', $payload, $headers)->assertOk()->assertExactJson($first->json());
        $this->assertDatabaseCount('mobility_trip_report_requests', 1);
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseHas('support_ticket_replies', ['ticket_id' => $first->json('data.id'), 'user_id' => $rider->id, 'is_admin' => 0]);
    }

    public function test_same_key_changed_payload_and_duplicate_text_with_new_key(): void
    {
        $owner = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $owner->id]);
        $base = ['subject' => 'Blocked route', 'message_markdown' => 'The route was blocked.'];
        $path = '/api/mobility/trips/'.$trip->id.'/reports';
        $this->actingAsApiUser($owner)->postJson($path, $base, ['Idempotency-Key' => 'one'])->assertOk()->assertJsonStructure(['data' => ['id', 'trip_id', 'subject', 'status', 'type', 'priority', 'created_at', 'updated_at']]);
        $this->actingAsApiUser($owner)->postJson($path, ['subject' => 'Changed route', 'message_markdown' => 'Another issue.'], ['Idempotency-Key' => 'one'])->assertStatus(409);
        $this->actingAsApiUser($owner)->postJson($path, $base, ['Idempotency-Key' => 'two'])->assertOk();
        $this->assertDatabaseCount('support_tickets', 2);
    }

    public function test_anonymous_inactive_unrelated_and_admin_outsider_are_denied(): void
    {
        $owner = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $owner->id]);
        $path = '/api/mobility/trips/'.$trip->id.'/reports';
        $payload = ['subject' => 'Unsafe pickup', 'message_markdown' => 'The pickup point was blocked.'];

        $this->postJson($path, $payload, ['Idempotency-Key' => 'anon'])->assertUnauthorized();
        $inactive = User::factory()->create(['active' => false]);
        $this->actingAsApiUser($inactive)->postJson($path, $payload, ['Idempotency-Key' => 'inactive'])->assertUnauthorized();
        $outsider = User::factory()->create();
        $this->actingAsApiUser($outsider)->postJson($path, $payload, ['Idempotency-Key' => 'outsider'])->assertForbidden();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAsApiUser($admin)->postJson($path, $payload, ['Idempotency-Key' => 'admin-outsider'])->assertForbidden();
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_owner_and_recoverable_passenger_states_can_report(): void
    {
        $owner = User::factory()->create();
        $accepted = User::factory()->create();
        $waiting = User::factory()->create();
        $canceled = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $owner->id]);
        foreach ([[$accepted, Passenger::STATE_ACCEPTED], [$waiting, Passenger::STATE_WAITING_PAYMENT], [$canceled, Passenger::STATE_CANCELED]] as [$user, $state]) {
            Passenger::factory()->create(['trip_id' => $trip->id, 'user_id' => $user->id, 'passenger_type' => Passenger::TYPE_PASAJERO, 'request_state' => $state]);
        }
        $path = '/api/mobility/trips/'.$trip->id.'/reports';
        foreach ([[$owner, 'owner'], [$accepted, 'accepted'], [$waiting, 'waiting'], [$canceled, 'canceled']] as [$actor, $key]) {
            $this->actingAsApiUser($actor)->postJson($path, ['subject' => 'Trip issue', 'message_markdown' => 'A report for '.$key.'.'], ['Idempotency-Key' => 'state-'.$key])->assertOk();
        }
        $this->assertDatabaseCount('support_tickets', 4);
    }

    public function test_invalid_key_subject_and_device_info_only_message_are_rejected(): void
    {
        $owner = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $owner->id]);
        $path = '/api/mobility/trips/'.$trip->id.'/reports';
        $this->actingAsApiUser($owner)->postJson($path, ['subject' => 'Valid', 'message_markdown' => 'A valid report.'])->assertStatus(422);
        $this->actingAsApiUser($owner)->postJson($path, ['subject' => 'x', 'message_markdown' => 'A valid report.'], ['Idempotency-Key' => 'bad'])->assertStatus(422);
        $this->actingAsApiUser($owner)->postJson($path, ['subject' => 'Valid subject', 'message_markdown' => '--- Información del dispositivo ---\nmodel: test'], ['Idempotency-Key' => 'device-only'])->assertStatus(422);
        $this->actingAsApiUser($owner)->postJson($path, ['subject' => 'Valid subject', 'message_markdown' => str_repeat('x', 4001)], ['Idempotency-Key' => 'too-long'])->assertStatus(422);
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_failure_after_ticket_and_reply_creation_rolls_back_everything(): void
    {
        $owner = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $owner->id]);
        $failure = \Mockery::mock(SupportTicketService::class);
        $failure->shouldReceive('appendOpeningAutoReply')->once()->andThrow(new \RuntimeException('injected report fault'));
        $this->app->instance(SupportTicketService::class, $failure);

        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/reports', ['subject' => 'Rollback issue', 'message_markdown' => 'This must roll back.'], ['Idempotency-Key' => 'rollback'])->assertServerError();
        $this->assertDatabaseCount('support_tickets', 0);
        $this->assertDatabaseCount('support_ticket_replies', 0);
        $this->assertDatabaseCount('mobility_trip_report_requests', 0);
    }

    public function test_replay_projection_hides_operator_private_note(): void
    {
        $owner = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $owner->id]);
        $path = '/api/mobility/trips/'.$trip->id.'/reports';
        $payload = ['subject' => 'Private case', 'message_markdown' => 'Please review this issue.'];
        $first = $this->actingAsApiUser($owner)->postJson($path, $payload, ['Idempotency-Key' => 'private-replay'])->assertOk();
        DB::table('support_tickets')->where('id', $first->json('data.id'))->update(['internal_note_markdown' => 'operator secret']);
        $this->actingAsApiUser($owner)->postJson($path, $payload, ['Idempotency-Key' => 'private-replay'])->assertOk()->assertJsonMissing(['internal_note_markdown' => 'operator secret']);
        $this->assertSame(1, SupportTicketReply::query()->where('ticket_id', $first->json('data.id'))->where('is_admin', false)->count());
    }
}
