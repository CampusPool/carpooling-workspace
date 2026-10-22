<?php

namespace STS\Mobility\Segments;

use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\Trip;

/** Canonical accepted Passenger rows supply occupancy; metadata supplies only immutable boundaries. */
final class SegmentInventory
{
    public static function free(Trip $trip, array $terms): array
    {
        $free = array_fill(0, count($terms['stops']) - 1, max(0, (int) $trip->total_seats));
        $accepted = DB::table('trip_passengers as booking')->leftJoin('mobility_booking_intervals as interval', 'interval.booking_id', '=', 'booking.id')
            ->where('booking.trip_id', $trip->id)->where('booking.user_id', '<>', $trip->user_id)->where('booking.request_state', Passenger::STATE_ACCEPTED)
            ->select('booking.id', 'interval.trip_id as interval_trip', 'interval.from_index', 'interval.till_index', 'interval.quote_id', 'interval.route_hash', 'interval.fare_minor')->get();
        foreach ($accepted as $row) {
            $valid = (int) $row->interval_trip === (int) $trip->id && $row->quote_id === $terms['quote_id'] && $row->route_hash === $terms['hash'] && $row->from_index !== null && $row->till_index !== null && (int) $row->from_index >= 0 && (int) $row->from_index < (int) $row->till_index && (int) $row->till_index < count($terms['stops']);
            if ($valid) {
                $valid = (int) $row->fare_minor === RouteTerms::fare($terms, (int) $row->from_index, (int) $row->till_index);
            }
            $from = $valid ? (int) $row->from_index : 0;
            $till = $valid ? (int) $row->till_index : count($free);
            for ($i = $from; $i < $till; $i++) {
                $free[$i] = max(0, $free[$i] - 1);
            }
        }

        return $free;
    }

    public static function available(Trip $trip, ?array $terms = null, int $from = 0, ?int $till = null): int
    {
        $terms ??= RouteTerms::forTrip((int) $trip->id);
        if ($terms === null) {
            return max(0, (int) $trip->total_seats - $trip->passengerAccepted()->count());
        }
        $till ??= count($terms['stops']) - 1;
        if ($from < 0 || $from >= $till || $till >= count($terms['stops'])) {
            return 0;
        }

        return min(array_slice(self::free($trip, $terms), $from, $till - $from));
    }
}
