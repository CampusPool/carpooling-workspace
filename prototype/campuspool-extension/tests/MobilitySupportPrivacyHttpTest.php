<?php

namespace Tests\Feature\Mobility;

use STS\Models\SupportTicket;
use STS\Models\SupportTicketReply;
use STS\Models\User;
use Tests\TestCase;

final class MobilitySupportPrivacyHttpTest extends TestCase
{
    public function test_user_ticket_list_detail_create_replay_and_reply_hide_operator_private_notes(): void
    {
        $owner = User::factory()->create(['active' => true, 'banned' => false]);
        $ticket = SupportTicket::create([
            'user_id' => $owner->id, 'created_by' => $owner->id,
            'type' => 'report', 'source' => 'web_form', 'subject' => 'Synthetic support privacy',
            'status' => 'Open', 'priority' => 'high', 'unread_for_user' => 0, 'unread_for_admin' => 1,
            'internal_note_markdown' => 'PRIVATE-OPERATOR-NOTE-SHOULD-STAY-PRIVATE',
        ]);
        SupportTicketReply::create([
            'ticket_id' => $ticket->id, 'user_id' => $owner->id, 'created_by' => $owner->id,
            'is_admin' => false, 'message_markdown' => 'Synthetic opening message',
        ]);
        $responses = [
            $this->actingAsApiUser($owner)->getJson('/api/support/tickets'),
            $this->actingAsApiUser($owner)->getJson('/api/support/tickets/'.$ticket->id),
            $this->actingAsApiUser($owner)->postJson('/api/support/tickets', [
                'type' => 'report', 'subject' => $ticket->subject, 'message_markdown' => 'Synthetic opening message',
            ]),
            $this->actingAsApiUser($owner)->postJson('/api/support/tickets/'.$ticket->id.'/replies', ['message_markdown' => 'Synthetic follow-up']),
        ];
        foreach ($responses as $response) {
            $response->assertOk();
            $this->assertStringNotContainsString('internal_note_markdown', $response->getContent());
            $this->assertStringNotContainsString('PRIVATE-OPERATOR-NOTE', $response->getContent());
        }
        $this->assertSame('PRIVATE-OPERATOR-NOTE-SHOULD-STAY-PRIVATE', $ticket->fresh()->internal_note_markdown);
        $this->assertDatabaseCount('support_tickets', 1);

        $outsider = User::factory()->create(['active' => true, 'banned' => false]);
        $this->actingAsApiUser($outsider)->getJson('/api/support/tickets/'.$ticket->id)->assertNotFound();
    }
}
