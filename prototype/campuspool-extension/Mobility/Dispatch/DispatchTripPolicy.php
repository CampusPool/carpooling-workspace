<?php

namespace STS\Mobility\Dispatch;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\Trip;

/** Read-only dispatch linkage and canonical cancellation/fare guards. */
final class DispatchTripPolicy
{
    public static function assignment(int $tripId): ?object
    {
        return DB::table('mobility_dispatch_assignments')->where('trip_id', $tripId)->first();
    }

    public static function managed(int $tripId): bool
    {
        return self::assignment($tripId) !== null;
    }

    public static function fare(Trip $trip, Passenger $booking): ?int
    {
        $assignment = self::assignment((int) $trip->id);
        if (! $assignment) {
            return null;
        }
        $request = DB::table('mobility_dispatch_requests')->where('id', $assignment->request_id)->first();
        if (! $request || (string) $request->quote_id !== (string) $assignment->quote_id || (string) $request->quote_hash !== (string) $assignment->quote_hash
            || (int) $request->rider_id !== (int) $assignment->rider_id || (int) $assignment->booking_id !== (int) $booking->id
            || (int) $booking->trip_id !== (int) $trip->id || (int) $booking->user_id !== (int) $assignment->rider_id
            || (int) $booking->request_state !== Passenger::STATE_ACCEPTED || (int) $trip->user_id !== (int) $assignment->driver_id
            || (int) $trip->car_id !== (int) ($assignment->car_id ?? $trip->car_id)) {
            self::fail('DISPATCH_FARE_INVALID', 409);
        }
        $quote = DB::table('mobility_route_quotes')->where('id', $assignment->quote_id)->first();
        $offer = DB::table('mobility_dispatch_offers')->where('id', $assignment->offer_id)->first();
        if (! $quote || ! $offer || (int) $offer->car_id !== (int) $trip->car_id
            || ! hash_equals((string) $assignment->quote_hash, hash('sha256', (string) $quote->snapshot))
            || (int) $assignment->fare_minor < 0
            || (int) $trip->seat_price_cents !== (int) $assignment->fare_minor) {
            self::fail('DISPATCH_FARE_INVALID', 409);
        }
        $terms = DispatchTerms::forQuote($quote);
        if ((string) $quote->dispatch_request_id !== (string) $request->id || (int) $quote->user_id !== (int) $request->rider_id
            || (int) $offer->driver_id !== (int) $assignment->driver_id || (string) $offer->request_id !== (string) $request->id
            || $offer->state !== 'accepted' || (int) $terms['fare_minor'] !== (int) $assignment->fare_minor
            || (int) $booking->passenger_type !== Passenger::TYPE_PASAJERO || (int) $trip->total_seats !== 1) {
            self::fail('DISPATCH_FARE_INVALID', 409);
        }
        $points = DB::table('trips_points')->where('trip_id', $trip->id)->orderBy('id')->get();
        if ($points->count() !== 2) {
            self::fail('DISPATCH_FARE_INVALID', 409);
        }
        foreach ($points as $index => $point) {
            if ($point->address !== $terms['points'][$index]['address'] || abs((float) $point->lat - $terms['points'][$index]['lat']) > .0000001 || abs((float) $point->lng - $terms['points'][$index]['lng']) > .0000001) {
                self::fail('DISPATCH_FARE_INVALID', 409);
            }
        }

        return (int) $assignment->fare_minor;
    }

    public static function canCancel(Trip $trip, int $actorId): bool
    {
        $assignment = self::assignment((int) $trip->id);
        if (! $assignment || ! in_array((int) $actorId, [(int) $assignment->rider_id, (int) $assignment->driver_id], true)) {
            return false;
        }
        $state = DB::table('mobility_trip_lifecycles')->where('trip_id', $trip->id)->value('state') ?? 'planned';

        return $state === 'planned';
    }

    /**
     * Fresh start gate for an accepted dispatch trip.  It deliberately reads
     * immutable assignment linkage and shared eligibility only; it never locks
     * or updates dispatch request/offer/availability rows.
     */
    public static function assertStartable(Trip $trip): void
    {
        $assignment = self::assignment((int) $trip->id);
        if (! $assignment || ! isset($assignment->created_at)) {
            self::fail('DISPATCH_ASSIGNMENT_REQUIRED', 409);
        }
        $booking = Passenger::find($assignment->booking_id);
        if (! $booking) {
            self::fail('DISPATCH_ASSIGNMENT_CHANGED', 409);
        }
        self::fare($trip, $booking);
        $created = CarbonImmutable::parse((string) $assignment->created_at, 'UTC');
        $now = CarbonImmutable::now('UTC');
        if ($created->isFuture() || $created->lt($now->subMinutes(30))) {
            self::fail('DISPATCH_START_WINDOW_EXPIRED', 409);
        }
        $quote = DB::table('mobility_route_quotes')->where('id', $assignment->quote_id)->first();
        if (! $quote || ! hash_equals((string) $assignment->quote_hash, hash('sha256', (string) $quote->snapshot))) {
            self::fail('DISPATCH_QUOTE_CHANGED', 409);
        }
        $terms = DispatchTerms::forQuote($quote);
        if ((int) $terms['rider_id'] !== (int) $assignment->rider_id || (int) $assignment->driver_id !== (int) $trip->user_id
            || (int) $assignment->fare_minor !== (int) $terms['fare_minor']) {
            self::fail('DISPATCH_ASSIGNMENT_CHANGED', 409);
        }
        $offer = DB::table('mobility_dispatch_offers')->where('id', $assignment->offer_id)->first();
        if (! $offer) {
            self::fail('DISPATCH_OFFER_MISSING', 409);
        }
        $fingerprint = ($terms['private'] ?? false) === true
            ? DispatchEligibility::privateDriverCurrent($terms, (int) $assignment->driver_id, (int) $trip->car_id)
            : DispatchEligibility::current($terms, (int) $assignment->driver_id, (int) $trip->car_id, false);
        if (! hash_equals((string) $offer->vehicle_fingerprint, $fingerprint)) {
            self::fail('DRIVER_VEHICLE_CHANGED', 409);
        }
    }

    private static function fail(string $code, int $status): never
    {
        if (class_exists(DispatchException::class)) {
            throw new DispatchException($code, $status);
        }
        throw new \RuntimeException($code);
    }
}
