<?php

namespace STS\Mobility\Segments;

use STS\Models\Passenger;

/** Fail-closed backstop for runtime model writers. Raw SQL writers are audited separately. */
final class CanonicalBookingObserver
{
    public function saving(Passenger $booking): void
    {
        $tripId = (int) $booking->trip_id;
        $dispatchContext = \STS\Mobility\Dispatch\DispatchBookingContext::allows($tripId, (int) $booking->user_id);
        $privateScheduled = \STS\Mobility\Journeys\PrivateScheduledAdmissionService::managed($tripId);
        if ($booking->exists && \STS\Mobility\Journeys\PrivateScheduledAdmissionService::managed((int) $booking->getOriginal('trip_id')) && $booking->isDirty(['trip_id','user_id','passenger_type'])) throw new SegmentException('BOOKING_IDENTITY_IMMUTABLE');
        if ($privateScheduled) {
            if (! \STS\Mobility\Journeys\PrivateScheduledAdmissionContext::allows($tripId, (int) $booking->user_id)) throw new SegmentException('PRIVATE_SCHEDULE_ADMISSION_REQUIRED');
            return;
        }
        if ($dispatchContext || \STS\Mobility\Dispatch\DispatchTripPolicy::managed($tripId)
            || ($booking->exists && \STS\Mobility\Dispatch\DispatchTripPolicy::managed((int) $booking->getOriginal('trip_id')))) {
            if (! $booking->exists && $dispatchContext && (int) $booking->request_state === Passenger::STATE_ACCEPTED && (int) $booking->passenger_type === Passenger::TYPE_PASAJERO) {
                return;
            }
            if (! $booking->exists || $booking->isDirty(['trip_id', 'user_id', 'passenger_type', 'request_state'])) {
                throw new \STS\Mobility\Dispatch\DispatchException('DISPATCH_CANONICAL_ACTION_REQUIRED');
            }

            return;
        }
        if (! SegmentBookingService::managed($tripId) && (! $booking->exists || ! SegmentBookingService::managed((int) $booking->getOriginal('trip_id')))) {
            return;
        }
        if ($booking->exists && $booking->isDirty(['trip_id', 'user_id', 'passenger_type'])) {
            throw new SegmentException('BOOKING_IDENTITY_IMMUTABLE');
        }
        $activity = ! $booking->exists || ($booking->isDirty('request_state') && in_array((int) $booking->request_state, [0, 1, 4], true));
        if ($activity && ! BookingIntervalContext::for($tripId, (int) $booking->user_id)) {
            throw new SegmentException('SEGMENT_ADMISSION_REQUIRED');
        }
    }

    public function deleting(Passenger $booking): void
    {
        if (\STS\Mobility\Journeys\PrivateScheduledAdmissionService::managed((int) $booking->trip_id) || SegmentBookingService::managed((int) $booking->trip_id) || \STS\Mobility\Dispatch\DispatchTripPolicy::managed((int) $booking->trip_id)) {
            throw new SegmentException('BOOKING_HISTORY_IMMUTABLE');
        }
    }
}
