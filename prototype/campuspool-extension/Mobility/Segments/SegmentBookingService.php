<?php

namespace STS\Mobility\Segments;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use STS\Mobility\Payments\PaymentService;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;

final class SegmentBookingService
{
    public static function managed(int $tripId): bool
    {
        return DB::table('mobility_pool_publications')->where('trip_id', $tripId)->exists();
    }

    public function newRequest(int $tripId, User $rider, array $data = []): ?Passenger
    {
        return DB::transaction(function () use ($tripId, $rider): ?Passenger {
            $trip = Trip::query()->whereKey($tripId)->lockForUpdate()->first();
            if (! $trip) {
                return null;
            }
            $terms = RouteTerms::forTrip($tripId);
            if (! $terms) {
                return null;
            }
            $this->activity($trip, (int) $rider->id, $terms);
            if (config('carpoolear.module_trip_seats_payment', false)) {
                throw new SegmentException('LEGACY_PAYMENT_PROMOTION_DISABLED');
            }
            if (Passenger::where('trip_id', $tripId)->where('user_id', $rider->id)->whereIn('request_state', [0, 1, 4])->exists()) {
                return null;
            }
            $context = BookingIntervalContext::for($tripId, (int) $rider->id);
            $from = $context['from_index'] ?? 0;
            $till = $context['till_index'] ?? count($terms['stops']) - 1;
            $fare = RouteTerms::fare($terms, $from, $till);
            if (isset($context['route_hash']) && (! hash_equals($terms['hash'], $context['route_hash']) || $terms['quote_id'] !== $context['quote_id'] || $fare !== $context['fare_minor'])) {
                throw new SegmentException('MATCH_REFERENCE_STALE');
            }

            return BookingIntervalContext::run(($context ?? []) + ['trip_id' => $tripId, 'user_id' => (int) $rider->id], function () use ($tripId, $rider, $from, $till, $fare, $terms, $context): Passenger {
                $booking = Passenger::create(['trip_id' => $tripId, 'user_id' => $rider->id, 'request_state' => Passenger::STATE_PENDING, 'passenger_type' => Passenger::TYPE_PASAJERO]);
                DB::table('mobility_booking_intervals')->insert(['booking_id' => $booking->id, 'trip_id' => $tripId, 'quote_id' => $terms['quote_id'], 'from_index' => $from, 'till_index' => $till, 'fare_minor' => $fare, 'route_hash' => $terms['hash'], 'match_reference_id' => $context['match_reference_id'] ?? null, 'created_at' => now(), 'updated_at' => now()]);

                return $booking;
            });
        });
    }

    public function acceptRequest(int $tripId, int $riderId): ?Passenger
    {
        return DB::transaction(function () use ($tripId, $riderId): ?Passenger {
            $trip = Trip::query()->whereKey($tripId)->lockForUpdate()->first();
            if (! $trip) {
                return null;
            }
            $terms = RouteTerms::forTrip($tripId);
            if (! $terms) {
                return null;
            }
            \STS\Mobility\Admission\DriverReservationPolicy::fence((int) $trip->user_id);
            $this->activity($trip, $riderId, $terms);
            $booking = Passenger::query()->where('trip_id', $tripId)->where('user_id', $riderId)->where('request_state', Passenger::STATE_PENDING)->where('passenger_type', Passenger::TYPE_PASAJERO)->lockForUpdate()->first();
            if (! $booking) {
                return null;
            }
            \STS\Mobility\Admission\DriverReservationPolicy::scheduled($trip, $terms);
            $interval = $this->interval($booking, $terms);
            if (SegmentInventory::available($trip, $terms, $interval['from_index'], $interval['till_index']) < 1) {
                return null;
            }

            return BookingIntervalContext::run(['trip_id' => $tripId, 'user_id' => $riderId, 'booking_id' => (int) $booking->id], function () use ($booking): Passenger {
                $booking->request_state = Passenger::STATE_ACCEPTED;
                $booking->save();

                return $booking;
            });
        });
    }

    public function hasCapacity(Trip $trip, int $riderId): bool
    {
        if (\STS\Mobility\Journeys\PrivateScheduledAdmissionService::managed((int) $trip->id)) {
            $binding = DB::table('mobility_private_scheduled_bindings as binding')->join('trip_passengers as b', 'b.id', '=', 'binding.booking_id')
                ->where('b.trip_id', $trip->id)->where('b.user_id', $riderId)->where('b.request_state', Passenger::STATE_PENDING)->first(['binding.interval_snapshot', 'binding.interval_hash']);
            if (! $binding || $binding->interval_hash === null) throw new SegmentException('PRIVATE_SCHEDULE_CONSENT_REFRESH_REQUIRED', 409);
            $interval = json_decode($binding->interval_snapshot ?? 'null', true);
            if (! is_array($interval) || ! hash_equals($binding->interval_hash, \STS\Mobility\Journeys\CanonicalRouteIntervalProjector::hashInterval($interval))) throw new SegmentException('PRIVATE_CONSENT_CHANGED', 409);
            $hash = DB::table('mobility_private_scheduled_publications')->where('trip_id', $trip->id)->value('route_hash');
            return \STS\Mobility\Journeys\PrivateIntervalInventory::available($trip, $interval, $hash) > 0;
        }
        $terms = RouteTerms::forTrip((int) $trip->id);
        if (! $terms) {
            return (int) $trip->seats_available > 0;
        }
        $booking = Passenger::where('trip_id', $trip->id)->where('user_id', $riderId)->where('request_state', Passenger::STATE_PENDING)->first();
        if (! $booking) {
            return false;
        }
        $interval = $this->interval($booking, $terms);

        return SegmentInventory::available($trip, $terms, $interval['from_index'], $interval['till_index']) > 0;
    }

