<?php

namespace STS\Mobility\Dispatch;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Routing\TrustedRouteContext;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Services\Logic\TripsManager;

/** Creates the one canonical driver Trip and accepted rider Passenger. */
final class DispatchCanonicalFactory
{
    public static function create(object $request, object $offer, object $availability, array $terms, ?\Carbon\CarbonImmutable $admittedAt = null): array
    {
        if (DB::connection()->transactionLevel() < 1) {
            self::fail('DISPATCH_TRANSACTION_REQUIRED', 409);
        }
        $riderId = (int) ($terms['rider_id'] ?? $request->rider_id ?? 0);
        $driverId = (int) ($offer->driver_id ?? $availability->driver_id ?? 0);
        $carId = (int) ($offer->car_id ?? $availability->car_id ?? 0);
        if ($riderId < 1 || $driverId < 1 || $carId < 1 || $riderId === $driverId || $terms['vehicle_type'] !== ($offer->vehicle_type ?? $terms['vehicle_type'])) {
            self::fail('CANONICAL_INPUT_INVALID', 422);
        }
        $owner = \STS\Models\User::query()->find($driverId);
        if (! $owner) {
            self::fail('DRIVER_NOT_FOUND', 404);
        }
        if (config('app.timezone') !== 'Asia/Kolkata' || config('carpoolear.module_trip_creation_payment_enabled')) {
            self::fail('DISPATCH_RUNTIME_UNSUPPORTED', 503);
        }
        // Preserve the canonical limit while avoiding the manager's user-ban write
        // under shared eligibility locks. That upgrade could invert owner controls.
        if (! config('carpoolear.disable_trip_creation_limits', false)
            && app(\STS\Repository\TripRepository::class)->getRecentTrips($driverId, config('carpoolear.trip_creation_limits.time_window_hours', 24))->count() > config('carpoolear.trip_creation_limits.max_trips', 5)) {
            self::fail('DRIVER_CREATION_LIMIT', 429);
        }
        $admittedAt ??= \Carbon\CarbonImmutable::now('UTC')->startOfSecond();
        \STS\Mobility\Admission\DriverReservationPolicy::fence($driverId);
        $private = ($terms['private'] ?? false) === true;
        if ($private) {
            if ((int) ($terms['driver_id'] ?? 0) !== $driverId || ($terms['ride_mode'] ?? null) !== 'on_demand') {
                self::fail('PRIVATE_MODE_UNSUPPORTED', 409);
            }
            DispatchEligibility::privateCurrent($terms, $driverId, $carId);
        } else {
            DispatchEligibility::current($terms, $driverId, $carId);
        }
        \STS\Mobility\Admission\DriverReservationPolicy::immediate($driverId, $terms, $admittedAt);
        $points = $terms['points'];
        $snapshot = $terms['snapshot'];
        $quote = array_merge($snapshot, ['distance_meters' => $snapshot['distance_meters'], 'duration_seconds' => $snapshot['duration_seconds'], 'seat_price_minor' => $terms['fare_minor']]);
        TrustedRouteContext::push($points, $quote);
        try {
            if ($private && ! TrustedRouteContext::privateEndpoints()) {
                self::fail('PRIVATE_ENDPOINT_CONTEXT_REQUIRED', 409);
            }
            $date = $admittedAt->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s');
            $trip = DispatchCreationContext::run($driverId, $date, fn () => app(TripsManager::class)->create($owner, [
                'is_passenger' => 0, 'car_id' => $carId, 'state' => Trip::STATE_READY,
                'from_town' => (string) $points[0]['address'], 'to_town' => (string) $points[1]['address'],
                'punto_partida' => (string) $points[0]['address'], 'punto_llegada' => (string) $points[1]['address'],
                // Dispatch trips are immediate.  The coordinator's dispatch-only
                // start policy owns the short acceptance window; do not create a
                // fake future date that changes canonical expiry semantics.
                'trip_date' => $date,
                'total_seats' => 1, 'friendship_type_id' => 2,
                'estimated_time' => gmdate('H:i:s', (int) $snapshot['duration_seconds']),
                'distance' => ((int) $snapshot['distance_meters']) / 1000, 'co2' => 0, 'description' => '',
                'points' => $points, 'seat_price_cents' => (int) $terms['fare_minor'],
            ]));
        } finally {
            TrustedRouteContext::reset();
        }
        if (! $trip || ($trip->existing ?? false) || (int) $trip->seat_price_cents !== (int) $terms['fare_minor']) {
            self::fail($trip && ($trip->existing ?? false) ? 'CANONICAL_DUPLICATE' : 'CANONICAL_VALIDATION', 409);
        }
        $booking = DispatchBookingContext::run((int) $trip->id, $riderId, static fn (): Passenger => Passenger::create([
            'trip_id' => (int) $trip->id, 'user_id' => $riderId,
            'request_state' => Passenger::STATE_ACCEPTED, 'passenger_type' => Passenger::TYPE_PASAJERO,
        ]));
        DB::table('mobility_trip_lifecycles')->insertOrIgnore(['trip_id' => $trip->id, 'state' => 'planned', 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        if (DB::getSchemaBuilder()->hasTable('mobility_trip_participants')) {
            DB::table('mobility_trip_participants')->insertOrIgnore(['trip_id' => $trip->id, 'booking_id' => $booking->id, 'user_id' => $riderId, 'state' => 'accepted', 'created_at' => now(), 'updated_at' => now()]);
        }

        return ['trip_id' => (int) $trip->id, 'booking_id' => (int) $booking->id];
    }

    private static function fail(string $code, int $status): never
    {
        if (class_exists(DispatchException::class)) {
            throw new DispatchException($code, $status);
        }
        throw new \RuntimeException($code);
    }
}
