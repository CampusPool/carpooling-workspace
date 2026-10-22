<?php

namespace STS\Mobility\Journeys;

use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\Trip;

/** Occupancy comes only from canonical accepted Passenger rows. */
final class PrivateIntervalInventory
{
    public static function available(Trip $trip, array $candidate, string $routeHash): int
    {
        $length = $candidate['route_length_meters'] ?? null;
        if (! self::valid($candidate, $routeHash, $length)) return 0;
        $query = DB::table('trip_passengers as booking')->leftJoin('mobility_private_scheduled_bindings as binding', 'binding.booking_id', '=', 'booking.id')
            ->where('booking.trip_id', $trip->id)->where('booking.passenger_type', Passenger::TYPE_PASAJERO)->where('booking.request_state', Passenger::STATE_ACCEPTED)
            ->select('booking.id', 'binding.trip_id as binding_trip', 'binding.interval_snapshot', 'binding.interval_hash');
        // Final admission holds the Trip lock; use a locking current read so an
        // earlier MVCC snapshot cannot omit an acceptance committed before it.
        $rows = DB::transactionLevel() > 0 ? $query->lock('for share nowait')->get() : $query->get();
        $events = [];
        foreach ($rows as $row) {
            $stored = json_decode($row->interval_snapshot ?? 'null', true);
            $valid = (int) $row->binding_trip === (int) $trip->id && is_array($stored)
                && self::valid($stored, $routeHash, $length)
                && is_string($row->interval_hash) && hash_equals($row->interval_hash, CanonicalRouteIntervalProjector::hashInterval($stored));
            // Legacy/missing/tampered metadata conservatively occupies the full
            // route. An invalid binding must never make a passenger disappear.
            $from = $valid ? $stored['from_meters'] : 0;
            $till = $valid ? $stored['till_meters'] : $length;
            $start = max($candidate['from_meters'], $from);
            $end = min($candidate['till_meters'], $till);
            if ($start >= $end) continue;
            $events[$start] = ($events[$start] ?? 0) + 1;
            $events[$end] = ($events[$end] ?? 0) - 1;
        }
        ksort($events, SORT_NUMERIC);
        $occupied = 0; $peak = 0;
        foreach ($events as $delta) { $occupied += $delta; $peak = max($peak, $occupied); }
        return max(0, (int) $trip->total_seats - $peak);
    }

    private static function valid(array $interval, string $hash, mixed $length): bool
    {
        return is_int($length) && $length > 0 && $length <= 100000 && ($interval['version'] ?? null) === 1
            && ($interval['canonical_route_hash'] ?? null) === $hash && ($interval['route_length_meters'] ?? null) === $length
            && is_int($interval['from_meters'] ?? null) && is_int($interval['till_meters'] ?? null)
            && $interval['from_meters'] >= 0 && $interval['from_meters'] < $interval['till_meters'] && $interval['till_meters'] <= $length;
    }
}
