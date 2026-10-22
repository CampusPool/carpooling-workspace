<?php

namespace STS\Mobility\Support;

use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\SupportTicket;
use STS\Models\SupportTicketReply;
use STS\Models\Trip;
use STS\Models\User;
use STS\Services\SupportTicketService;

final class TripReportService
{
    public function __construct(private readonly SupportTicketService $supportTickets) {}

    public function create(Trip $trip, User $actor, string $key, string $subject, string $message): object
    {
        $fingerprint = hash('sha256', json_encode(['subject' => $subject, 'message_markdown' => $message], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($trip, $actor, $key, $subject, $message, $fingerprint): object {
            $lockedTrip = Trip::query()->whereKey($trip->getKey())->lockForUpdate()->first();
            if (! $lockedTrip) {
                throw new TripReportException('TRIP_NOT_FOUND', 404);
            }
            if (! $this->participant((int) $lockedTrip->id, (int) $actor->id, (int) $lockedTrip->user_id)) {
                throw new TripReportException('TRIP_REPORT_FORBIDDEN', 403);
            }

            $existing = DB::table('mobility_trip_report_requests')->where([
                'trip_id' => $lockedTrip->id,
                'actor_id' => $actor->id,
                'idempotency_key' => $key,
            ])->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                    throw new TripReportException('IDEMPOTENCY_CONFLICT', 409);
                }

                return (object) ['ticket' => SupportTicket::query()->findOrFail($existing->ticket_id), 'replay' => true, 'auto_reply' => false, 'auto_reply_actor_id' => null];
            }

            $ticket = SupportTicket::create([
                'user_id' => $actor->id,
                'created_by' => $actor->id,
                'trip_id' => $lockedTrip->id,
                'subject' => $subject,
                'type' => 'report',
                'source' => SupportTicket::SOURCE_WEB_FORM,
                'priority' => SupportTicket::TYPE_DEFAULT_PRIORITIES['report'],
                'status' => 'Open',
                'unread_for_admin' => 1,
                'unread_for_user' => 0,
                'last_reply_at' => now(),
            ]);
            SupportTicketReply::create([
                'ticket_id' => $ticket->id,
                'user_id' => $actor->id,
                'is_admin' => false,
                'message_markdown' => $message,
                'created_by' => $actor->id,
            ]);
            $addedOpeningAutoReply = $this->supportTickets->appendOpeningAutoReply($ticket);

            DB::table('mobility_trip_report_requests')->insert([
                'trip_id' => $lockedTrip->id,
                'actor_id' => $actor->id,
                'idempotency_key' => $key,
                'request_fingerprint' => $fingerprint,
                'ticket_id' => $ticket->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return (object) ['ticket' => $ticket->fresh(), 'replay' => false, 'auto_reply' => $addedOpeningAutoReply,
                'auto_reply_actor_id' => $addedOpeningAutoReply ? SupportTicketReply::where('ticket_id', $ticket->id)->where('is_admin', true)->orderByDesc('id')->value('user_id') : null];
        });
    }

    public function projection(SupportTicket $ticket): array
    {
        return [
            'id' => (int) $ticket->id,
            'trip_id' => (int) $ticket->trip_id,
            'subject' => (string) $ticket->subject,
            'status' => (string) $ticket->status,
            'type' => (string) $ticket->type,
            'priority' => (string) $ticket->priority,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
        ];
    }

    private function participant(int $tripId, int $actorId, int $ownerId): bool
    {
        if ($actorId === $ownerId) {
            return true;
        }

        return Passenger::query()->where('trip_id', $tripId)->where('user_id', $actorId)
            ->where('passenger_type', Passenger::TYPE_PASAJERO)
            ->whereIn('request_state', [Passenger::STATE_ACCEPTED, Passenger::STATE_WAITING_PAYMENT, Passenger::STATE_CANCELED])->exists();
    }
}

final class TripReportException extends \RuntimeException
{
    public function __construct(public string $errorCode, public int $httpStatus)
    {
        parent::__construct($errorCode);
    }
}
