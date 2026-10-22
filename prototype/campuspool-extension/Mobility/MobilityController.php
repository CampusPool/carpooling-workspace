<?php

namespace STS\Mobility;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\Trip;

class MobilityController
{
    public function show(Request $request, Trip $trip): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $this->canView($trip, (int) $actor->id, (bool) $actor->is_admin)) {
            return $this->error('TRIP_FORBIDDEN', 'You are not a participant in this trip.', 403);
        }

        return response()->json($this->snapshot($trip, false, (int) $actor->id));
    }

    public function operatorShow(Request $request, Trip $trip): JsonResponse
    {
        if (! $request->user()?->is_admin) {
            return $this->error('OPERATOR_FORBIDDEN', 'Administrator access is required.', 403);
        }

        return response()->json($this->snapshot($trip, true));
    }

    public function transition(Request $request, Trip $trip): JsonResponse
    {
        if (DB::table('mobility_commute_demo_trips')->where('trip_id',$trip->id)->exists() && ! $request->attributes->get('commute.canonical_transition')) return $this->error('COMMUTE_DEMO_ACTION_REQUIRED','Use the commute trip controls.',403);
        $key = (string) $request->header('Idempotency-Key', '');
        if ($key === '' || strlen($key) > 100 || ! preg_match('/^[\x21-\x7E]+$/', $key)) {
            return $this->error('INVALID_IDEMPOTENCY_KEY', 'Idempotency-Key must be 1-100 printable ASCII characters.', 422);
        }

        $action = $request->input('action');
        $passengerId = $request->input('passenger_id');
        if (! in_array($action, ['arrive', 'start', 'board', 'alight', 'complete', 'cancel'], true)) {
            return $this->error('INVALID_ACTION', 'The transition action is invalid.', 422);
        }
        if (in_array($action, ['board', 'alight'], true) && filter_var($passengerId, FILTER_VALIDATE_INT) === false) {
            return $this->error('PASSENGER_REQUIRED', 'passenger_id is required for this action.', 422);
        }
        if (! in_array($action, ['board', 'alight'], true)) {
            $passengerId = null;
        } else {
            $passengerId = (int) $passengerId;
        }

        $actor = $request->user();
        $fingerprint = hash('sha256', json_encode(['action' => $action, 'passenger_id' => $passengerId], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($trip, $actor, $key, $action, $passengerId, $fingerprint): JsonResponse {
            $lockedTrip = Trip::query()->whereKey($trip->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $lockedTrip->user_id !== (int) $actor->id && ! ($action === 'cancel' && \STS\Mobility\Dispatch\DispatchTripPolicy::canCancel($lockedTrip, (int) $actor->id))) {
                return $this->error('TRIP_OWNER_REQUIRED', 'Only the trip owner may change its lifecycle.', 403);
            }
            $existing = DB::table('mobility_trip_events')->where([
                'trip_id' => $lockedTrip->id,
                'actor_id' => $actor->id,
                'idempotency_key' => $key,
            ])->first();
            if ($existing) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    return $this->error('IDEMPOTENCY_CONFLICT', 'The idempotency key was already used for another request.', 409);
                }

                return response()->json(json_decode($existing->response_body, true, 512, JSON_THROW_ON_ERROR), $existing->response_status);
            }

            $lifecycle = DB::table('mobility_trip_lifecycles')->where('trip_id', $lockedTrip->id)->lockForUpdate()->first();
            $failure = $this->validateTransition($lockedTrip, $lifecycle?->state ?? 'planned', $action, $passengerId);
            if ($failure) {
                return $this->error($failure[0], $failure[1], $failure[2]);
            }
            if (! $lifecycle) {
                $lifecycleId = DB::table('mobility_trip_lifecycles')->insertGetId([
                    'trip_id' => $lockedTrip->id, 'state' => 'planned', 'version' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $lifecycle = DB::table('mobility_trip_lifecycles')->where('id', $lifecycleId)->lockForUpdate()->first();
            }

            $nextState = match ($action) {
                'start' => 'in_progress', 'complete' => 'completed', 'cancel' => 'cancelled', default => $lifecycle->state,
            };
            if (in_array($action, ['board', 'alight'], true)) {
                $booking = Passenger::query()->where('trip_id', $lockedTrip->id)->where('user_id', $passengerId)->where('request_state', Passenger::STATE_ACCEPTED)->lockForUpdate()->first();
                if (! $booking) {
                    return $this->error('ACCEPTED_BOOKING_REQUIRED', 'The passenger booking is no longer accepted.', 409);
                }
                DB::table('mobility_trip_participants')->updateOrInsert(
                    ['trip_id' => $lockedTrip->id, 'booking_id' => $booking->id],
                    ['user_id' => $passengerId, 'state' => $action === 'board' ? 'boarded' : 'alighted', 'updated_at' => now(), 'created_at' => now()]
                );
            }
            if ($action === 'cancel') {
                if (\STS\Mobility\Segments\SegmentBookingService::managed((int) $lockedTrip->id) || \STS\Mobility\Dispatch\DispatchTripPolicy::managed((int) $lockedTrip->id)) {
                    $affected = Passenger::query()->where('trip_id', $lockedTrip->id)->where('user_id', '<>', $lockedTrip->user_id)->whereIn('request_state', [Passenger::STATE_PENDING, Passenger::STATE_ACCEPTED, Passenger::STATE_WAITING_PAYMENT])->orderBy('id')->lockForUpdate()->get();
                    foreach ($affected as $booking) {
                        app(\STS\Mobility\Payments\PaymentService::class)->blockRefundOrDispute($lockedTrip, $booking, 'trip_cancelled');
                    }
                }
                Passenger::query()->where('trip_id', $lockedTrip->id)->whereIn('request_state', [Passenger::STATE_PENDING, Passenger::STATE_ACCEPTED, Passenger::STATE_WAITING_PAYMENT])->update(['request_state' => Passenger::STATE_CANCELED]);
                $lockedTrip->newQuery()->whereKey($lockedTrip->id)->update(['state' => Trip::STATE_CANCELED]);
                $this->stopLiveShares($lockedTrip->id);
            }
            if ($action === 'complete') {
                $this->stopLiveShares($lockedTrip->id);
            }

            $version = (int) $lifecycle->version + 1;
            DB::table('mobility_trip_lifecycles')->where('id', $lifecycle->id)->update(['state' => $nextState, 'version' => $version, 'updated_at' => now()]);
            $body = ['trip_id' => (int) $lockedTrip->id, 'state' => $nextState, 'version' => $version, 'action' => $action, 'passenger_id' => $passengerId];
            $eventId = DB::table('mobility_trip_events')->insertGetId([
                'trip_id' => $lockedTrip->id, 'actor_id' => $actor->id, 'action' => $action, 'passenger_id' => $passengerId,
                'idempotency_key' => $key, 'request_fingerprint' => $fingerprint, 'version' => $version,
                'response_status' => 200, 'response_body' => json_encode($body, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $body['event_id'] = $eventId;
            DB::table('mobility_trip_events')->where('id', $eventId)->update(['response_body' => json_encode($body, JSON_THROW_ON_ERROR)]);

            return response()->json($body);
        });
    }

    private function validateTransition(Trip $trip, string $state, string $action, ?int $passengerId): ?array
    {
        $allowed = ['planned' => ['arrive', 'start', 'cancel'], 'in_progress' => ['board', 'alight', 'complete'], 'completed' => [], 'cancelled' => []];
        if (! in_array($action, $allowed[$state] ?? [], true)) {
            return ['INVALID_STATE_TRANSITION', 'The action is not valid for the current lifecycle state.', 409];
        }
        $dispatch = \STS\Mobility\Dispatch\DispatchTripPolicy::managed((int) $trip->id);
        $scheduled = \STS\Mobility\Journeys\PrivateScheduledAdmissionService::managed((int) $trip->id);
        if (in_array($action, ['arrive','start'], true) && $scheduled) app(\STS\Mobility\Journeys\PrivateScheduledAdmissionService::class)->assertStartable($trip);
        if ($action === 'arrive' && ! $dispatch && ! $scheduled) {
            return ['DISPATCH_TRIP_REQUIRED', 'Arrival is only available for an assigned on-demand trip.', 409];
        }
        if (in_array($action, ['arrive', 'start'], true) && $dispatch) {
            \STS\Mobility\Dispatch\DispatchTripPolicy::assertStartable($trip);
        }
        if ($action === 'arrive' && DB::table('mobility_trip_events')->where('trip_id', $trip->id)->where('action', 'arrive')->exists()) {
            return ['DRIVER_ALREADY_ARRIVED', 'Driver arrival has already been recorded.', 409];
        }
        if ($action === 'start' && ! in_array($trip->state, [null, Trip::STATE_READY, Trip::STATE_PAID], true)) {
            return ['LEGACY_TRIP_NOT_STARTABLE', 'The legacy trip is not in a startable state.', 409];
        }
        if ($action === 'start' && ! $dispatch && method_exists($trip, 'expired') && $trip->expired()) {
            return ['TRIP_EXPIRED', 'An expired trip cannot be started.', 409];
        }
        if (in_array($action, ['board', 'alight'], true)) {
            $booking = Passenger::query()->where('trip_id', $trip->id)->where('user_id', $passengerId)->where('request_state', Passenger::STATE_ACCEPTED)->lockForUpdate()->first();
            if (! $booking) {
                return ['ACCEPTED_BOOKING_REQUIRED', 'The passenger does not have an accepted booking.', 409];
            }
            $participant = DB::table('mobility_trip_participants')->where('trip_id', $trip->id)->where('user_id', $passengerId)->first();
            if ($action === 'board' && $participant?->state === 'boarded') {
                return ['ALREADY_BOARDED', 'The passenger is already boarded.', 409];
            }
            if ($action === 'board' && $participant?->state === 'alighted') {
                return ['ALREADY_ALIGHTED', 'An alighted passenger cannot board again.', 409];
            }
            if ($action === 'board' && DB::table('mobility_trip_participants')->where('trip_id', $trip->id)->where('state', 'boarded')->lockForUpdate()->get()->count() >= (int) $trip->total_seats) {
                return ['BOARDING_CAPACITY_EXCEEDED', 'Alight the previous rider before boarding another passenger.', 409];
            }
            if ($action === 'alight' && $participant?->state !== 'boarded') {
                return ['PASSENGER_NOT_BOARDED', 'The passenger must be boarded before alighting.', 409];
            }
        }
        if ($action === 'complete' && DB::table('trip_passengers')->where('trip_id', $trip->id)->where('passenger_type', Passenger::TYPE_PASAJERO)->where('request_state', Passenger::STATE_ACCEPTED)->whereNotExists(function ($q) use ($trip) {
            $q->from('mobility_trip_participants')->whereColumn('mobility_trip_participants.user_id', 'trip_passengers.user_id')->where('mobility_trip_participants.trip_id', $trip->id)->where('mobility_trip_participants.state', 'alighted');
        })->exists()) {
            return ['PASSENGERS_NOT_ALIGHTED', 'Every accepted passenger must alight before completion.', 409];
        }

        return null;
    }

    private function canView(Trip $trip, int $userId, bool $isAdmin = false): bool
    {
        return $isAdmin || (int) $trip->user_id === $userId || DB::table('trip_passengers')->where('trip_id', $trip->id)->where('user_id', $userId)->whereIn('request_state', [Passenger::STATE_ACCEPTED, Passenger::STATE_WAITING_PAYMENT, Passenger::STATE_CANCELED])->exists();
    }

    private function snapshot(Trip $trip, bool $operator = false, ?int $actorId = null): array
    {
        $lifecycle = DB::table('mobility_trip_lifecycles')->where('trip_id', $trip->id)->first();
        $events = DB::table('mobility_trip_events')->where('trip_id', $trip->id)->orderBy('version')->get()->map(fn ($event) => ['id' => (int) $event->id, 'action' => $event->action, 'actor_id' => (int) $event->actor_id, 'passenger_id' => $event->passenger_id ? (int) $event->passenger_id : null, 'version' => (int) $event->version, 'created_at' => $event->created_at])->values()->all();
        $participantsQuery = DB::table('trip_passengers')->where('trip_id', $trip->id);
        if (! $operator) {
            $participantsQuery->whereIn('request_state', [Passenger::STATE_ACCEPTED, Passenger::STATE_WAITING_PAYMENT, Passenger::STATE_CANCELED]);
            if ($actorId !== null && (int) $trip->user_id !== $actorId) {
                $participantsQuery->where('user_id', $actorId);
            }
        }
        $participants = $participantsQuery->get()->map(function ($booking) use ($trip) {
            $state = DB::table('mobility_trip_participants')->where('trip_id', $trip->id)->where('user_id', $booking->user_id)->value('state') ?? 'accepted';

            return ['booking_id' => (int) $booking->id, 'passenger_id' => (int) $booking->user_id, 'booking_state' => (int) $booking->request_state, 'state' => $state];
        })->values()->all();

        return ['trip_id' => (int) $trip->id, 'owner_id' => (int) $trip->user_id, 'state' => $lifecycle?->state ?? ($trip->state === Trip::STATE_CANCELED ? 'cancelled' : 'planned'), 'version' => (int) ($lifecycle?->version ?? 0), 'participants' => $participants, 'events' => $operator ? $events : []];
    }

    private function stopLiveShares(int $tripId): void
    {
        DB::table('trip_live_shares')->where('trip_id', $tripId)->where('is_active', true)->update([
            'is_active' => false,
            'lat' => null,
            'lng' => null,
            'recorded_at' => null,
            'sample_recorded_at' => null,
            'accuracy_meters' => null,
            'driver_session_id' => null,
            'consented_at' => null,
            'stopped_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
