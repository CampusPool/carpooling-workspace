<?php

namespace STS\Mobility\Recurrence;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\Trip;

final class ProgramReader
{
    public static function summary(object $program): array
    {
        $route = is_string($program->route_snapshot) ? json_decode($program->route_snapshot, true) : $program->route_snapshot;
        $points = $route['points'] ?? [];

        $pickupPoints = is_array($route['pickup_points'] ?? null) ? array_values($route['pickup_points']) : [];
        $last = max(0, count($points) - 1);

        return ['id' => (string) $program->id, 'version' => (int) $program->version, 'state' => $program->state, 'car_id' => (int) $program->car_id, 'vehicle_type' => $program->vehicle_type,
            'from_name' => (string) ($points[0]['address'] ?? ''), 'to_name' => (string) ($points[$last]['address'] ?? ''),
            'pickup_point_id' => (int) ($pickupPoints[0]['id'] ?? 0), 'dropoff_point_id' => (int) ($pickupPoints[$last]['id'] ?? 0),
            'stop_ids' => array_values(array_map(static fn (array $point): int => (int) ($point['id'] ?? 0), $pickupPoints)),
            'route_schema' => (string) ($route['route_schema'] ?? 'whole-route-v1'),
            'weekly_schedule' => (int) $program->weekly_schedule, 'departure_time' => $program->departure_time,
            'date_from' => $program->date_from, 'date_to' => $program->date_to, 'total_seats' => (int) $program->total_seats,
            'max_fare_minor' => (int) $program->max_fare_minor, 'pricing_version' => $program->pricing_version, 'generated_through' => $program->generated_through,
            'next_edit_from' => $program->generated_through ? CarbonImmutable::parse($program->generated_through, 'Asia/Kolkata')->addDay()->format('Y-m-d') : $program->date_from,
            'synthetic_only' => (bool) ($route['synthetic_only'] ?? false), 'last_generation_code' => $program->last_generation_code];
    }

    public static function occurrences(object $program, ?string $afterDate = null, int $size = 30): array
    {
        $query = DB::table('mobility_recurring_occurrences as occurrence')->join('trips as trip', 'trip.id', '=', 'occurrence.trip_id')
            ->leftJoin('mobility_trip_lifecycles as lifecycle', 'lifecycle.trip_id', '=', 'trip.id')->where('occurrence.program_id', $program->id)
            ->select('occurrence.service_date', 'occurrence.trip_id', 'occurrence.program_version', 'trip.trip_date', 'trip.total_seats', 'trip.seat_price_cents', 'trip.state as trip_state', 'lifecycle.state as lifecycle_state')
            ->selectSub(function ($q): void {
                $q->from('trip_passengers as booking')->selectRaw('COUNT(*)')->whereColumn('booking.trip_id', 'trip.id')->whereColumn('booking.user_id', '<>', 'trip.user_id')->where('booking.request_state', Passenger::STATE_ACCEPTED);
            }, 'accepted_count')->orderBy('occurrence.service_date')->limit(max(1, min(50, $size)));
        if ($afterDate !== null) {
            $query->where('occurrence.service_date', '>', $afterDate);
        }

        return $query->get()->map(fn ($row) => ['service_date' => $row->service_date, 'trip_id' => (int) $row->trip_id, 'program_version' => (int) $row->program_version,
            'departure_local' => CarbonImmutable::parse($row->trip_date, 'Asia/Kolkata')->format('Y-m-d\TH:i:sP'),
            'seats_available' => \STS\Mobility\Segments\SegmentInventory::available(Trip::findOrFail($row->trip_id)), 'total_seats' => (int) $row->total_seats,
            'seat_price_minor' => (int) $row->seat_price_cents, 'lifecycle_state' => $row->lifecycle_state ?? ($row->trip_state === Trip::STATE_CANCELED ? 'cancelled' : 'planned')])->all();
    }
}
