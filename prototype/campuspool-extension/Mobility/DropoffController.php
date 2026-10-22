<?php

namespace STS\Mobility;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\Trip;

class DropoffController
{
    public function confirm(Request $request, Trip $trip): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (! preg_match('/^[\x21-\x7E]{1,100}$/D', $key)) {
            return $this->error('INVALID_IDEMPOTENCY_KEY', 422);
        }

        return DB::transaction(function () use ($request, $trip, $key): JsonResponse {
            $lockedTrip = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $userId = (int) $request->user()->id;
            if ((int) $lockedTrip->user_id === $userId) {
                return $this->error('RIDER_CONFIRMATION_REQUIRED', 403);
            }
            $bookings = Passenger::query()->where('trip_id', $lockedTrip->id)
                ->where('user_id', $userId)->where('passenger_type', Passenger::TYPE_PASAJERO)
                ->where('request_state', Passenger::STATE_ACCEPTED)->lockForUpdate()->get();
            if ($bookings->count() !== 1) {
                return $this->error('ACCEPTED_BOOKING_REQUIRED', 403);
            }
            $booking = $bookings->first();
            $existing = DB::table('mobility_dropoff_confirmations')->where('booking_id', $booking->id)->first();
            if ($existing) {
                if (! hash_equals($existing->idempotency_key, $key)) {
                    return $this->error('DROPOFF_ALREADY_CONFIRMED', 409);
                }

                return response()->json(json_decode($existing->response_body, true, 512, JSON_THROW_ON_ERROR));
            }
            $state = DB::table('mobility_trip_lifecycles')->where('trip_id', $lockedTrip->id)->value('state');
            $participant = DB::table('mobility_trip_participants')->where('trip_id', $lockedTrip->id)
                ->where('booking_id', $booking->id)->where('user_id', $userId)->first();
            if (! in_array($state, ['in_progress', 'completed'], true) || $participant?->state !== 'alighted'
                || $lockedTrip->state === Trip::STATE_CANCELED) {
                return $this->error('DROPOFF_NOT_READY', 409);
            }
            $alightEvent = DB::table('mobility_trip_events')->where('trip_id', $lockedTrip->id)
                ->where('passenger_id', $userId)->where('action', 'alight')->orderByDesc('version')->first();
            if (! $alightEvent) {
                return $this->error('DROPOFF_EVIDENCE_MISSING', 409);
            }
            $confirmedAt = now()->utc();
            $body = ['trip_id' => (int) $lockedTrip->id, 'booking_id' => (int) $booking->id,
                'user_id' => $userId, 'confirmed_after_event_id' => (int) $alightEvent->id,
                'confirmed_at' => $confirmedAt->toIso8601String(), 'confirmation' => 'rider_dropoff'];
            DB::table('mobility_dropoff_confirmations')->insert([
                'trip_id' => $lockedTrip->id, 'booking_id' => $booking->id, 'user_id' => $userId,
                'idempotency_key' => $key, 'confirmed_after_event_id' => $alightEvent->id,
                'confirmed_at' => $confirmedAt, 'response_body' => json_encode($body, JSON_THROW_ON_ERROR),
            ]);

            return response()->json($body);
        });
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code]], $status);
    }
}