    public function changeState(int $tripId, int $riderId, int $newState, array $criteria, ?int $cancelled): ?Passenger
    {
        if (! in_array($newState, [Passenger::STATE_CANCELED, Passenger::STATE_REJECTED], true)) {
            throw new SegmentException('LEGACY_PAYMENT_PROMOTION_DISABLED');
        }

        return DB::transaction(function () use ($tripId, $riderId, $newState, $criteria, $cancelled): ?Passenger {
            $trip = Trip::query()->whereKey($tripId)->lockForUpdate()->first();
            if (! $trip) {
                return null;
            }
            $query = Passenger::where('trip_id', $tripId)->where('user_id', $riderId)->where('passenger_type', Passenger::TYPE_PASAJERO);
            foreach ($criteria as $field => $value) {
                $query->where($field, $value);
            }
            $booking = $query->lockForUpdate()->first();
            if (! $booking) {
                return null;
            }

            return BookingIntervalContext::run(['trip_id' => $tripId, 'user_id' => $riderId, 'booking_id' => (int) $booking->id], function () use ($booking, $trip, $newState, $cancelled): Passenger {
                $booking->request_state = $newState;
                $booking->canceled_state = $cancelled;
                $booking->save();
                app(PaymentService::class)->blockRefundOrDispute($trip, $booking, 'booking_cancelled_or_rejected');

                return $booking;
            });
        });
    }

    public function interval(Passenger $booking, array $terms): array
    {
        $row = DB::table('mobility_booking_intervals')->where('booking_id', $booking->id)->first();
        if (! $row) {
            return ['from_index' => 0, 'till_index' => count($terms['stops']) - 1, 'fare_minor' => $terms['snapshot']['seat_price_minor']];
        }
        if ((int) $row->trip_id !== (int) $booking->trip_id || $row->quote_id !== $terms['quote_id'] || ! hash_equals($row->route_hash, $terms['hash']) || (int) $row->fare_minor !== RouteTerms::fare($terms, (int) $row->from_index, (int) $row->till_index)) {
            throw new SegmentException('MATCH_REFERENCE_STALE');
        }

        return ['from_index' => (int) $row->from_index, 'till_index' => (int) $row->till_index, 'fare_minor' => (int) $row->fare_minor];
    }

    public function activity(Trip $trip, int $riderId, array $terms): string
    {
        if (config('app.timezone') !== 'Asia/Kolkata') {
            throw new SegmentException('MOBILITY_TIMEZONE_NOT_CONFIGURED', 503);
        }
        if ($riderId === (int) $trip->user_id || ! CohortPolicy::approved($riderId)) {
            throw new SegmentException('MEMBERSHIP_REQUIRED', 403);
        }
        if ($trip->state !== Trip::STATE_READY || $trip->trashed() || (int) $trip->friendship_type_id !== Trip::PRIVACY_PUBLIC || $trip->is_passenger || CarbonImmutable::parse($trip->trip_date, 'Asia/Kolkata')->lte(CarbonImmutable::now('Asia/Kolkata'))) {
            throw new SegmentException('MATCH_TRIP_NOT_PLANNED');
        }
        $occ = DB::table('mobility_recurring_occurrences')->where('trip_id', $trip->id)->first();
        if ($occ) {
            $program = DB::table('mobility_recurring_programs')->where('id', $occ->program_id)->lockForUpdate()->first();
            if (! $program || $program->state !== 'active') {
                throw new SegmentException('PROGRAM_PAUSED');
            }
            if (DB::table('mobility_recurring_exceptions')->where(['program_id' => $occ->program_id, 'service_date' => $occ->service_date, 'action' => 'skip'])->exists()) {
                throw new SegmentException('PROGRAM_DATE_SKIPPED');
            }
        }
        $state = DB::table('mobility_trip_lifecycles')->where('trip_id', $trip->id)->value('state');
        if ($state !== null && $state !== 'planned') {
            throw new SegmentException('MATCH_TRIP_NOT_PLANNED');
        }

        $fingerprint = RouteTerms::current($trip, $terms, $riderId);
        if (\STS\Mobility\Communications\PairBlockPolicy::blocked($riderId, (int) $trip->user_id)) throw new SegmentException('PARTICIPANT_BLOCKED', 403);
        return $fingerprint;
    }
}
