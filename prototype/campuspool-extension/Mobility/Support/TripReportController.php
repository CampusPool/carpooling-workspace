<?php

namespace STS\Mobility\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use STS\Models\SupportTicket;
use STS\Models\Trip;
use STS\Models\User;
use STS\Notifications\SupportTicketReplyNotification;
use STS\Support\SupportTicketMessage;

final class TripReportController
{
    public function store(Request $request, Trip $trip, TripReportService $service): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if ($key === '' || strlen($key) > 100 || ! preg_match('/^[\x21-\x7E]+$/', $key)) {
            return $this->error('INVALID_IDEMPOTENCY_KEY', 422);
        }
        if (is_string($request->input('subject'))) {
            $request->merge(['subject' => trim($request->input('subject'))]);
        }
        $validated = $request->validate([
            'subject' => 'required|string|min:3|max:160',
            'message_markdown' => ['required', 'string', 'min:1', 'max:4000', SupportTicketMessage::userContentValidationRule()],
        ]);
        $actor = $request->user();
        if (! $actor) {
            return $this->error('TRIP_REPORT_FORBIDDEN', 403);
        }

        try {
            $result = $service->create($trip, $actor, $key, trim($validated['subject']), $validated['message_markdown']);
            if (! $result->replay && $result->auto_reply) {
                $ticketId = (int) $result->ticket->id;
                $autoReplyActorId = (int) $result->auto_reply_actor_id;
                DB::afterCommit(function () use ($ticketId, $autoReplyActorId): void {
                    $this->notifyBestEffort($ticketId, $autoReplyActorId);
                });
            }

            return response()->json(['data' => $service->projection($result->ticket)]);
        } catch (TripReportException $e) {
            return $this->error($e->errorCode, $e->httpStatus);
        }
    }

    private function notifyBestEffort(int $ticketId, int $actorId): void
    {
        try {
            $ticket = SupportTicket::findOrFail($ticketId);
            $actor = User::findOrFail($actorId);
            $notification = new SupportTicketReplyNotification;
            $notification->setAttribute('ticket', $ticket->fresh());
            $notification->setAttribute('from', $actor);
            $notification->notify($ticket->user);
        } catch (\Throwable $e) {
            Log::warning('trip_report_notification_failed', ['code' => 'NOTIFICATION_FAILED']);
        }
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code]], $status);
    }
}
