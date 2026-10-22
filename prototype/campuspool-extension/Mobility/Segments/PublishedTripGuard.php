<?php

namespace STS\Mobility\Segments;

use Illuminate\Support\Facades\DB;
use STS\Models\Trip;

final class PublishedTripGuard
{
    public static function input(Trip $trip, array $data): void
    {
        if (array_intersect(array_keys($data), ['points', 'user_id', 'car_id', 'total_seats', 'trip_date', 'weekly_schedule', 'seat_price_cents', 'is_passenger', 'distance', 'estimated_time', 'from_town', 'to_town', 'punto_partida', 'punto_llegada']) && (\STS\Mobility\Journeys\PrivateScheduledAdmissionService::managed((int) $trip->id) || SegmentBookingService::managed((int) $trip->id) || \STS\Mobility\Dispatch\DispatchTripPolicy::managed((int) $trip->id))) {
            throw new SegmentException('QUOTED_OFFER_TERMS_IMMUTABLE');
        }
    }

    public function updating(Trip $trip): void
    {
        self::input($trip, $trip->getDirty());
    }

    public function deleting(Trip $trip): void
    {
        if (\STS\Mobility\Journeys\PrivateScheduledAdmissionService::managed((int) $trip->id) || SegmentBookingService::managed((int) $trip->id) || \STS\Mobility\Dispatch\DispatchTripPolicy::managed((int) $trip->id)) {
            throw new SegmentException('QUOTED_TRIP_DELETE_DENIED');
        }
    }

    public static function point(object $point): void
    {
        if ($point->trip_id && (\STS\Mobility\Journeys\PrivateScheduledAdmissionService::managed((int) $point->trip_id) || DB::table('mobility_pool_publications')->where('trip_id', $point->trip_id)->exists() || \STS\Mobility\Dispatch\DispatchTripPolicy::managed((int) $point->trip_id))) {
            throw new SegmentException('QUOTED_OFFER_TERMS_IMMUTABLE');
        }
    }
}
