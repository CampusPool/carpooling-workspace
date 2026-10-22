<?php

namespace STS\Mobility\Routing;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use STS\Mobility\Trust\CohortPolicy;
use STS\Mobility\Trust\DriverOnboardingPolicy;
use STS\Models\Car;
use STS\Models\Passenger;
use STS\Models\Trip;

/**
 * Read-only, bounded discovery of published fixed-date pool offers.
 *
 * The publication and quote joins are deliberately part of the candidate
 * query. A publication is the only trusted link between a canonical trip and
 * its immutable route terms; the quote expiration is irrelevant after commit.
 */
final class OfferDiscoveryController
{
    private const PAGE_DEFAULT = 20;

    private const PAGE_MAX = 50;

    private const CHUNK = 100;

    private const MAX_SCAN = 5000;

    public function index(Request $request): JsonResponse
    {
        $payload = $request->query();
        $allowed = ['service_date', 'from_time', 'to_time', 'pickup_point_id', 'dropoff_point_id', 'vehicle_type', 'page_size', 'after'];
        if (array_diff(array_keys($payload), $allowed) !== []) {
            return $this->error('DISCOVERY_QUERY_INVALID');
        }

        $college = CohortPolicy::college();
        if (! $college) {
            return $this->error('COHORT_NOT_CONFIGURED', 503);
        }
        $actor = $request->user();
        if (! CohortPolicy::activeUser($actor)) {
            return $this->error('ACCOUNT_RESTRICTED', 403);
        }
        if (! CohortPolicy::approved((int) $actor->id)) {
            return $this->error('MEMBERSHIP_REQUIRED', 403);
        }

        $date = $this->date($payload['service_date'] ?? null);
        if (! $date) {
            return $this->error('DISCOVERY_QUERY_INVALID');
        }
        $today = CarbonImmutable::now('Asia/Kolkata')->startOfDay();
        if ($date->lt($today) || $date->gt($today->addDays(30))) {
            return $this->error('DISCOVERY_QUERY_INVALID');
        }

        $from = $this->time($payload['from_time'] ?? null);
        $to = $this->time($payload['to_time'] ?? null);
        if (($payload['from_time'] ?? null) !== null && $from === null || ($payload['to_time'] ?? null) !== null && $to === null) {
            return $this->error('DISCOVERY_QUERY_INVALID');
        }
        if ($from !== null && $to !== null && $from > $to) {
            return $this->error('DISCOVERY_QUERY_INVALID');
        }
        $pageSize = $this->positiveInt($payload['page_size'] ?? self::PAGE_DEFAULT);
        $after = $this->positiveInt($payload['after'] ?? null, true);
        if ($pageSize === null || $pageSize < 1 || $pageSize > self::PAGE_MAX || (($payload['page_size'] ?? null) !== null && is_array($payload['page_size'] ?? null)) || (($payload['after'] ?? null) !== null && $after === null)) {
            return $this->error('DISCOVERY_QUERY_INVALID');
        }
        foreach (['pickup_point_id', 'dropoff_point_id'] as $key) {
            if (array_key_exists($key, $payload) && $this->positiveInt($payload[$key]) === null) {
                return $this->error('DISCOVERY_QUERY_INVALID');
            }
        }
        $vehicle = $payload['vehicle_type'] ?? null;
        if ($vehicle !== null && (! is_string($vehicle) || ! in_array($vehicle, ['bike', 'car'], true))) {
            return $this->error('DISCOVERY_QUERY_INVALID');
        }

        $now = CarbonImmutable::now('Asia/Kolkata');
        $query = DB::table('trips')
            ->join('mobility_pool_publications as publication', 'publication.trip_id', '=', 'trips.id')
            ->join('mobility_route_quotes as quote', 'quote.id', '=', 'publication.quote_id')
            ->join('users as owner', 'owner.id', '=', 'trips.user_id')
            ->join('mobility_memberships as host_member', function ($join) use ($college): void {
                $join->on('host_member.user_id', '=', 'trips.user_id')->where('host_member.college_code', '=', $college)->where('host_member.status', '=', 'approved');
            })
            ->join('cars as host_car', 'host_car.id', '=', 'trips.car_id')
            ->join('mobility_host_applications as host_approval', function ($join): void {
                $join->on('host_approval.car_id', '=', 'trips.car_id')->on('host_approval.user_id', '=', 'trips.user_id')->where('host_approval.status', '=', 'approved');
            })
            ->whereColumn('host_car.user_id', 'trips.user_id')->whereNull('host_car.deleted_at')
            ->whereColumn('publication.user_id', 'trips.user_id')->whereColumn('quote.user_id', 'trips.user_id')
            ->whereColumn('quote.consumed_trip_id', 'trips.id')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(quote.snapshot, '$.vehicle_type')) = host_approval.vehicle_type")
            ->where(function ($q): void {
                $q->whereNull('trips.weekly_schedule')->orWhere('trips.weekly_schedule', 0);
            })
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('mobility_trip_lifecycles')->whereColumn('mobility_trip_lifecycles.trip_id', 'trips.id')->where('mobility_trip_lifecycles.state', '!=', 'planned');
            })
            ->where('trips.user_id', '<>', $actor->id)
            ->where('owner.active', true)->where('owner.banned', false)
            ->where('trips.is_passenger', false)
            ->where('trips.friendship_type_id', Trip::PRIVACY_PUBLIC)
            ->where('trips.state', Trip::STATE_READY)
            ->whereNull('trips.deleted_at')
            ->where('trips.trip_date', '>=', $now->format('Y-m-d H:i:s'))
            ->whereBetween('trips.trip_date', [$date->format('Y-m-d').' 00:00:00', $date->format('Y-m-d').' 23:59:59'])
            ->where('quote.college_code', $college)
            ->select('trips.*', 'quote.snapshot as quote_snapshot', 'quote.id as quote_id', 'host_approval.id as approval_id', 'host_approval.vehicle_fingerprint as approved_fingerprint')
            ->orderBy('trips.id');
        \STS\Mobility\Recurrence\ProgramVisibility::scope($query);
        if ($after !== null) {
            $query->where('trips.id', '>', $after);
        }
        if ($from !== null) {
            $query->whereTime('trips.trip_date', '>=', $from);
        }
        if ($to !== null) {
            $query->whereTime('trips.trip_date', '<=', $to);
        }
        if ($vehicle !== null) {
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(quote.snapshot, '$.vehicle_type')) = ?", [$vehicle]);
        }
        foreach ([['pickup_point_id', 0], ['dropoff_point_id', 1]] as [$key, $index]) {
            if (array_key_exists($key, $payload)) {
                $path = $index === 0 ? "'$.pickup_points[0].id'" : "CONCAT('$.pickup_points[', JSON_LENGTH(JSON_EXTRACT(quote.snapshot, '$.pickup_points')) - 1, '].id')";
                $query->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(quote.snapshot, $path)) AS UNSIGNED) = ?", [(int) $payload[$key]]);
            }
        }

        $items = [];
        $lastId = null;
        $scanned = 0;
        $query->chunkById(self::CHUNK, function ($rows) use (&$items, &$lastId, &$scanned, $pageSize, $vehicle): bool {
            $cars = Car::whereIn('id', $rows->pluck('car_id')->unique())->get()->keyBy('id');
            $accepted = DB::table('trip_passengers')->whereIn('trip_id', $rows->pluck('id'))->where('request_state', Passenger::STATE_ACCEPTED)->selectRaw('trip_id, COUNT(*) as count')->groupBy('trip_id')->pluck('count', 'trip_id');
            foreach ($rows as $row) {
                if ($scanned >= self::MAX_SCAN) {
                    return false;
                }
                $scanned++;
                $lastId = (int) $row->id;
                $snapshot = json_decode((string) $row->quote_snapshot, true);
                if (! is_array($snapshot) || ! in_array($snapshot['vehicle_type'] ?? null, ['bike', 'car'], true)) {
                    continue;
                }
                $mode = (string) $snapshot['vehicle_type'];
                if ($vehicle !== null && $mode !== $vehicle) {
                    continue;
                }
                $car = $cars->get((int) $row->car_id);
                $approval = DB::table('mobility_host_applications')->where('id', (int) $row->approval_id)->first();
                if (! $car || ! $approval || ! DriverOnboardingPolicy::approvedApplication($approval) || ! $car->isComplete() || CohortPolicy::vehicleFingerprint($car) !== $row->approved_fingerprint) {
                    continue;
                }
                $points = $snapshot['pickup_points'] ?? [];
                if (! is_array($points) || count($points) < 2 || count($points) > 8 || ! isset($points[0]['id'], $points[1]['id'])) {
                    continue;
                }
                try {
                    $available = \STS\Mobility\Segments\SegmentInventory::available(Trip::findOrFail($row->id));
                } catch (\STS\Mobility\Segments\SegmentException) {
                    continue;
                }
                $items[] = [
                    'id' => (int) $row->id,
                    'from_town' => (string) $row->from_town,
                    'to_town' => (string) $row->to_town,
                    'trip_date' => CarbonImmutable::parse($row->trip_date, 'Asia/Kolkata')->format('Y-m-d H:i:s'),
                    'total_seats' => (int) $row->total_seats,
                    'seats_available' => $available,
                    'vehicle_type' => $mode,
                    'seat_price_minor' => (int) ($snapshot['seat_price_minor'] ?? $row->seat_price_cents ?? 0),
                    'currency' => 'INR',
                    'synthetic_only' => (bool) ($snapshot['synthetic_only'] ?? false),
                    'pickup_point_id' => (int) $points[0]['id'],
                    'dropoff_point_id' => (int) $points[count($points) - 1]['id'],
                ];
                if (count($items) > $pageSize) {
                    return false;
                }
            }

            return true;
        }, 'trips.id', 'id');

        $next = null;
        if (count($items) > $pageSize) {
            $next = (string) $items[$pageSize - 1]['id'];
            $items = array_slice($items, 0, $pageSize);
        } elseif ($lastId !== null && $scanned >= self::MAX_SCAN) {
            $next = (string) $lastId;
        }

        return response()->json(['data' => $items, 'next_cursor' => $next]);
    }

    private function error(string $code, int $status = 422): JsonResponse
    {
        return response()->json(['error' => ['code' => $code]], $status);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Kolkata');
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function time(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
            return null;
        }

        return $value.':00';
    }

    private function positiveInt(mixed $value, bool $nullable = false): ?int
    {
        if ($nullable && $value === null) {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9]\d*$/D', $value) && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }

        return null;
    }
}
