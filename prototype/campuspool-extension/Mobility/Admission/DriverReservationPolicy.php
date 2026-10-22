<?php

namespace STS\Mobility\Admission;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use STS\Mobility\Segments\RouteTerms;
use STS\Mobility\Segments\SegmentException;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;

/** Admission fence derived from canonical accepted obligations; no busy counter. */
final class DriverReservationPolicy
{
    public static function fence(int $driverId): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Driver admission requires a transaction');
        self::current(fn () => User::whereKey($driverId)->lock('for update nowait')->firstOrFail());
    }

    public static function scheduled(Trip $trip, array $terms): void
    {
        $start = CarbonImmutable::parse($trip->trip_date, 'Asia/Kolkata')->utc();
        self::check((int) $trip->user_id, (int) $trip->id, $start, self::duration($terms['snapshot']['duration_seconds'] ?? null));
    }

    public static function starting(Trip $trip, int $duration): void
    {
        self::check((int) $trip->user_id, (int) $trip->id, CarbonImmutable::now('UTC'), self::duration($duration));
    }

    public static function immediate(int $driverId, array $terms, CarbonImmutable $now): void
    {
        // The normalized private canonical route describes only the occupied
        // leg. Reserve the complete agreed driver path, including approach.
        $duration = ($terms['private'] ?? false)
            ? ($terms['private_snapshot']['driver_path']['duration_seconds'] ?? null)
            : ($terms['snapshot']['duration_seconds'] ?? null);
        self::check($driverId, null, $now->utc(), self::duration($duration));
    }

    private static function check(int $driverId, ?int $ignoreTrip, CarbonImmutable $start, int $duration): void
    {
        $buffer = filter_var(config('mobility_routing.driver_reservation_buffer_seconds', 300), FILTER_VALIDATE_INT);
        if ($buffer === false || $buffer < 0 || $buffer > 3600) throw new SegmentException('DRIVER_WINDOW_NOT_CONFIGURED', 503);
        $candidateStart = $start->subSeconds((int) $buffer);
        $candidateEnd = $start->addSeconds($duration + (int) $buffer);
        // A locking JOIN is a current read. A snapshot EXISTS/subquery can miss
        // an acceptance committed before this transaction acquired its fence.
        $rows = self::current(fn () => DB::table('trips as t')->join('trip_passengers as p', 'p.trip_id', '=', 't.id')
            ->leftJoin('mobility_trip_lifecycles as l', 'l.trip_id', '=', 't.id')
            ->where('t.user_id', $driverId)->whereNull('t.deleted_at')->where('t.state', '!=', Trip::STATE_CANCELED)
            ->where('p.passenger_type', Passenger::TYPE_PASAJERO)->where('p.request_state', Passenger::STATE_ACCEPTED)
            ->when($ignoreTrip !== null, fn ($q) => $q->where('t.id', '!=', $ignoreTrip))
            ->where(fn ($q) => $q->whereNull('l.state')->orWhereNotIn('l.state', ['completed', 'cancelled']))
            ->orderBy('t.id')->orderBy('p.id')->select('t.id', 't.trip_date', 'l.state as lifecycle_state')->lock('for share nowait')->get());
        $seen = [];
        foreach ($rows as $row) {
            $id = (int) $row->id; if (isset($seen[$id])) continue; $seen[$id] = true;
            $assignment = self::current(fn () => DB::table('mobility_dispatch_assignments')->where('trip_id', $id)->lock('for share nowait')->first());
            // A running/overdue accepted obligation must be explicitly resolved;
            // its estimated end is not evidence that the driver became free.
            $otherStart = CarbonImmutable::parse($row->trip_date, 'Asia/Kolkata')->utc();
            if ($assignment || $row->lifecycle_state === 'in_progress' || $otherStart->lte(CarbonImmutable::now('UTC'))) throw new SegmentException('DRIVER_ACTIVE_TRIP');
            $private = self::current(fn () => DB::table('mobility_private_scheduled_publications')->where('trip_id', $id)->lock('for share nowait')->first());
            $commute = self::current(fn () => DB::table('mobility_commute_demo_trips')->where('trip_id', $id)->lock('for share nowait')->first());
            $commuteMatch = $commute ? self::current(fn () => DB::table('mobility_commute_booking_terms as b')->join('trip_passengers as p','p.id','=','b.booking_id')->join('mobility_commute_matches as m','m.match_key','=','b.match_key')->where('p.trip_id',$id)->where('p.request_state',Passenger::STATE_ACCEPTED)->select('m.journey')->lock('for share nowait')->first()) : null;
            $commutePath = $commuteMatch ? json_decode($commuteMatch->journey,true,512,JSON_THROW_ON_ERROR)['driver_path'] : ($commute ? (json_decode($commute->journey,true,512,JSON_THROW_ON_ERROR)['driver_path']??null) : null);
            $other = $commutePath ? ['snapshot'=>$commutePath] : ($private ? ['snapshot' => json_decode($private->driver_path, true, 512, JSON_THROW_ON_ERROR)] : self::current(fn () => RouteTerms::forTrip($id, true)));
            if (! $other) throw new SegmentException('DRIVER_UNRESOLVED_TRIP');
            $otherEnd = $otherStart->addSeconds(self::duration($other['snapshot']['duration_seconds'] ?? null) + (int) $buffer);
            if ($candidateStart->lt($otherEnd) && $otherStart->subSeconds((int) $buffer)->lt($candidateEnd)) throw new SegmentException('DRIVER_ROUTE_OVERLAP');
        }
    }
    private static function duration(mixed $value): int
    {
        if (! is_int($value) || $value < 1 || $value > 86400) throw new SegmentException('DRIVER_OBLIGATION_INVALID');
        return $value;
    }
    private static function current(callable $read): mixed
    {
        try { return $read(); }
        catch (QueryException $e) { if ((int) ($e->errorInfo[1] ?? 0) === 3572) throw new SegmentException('DRIVER_ADMISSION_BUSY'); throw $e; }
    }
}
