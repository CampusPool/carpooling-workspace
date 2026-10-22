<?php

namespace STS\Mobility\Journeys;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Admission\DriverReservationPolicy;
use STS\Mobility\Dispatch\DispatchEligibility;
use STS\Mobility\Segments\SegmentException;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use STS\Services\Logic\TripsManager;

/**
 * Private scheduled publication/admission on canonical Trip/Passenger rows.
 * The publication is immutable route metadata; accepted seats remain Passenger
 * rows and existing lifecycle/history services remain authoritative.
 */
final class PrivateScheduledAdmissionService
{
    public function __construct(private readonly TripsManager $trips) {}

    public function publish(User $driver, string $quoteId, string $key, \DateTimeInterface $departure, int $seats): array
    {
        $departure = CarbonImmutable::instance($departure);
        if (! Str::isUuid($quoteId) || ! preg_match('/^[\x21-\x7E]{1,100}$/', $key) || $seats < 1 || $seats > 5) {
            throw new SegmentException('PRIVATE_SCHEDULE_INPUT_INVALID', 422);
        }
        $keyHash = hash('sha256', $driver->id.':'.$key);
        $existing = DB::table('mobility_private_scheduled_publications')->where('create_key_hash', $keyHash)->first();
        if ($existing) {
            return $this->replay($existing, $quoteId, $departure, $seats);
        }
        $routeQuote = DB::table('mobility_route_quotes')->where('id', $quoteId)->first();
        if (! $routeQuote) {
            throw new SegmentException('PRIVATE_QUOTE_NOT_FOUND', 404);
        }
        $terms = PrivateJourneyTerms::forQuote($routeQuote);
        if ($terms['ride_mode'] !== 'scheduled' || (int) $terms['driver_id'] !== (int) $driver->id) {
            throw new SegmentException('PRIVATE_SCHEDULE_DRIVER_REQUIRED', 403);
        }
        if ($departure->timezone->getName() !== 'Asia/Kolkata') {
            $departure = $departure->setTimezone('Asia/Kolkata');
        }
        if ($departure->lte(CarbonImmutable::now('Asia/Kolkata')->addMinutes(15)) || $departure->gt(CarbonImmutable::now('Asia/Kolkata')->addDays(30))) {
            throw new SegmentException('DEPARTURE_INVALID', 422);
        }
        $path = $terms['private_snapshot']['driver_path'];
        $points = $this->pathPoints($path, $quoteId);
        $routeHash = $this->routeHash($path);

        return DB::transaction(function () use ($driver, $routeQuote, $terms, $departure, $seats, $keyHash, $routeHash, $path, $points): array {
            DriverReservationPolicy::fence((int) $driver->id);
            $existing = DB::table('mobility_private_scheduled_publications')->where('create_key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing) {
                return $this->replay($existing, (string) $routeQuote->id, $departure, $seats);
            }
            $lockedQuote = DB::table('mobility_route_quotes')->where('id', $routeQuote->id)->lockForUpdate()->first();
            if ($lockedQuote->consumed_trip_id || ($lockedQuote->dispatch_request_id ?? null) || CarbonImmutable::parse($lockedQuote->expires_at, 'UTC')->lte(now('UTC'))) {
                throw new SegmentException('PRIVATE_QUOTE_UNAVAILABLE', 409);
            }
            $carId = DB::table('mobility_pickup_plans')->where('id', DB::table('mobility_pickup_selections')->where('id', $terms['private_snapshot']['selection_id'])->value('plan_id'))->value('car_id');
            if (! $carId || ! CohortPolicy::hostApproved((int) $driver->id, (int) $carId, $terms['vehicle_type'])) {
                throw new SegmentException('HOST_APPROVAL_REQUIRED', 403);
            }
            DispatchEligibility::privateCurrent($terms, (int) $driver->id, (int) $carId);
            if ($terms['vehicle_type'] === 'bike' && $seats > 1) {
                throw new SegmentException('BIKE_CAPACITY_EXCEEDED', 422);
            }
            $quote = ['endpoint_schema' => 'actor-private-v1', 'private_quote_id' => (string) $routeQuote->id, 'points' => $points, 'provider' => 'osrm', 'profile' => $path['profile'], 'data_version' => $path['data_version'], 'distance_meters' => $path['distance_meters'], 'duration_seconds' => $path['duration_seconds'], 'seat_price_minor' => $terms['fare_minor']];
            \STS\Mobility\Routing\TrustedRouteContext::push($points, $quote);
            try {
                $trip = \STS\Mobility\Dispatch\DispatchCreationContext::run((int) $driver->id, $departure->format('Y-m-d H:i:s'), fn () => $this->trips->create($driver, ['is_passenger' => 0, 'car_id' => (int) $carId, 'state' => Trip::STATE_READY, 'from_town' => $points[0]['address'], 'to_town' => $points[count($points) - 1]['address'], 'punto_partida' => $points[0]['address'], 'punto_llegada' => $points[count($points) - 1]['address'], 'trip_date' => $departure->format('Y-m-d H:i:s'), 'total_seats' => $seats, 'friendship_type_id' => 2, 'estimated_time' => gmdate('H:i:s', (int) $path['duration_seconds']), 'distance' => ((int) $path['distance_meters']) / 1000, 'co2' => 0, 'description' => '', 'points' => $points, 'seat_price_cents' => $terms['fare_minor']]));
            } finally {
                \STS\Mobility\Routing\TrustedRouteContext::reset();
            }
            if (! $trip || ($trip->existing ?? false)) {
                throw new SegmentException('CANONICAL_DUPLICATE', 409);
            }
            DB::table('mobility_trip_lifecycles')->insertOrIgnore(['trip_id' => $trip->id, 'state' => 'planned', 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DriverReservationPolicy::scheduled($trip, ['snapshot' => ['duration_seconds' => (int) $path['duration_seconds']]]);
            $id = (string) Str::uuid();
            DB::table('mobility_private_scheduled_publications')->insert(['trip_id' => $trip->id, 'publication_id' => $id, 'source_quote_id' => $routeQuote->id, 'source_fare_minor' => $terms['fare_minor'], 'driver_id' => $driver->id, 'car_id' => $carId, 'total_seats' => $seats, 'departure_at' => $departure, 'route_hash' => $routeHash, 'driver_path' => json_encode($path, JSON_THROW_ON_ERROR), 'points' => json_encode($points, JSON_THROW_ON_ERROR), 'create_key_hash' => $keyHash, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('mobility_route_quotes')->where('id', $routeQuote->id)->update(['consumed_trip_id' => $trip->id, 'updated_at' => now()]);

            return ['id' => $trip->id, 'trip_id' => $trip->id, 'publication_id' => $id, 'quote_id' => $routeQuote->id, 'state' => 'published', 'total_seats' => $seats, 'departure_at' => $departure->toISOString(), 'fare_minor' => $terms['fare_minor'], 'points' => $points];
        });
    }

    public function request(int $tripId, User $rider, string $quoteId, string $key, string $expectedHash, string $expectedDeparture, string $expectedIntervalHash): array
    {
        if (! Str::isUuid($quoteId) || ! preg_match('/^[\x21\x23-\x7E]{1,100}$/', $key)) {
            throw new SegmentException('PRIVATE_SCHEDULE_INPUT_INVALID', 422);
        }

        return DB::transaction(function () use ($tripId, $rider, $quoteId, $key, $expectedHash, $expectedDeparture, $expectedIntervalHash): array {
            $trip = Trip::whereKey($tripId)->lockForUpdate()->first();
            $publication = DB::table('mobility_private_scheduled_publications')->where('trip_id', $tripId)->lockForUpdate()->first();
            if (! $trip || ! $publication) {
                throw new SegmentException('PRIVATE_SCHEDULE_NOT_FOUND', 404);
            }
            $actionHash = hash('sha256', $key);
            $reqHash = hash('sha256', json_encode([$quoteId, 'request', $expectedHash, $expectedDeparture, $expectedIntervalHash], JSON_THROW_ON_ERROR));
            $old = DB::table('mobility_private_scheduled_actions')->where(['trip_id' => $tripId, 'rider_id' => $rider->id, 'kind' => 'request', 'key_hash' => $actionHash])->first();
            if ($old) {
                if ($old->request_hash !== $reqHash) {
                    throw new SegmentException('IDEMPOTENCY_CONFLICT', 409);
                }

                return json_decode($old->response, true);
            }
            $this->planned($trip, $publication);
            if (! CohortPolicy::approved((int) $rider->id) || (int) $publication->driver_id === (int) $rider->id) {
                throw new SegmentException('MEMBERSHIP_REQUIRED', 403);
            }
            $quote = DB::table('mobility_route_quotes')->where('id', $quoteId)->first();
            if (! $quote) {
                throw new SegmentException('PRIVATE_QUOTE_NOT_FOUND', 404);
            }
            $terms = PrivateJourneyTerms::forQuote($quote);
            if ((int) $terms['rider_id'] !== (int) $rider->id || $terms['ride_mode'] !== 'scheduled') {
                throw new SegmentException('PRIVATE_QUOTE_ACTOR_MISMATCH', 403);
            }
            $this->freshQuote($quote, $tripId);
            if (! hash_equals($terms['hash'], $expectedHash) || CarbonImmutable::parse($publication->departure_at, 'Asia/Kolkata')->getTimestamp() !== CarbonImmutable::parse($expectedDeparture)->getTimestamp()) {
                throw new SegmentException('PRIVATE_CONSENT_CHANGED', 409);
            }
            DispatchEligibility::privateCurrent($terms, (int) $publication->driver_id, (int) $publication->car_id);
            $approvalMode = DB::table('mobility_host_applications')->where('user_id', $publication->driver_id)->where('car_id', $publication->car_id)->where('status', 'approved')->value('vehicle_type');
            if ((int) $terms['driver_id'] !== (int) $publication->driver_id || $terms['vehicle_type'] !== $approvalMode) {
                throw new SegmentException('PRIVATE_ROUTE_CHANGED', 409);
            }
            $interval = $this->interval($publication, $terms);
            if (! hash_equals($interval['interval_hash'], $expectedIntervalHash)) throw new SegmentException('PRIVATE_CONSENT_CHANGED', 409);
            $active = Passenger::where('trip_id', $tripId)->where('user_id', $rider->id)->whereIn('request_state', [0, 1, 4])->first();
            if ($active) {
                $binding = DB::table('mobility_private_scheduled_bindings')->where('booking_id', $active->id)->first();
                if (! $binding || $binding->quote_id !== $quoteId || ! hash_equals($binding->quote_hash, $terms['hash'])) {
                    throw new SegmentException('PRIVATE_CONSENT_CHANGED', 409);
                }

                if ((int) $active->request_state === Passenger::STATE_PENDING && $binding->interval_snapshot === null && $binding->interval_hash === null) {
                    // Fresh exact card consent upgrades a pending legacy request
                    // without altering any accepted obligation or Passenger row.
                    $this->bindingSnapshot($binding);
                    DB::table('mobility_private_scheduled_bindings')->where('booking_id', $active->id)->update(['interval_snapshot' => json_encode($interval, JSON_THROW_ON_ERROR), 'interval_hash' => $interval['interval_hash'], 'updated_at' => now()]);
                } elseif ($binding->interval_hash !== null || $binding->interval_snapshot !== null) {
                    $this->bindingInterval($publication, $binding, $this->bindingSnapshot($binding), false);
                }
                $response = ['trip_id' => $tripId, 'booking_id' => $active->id, 'request_state' => $active->request_state, 'existing' => true];
                DB::table('mobility_private_scheduled_actions')->insert(['trip_id' => $tripId, 'rider_id' => $rider->id, 'kind' => 'request', 'key_hash' => $actionHash, 'request_hash' => $reqHash, 'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                return $response;
            }
            if (DB::table('mobility_private_scheduled_bindings')->where('trip_id', $tripId)->where('quote_id', $quoteId)->exists()) {
                throw new SegmentException('PRIVATE_QUOTE_ALREADY_USED', 409);
            }
            $booking = PrivateScheduledAdmissionContext::run($tripId, (int) $rider->id, fn () => Passenger::create(['trip_id' => $tripId, 'user_id' => $rider->id, 'request_state' => Passenger::STATE_PENDING, 'passenger_type' => Passenger::TYPE_PASAJERO]));
            DB::table('mobility_private_scheduled_bindings')->insert(['booking_id' => $booking->id, 'trip_id' => $tripId, 'quote_id' => $quoteId, 'rider_id' => $rider->id, 'fare_minor' => $terms['fare_minor'], 'quote_hash' => $terms['hash'], 'terms_snapshot' => json_encode($terms['private_snapshot'], JSON_THROW_ON_ERROR), 'interval_snapshot' => json_encode($interval, JSON_THROW_ON_ERROR), 'interval_hash' => $interval['interval_hash'], 'created_at' => now(), 'updated_at' => now()]);
            $response = ['trip_id' => $tripId, 'booking_id' => $booking->id, 'request_state' => Passenger::STATE_PENDING, 'existing' => false];
            DB::table('mobility_private_scheduled_actions')->insert(['trip_id' => $tripId, 'rider_id' => $rider->id, 'kind' => 'request', 'key_hash' => $actionHash, 'request_hash' => $reqHash, 'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return $response;
        });
    }

    /** Narrow discovery: only this rider's mutually approved, immutable route. */
    public function discover(User $rider, string $quoteId): array
    {
        return DB::transaction(function () use ($rider, $quoteId): array {
            $quote = DB::table('mobility_route_quotes')->where('id', $quoteId)->first();
            if (! $quote) {
                throw new SegmentException('PRIVATE_QUOTE_NOT_FOUND', 404);
            }
            $terms = PrivateJourneyTerms::forQuote($quote);
            if ((int) $terms['rider_id'] !== (int) $rider->id || $terms['ride_mode'] !== 'scheduled') {
                throw new SegmentException('PRIVATE_QUOTE_ACTOR_MISMATCH', 403);
            }
            $planId = DB::table('mobility_pickup_selections')->where('id', $terms['private_snapshot']['selection_id'])->value('plan_id');
            $carId = (int) DB::table('mobility_pickup_plans')->where('id', $planId)->value('car_id');
            DispatchEligibility::privateCurrent($terms, (int) $terms['driver_id'], $carId);
            if (CarbonImmutable::parse($quote->expires_at, 'UTC')->lte(now('UTC'))) {
                throw new SegmentException('PRIVATE_QUOTE_UNAVAILABLE', 409);
            }
            $rows = DB::table('mobility_private_scheduled_publications as p')->join('trips as t', 't.id', '=', 'p.trip_id')->join('mobility_trip_lifecycles as l', 'l.trip_id', '=', 't.id')
                ->where('p.driver_id', $terms['driver_id'])->where('p.car_id', $carId)
                ->where('p.departure_at', '>', now('Asia/Kolkata'))->whereNull('t.deleted_at')->where('t.state', Trip::STATE_READY)->where('l.state', 'planned')
                ->orderBy('p.departure_at')->orderBy('p.trip_id')->limit(20)->select('p.*')->get();
            $cards = [];
            foreach ($rows as $row) {
                if (($quote->consumed_trip_id && (int) $quote->consumed_trip_id !== (int) $row->trip_id) || ($quote->dispatch_request_id ?? null)) {
                    continue;
                }
                try { $interval = $this->interval($row, $terms); } catch (SegmentException $error) { if ($error->machineCode === 'PRIVATE_ROUTE_INCOMPATIBLE' || $error->machineCode === 'PRIVATE_WALKING_LIMIT_EXCEEDED') continue; throw $error; }
                $canonicalTrip = Trip::findOrFail($row->trip_id);
                $booking = Passenger::where('trip_id', $row->trip_id)->where('user_id', $rider->id)->where('passenger_type', Passenger::TYPE_PASAJERO)->orderByDesc('id')->first();
                $binding = $booking ? DB::table('mobility_private_scheduled_bindings')->where('booking_id', $booking->id)->first() : null;
                $available = PrivateIntervalInventory::available($canonicalTrip, $interval, (string) $row->route_hash);
                if ($available < 1 && (! $booking || (int) $booking->request_state !== Passenger::STATE_ACCEPTED)) {
                    continue;
                }
                $path = $terms['private_snapshot']['driver_path'];
                $departure = CarbonImmutable::parse($row->departure_at, 'Asia/Kolkata');
                $pickup = $departure->addSeconds($interval['from_seconds']);
                $cards[] = ['trip_id' => (int) $row->trip_id, 'driver_name' => (string) User::whereKey($row->driver_id)->value('name'), 'vehicle_type' => $terms['vehicle_type'],
                    'departure_at' => $departure->toISOString(), 'pickup_window' => ['earliest' => $pickup->toISOString(), 'latest' => $pickup->addMinutes(5)->toISOString(), 'demo_estimate' => true],
                    'requires_consent_refresh' => $booking && (int) $booking->request_state === Passenger::STATE_PENDING && $binding && $binding->interval_hash === null, 'seats_available' => $available, 'booking_state' => $booking ? (int) $booking->request_state : null, 'quote_id' => $quoteId, 'quote_hash' => $terms['hash'], 'interval_hash' => $interval['interval_hash'],
                    'choice' => $terms['private_snapshot']['choice'], 'pricing' => $terms['private_snapshot']['pricing'], 'access' => ['meters' => $terms['private_snapshot']['access']['total_meters'] + $interval['pickup_gap_meters']],
                    'riding' => array_replace($terms['private_snapshot']['riding'], ['duration_seconds' => $interval['till_seconds'] - $interval['from_seconds']]), 'egress' => ['meters' => $terms['private_snapshot']['egress']['total_meters'] + $interval['dropoff_gap_meters']], 'total_walking_meters' => $terms['private_snapshot']['total_walking_meters'] + $interval['pickup_gap_meters'] + $interval['dropoff_gap_meters'],
                    'rank' => count($cards) + 1, 'rank_reason' => 'Follows the unchanged driver route; earliest departure first'];
            }

            return $cards;
        });
    }

    public function bookings(User $actor): array
    {
        $rows = DB::table('mobility_private_scheduled_publications as p')->join('trips as t', 't.id', '=', 'p.trip_id')
            ->where(function ($q) use ($actor) {
                $q->where('p.driver_id', $actor->id)->orWhereExists(fn ($b) => $b->selectRaw('1')->from('trip_passengers')->whereColumn('trip_passengers.trip_id', 'p.trip_id')->where('trip_passengers.user_id', $actor->id));
            })
            ->orderByDesc('p.departure_at')->limit(50)->select('p.*', 't.state as trip_state')->get();

        return $rows->map(function ($publication) use ($actor) {
            $owner = (int) $publication->driver_id === (int) $actor->id;
            $bookings = DB::table('trip_passengers as b')->join('mobility_private_scheduled_bindings as binding', 'binding.booking_id', '=', 'b.id')
                ->where('b.trip_id', $publication->trip_id)->when(! $owner, fn ($q) => $q->where('b.user_id', $actor->id))->orderBy('b.id')
                ->get(['b.id', 'b.user_id', 'b.request_state', 'b.canceled_state', 'binding.fare_minor', 'binding.terms_snapshot', 'binding.interval_snapshot', 'binding.interval_hash']);

            $capacityInterval = null;
            foreach ($bookings->reverse() as $binding) {
                $stored = json_decode($binding->interval_snapshot ?? 'null', true);
                if (is_array($stored) && is_string($binding->interval_hash)
                    && hash_equals($binding->interval_hash, CanonicalRouteIntervalProjector::hashInterval($stored))
                    && ($stored['canonical_route_hash'] ?? null) === $publication->route_hash) {
                    $capacityInterval = $stored;
                    if ($owner) {
                        $capacityInterval['from_meters'] = 0;
                        $capacityInterval['till_meters'] = $stored['route_length_meters'];
                    }
                    break;
                }
            }
            $available = $capacityInterval
                ? PrivateIntervalInventory::available(Trip::findOrFail($publication->trip_id), $capacityInterval, $publication->route_hash)
                : max(0, (int) $publication->total_seats - Passenger::where('trip_id', $publication->trip_id)->where('passenger_type', Passenger::TYPE_PASAJERO)->where('request_state', Passenger::STATE_ACCEPTED)->count());

            return ['trip_id' => (int) $publication->trip_id, 'publication_id' => $owner ? $publication->publication_id : null, 'route_hash' => $owner ? $publication->route_hash : null, 'owner' => $owner, 'departure_at' => CarbonImmutable::parse($publication->departure_at, 'Asia/Kolkata')->toISOString(),
                'state' => DB::table('mobility_trip_lifecycles')->where('trip_id', $publication->trip_id)->value('state') ?? 'planned', 'total_seats' => (int) $publication->total_seats,
                'seats_available' => $available,
                'bookings' => $bookings->map(function ($b) {
                    $terms = json_decode($b->terms_snapshot ?? '{}', true);

                    return ['booking_id' => (int) $b->id, 'rider_id' => (int) $b->user_id, 'rider_name' => (string) User::whereKey($b->user_id)->value('name'), 'request_state' => (int) $b->request_state, 'cancelled_by' => $b->canceled_state, 'requires_consent_refresh' => (int) $b->request_state === Passenger::STATE_PENDING && $b->interval_hash === null, 'fare_minor' => (int) $b->fare_minor, 'pricing' => $terms['pricing'] ?? null, 'walking_meters' => $terms['total_walking_meters'] ?? null];
                })->all()];
        })->all();
    }

    /** Actor-scoped action receipts replay the original result before current policy. */
    public function action(User $actor, int $tripId, int $riderId, string $action, string $key): array
    {
        if (! in_array($action, ['accept', 'reject', 'cancel'], true) || ! preg_match('/^[\x21-\x7E]{1,100}$/', $key)) {
            throw new SegmentException('PRIVATE_SCHEDULE_INPUT_INVALID', 422);
        }

        return DB::transaction(function () use ($actor, $tripId, $riderId, $action, $key): array {
            $trip = Trip::whereKey($tripId)->lockForUpdate()->first();
            if (! $trip || ! self::managed($tripId)) {
                throw new SegmentException('PRIVATE_SCHEDULE_NOT_FOUND', 404);
            }
            $owner = (int) $trip->user_id === (int) $actor->id;
            if (($action !== 'cancel' && ! $owner) || ($action === 'cancel' && ! $owner && (int) $actor->id !== $riderId)) {
                throw new SegmentException('PRIVATE_SCHEDULE_FORBIDDEN', 403);
            }
            $kind = $action;
            $keyHash = hash('sha256', $actor->id.':'.$key);
            $requestHash = hash('sha256', $riderId.':'.$action);
            $old = DB::table('mobility_private_scheduled_actions')->where(['trip_id' => $tripId, 'rider_id' => $riderId, 'kind' => $kind, 'key_hash' => $keyHash])->first();
            if ($old) {
                if (! hash_equals($old->request_hash, $requestHash)) {
                    throw new SegmentException('IDEMPOTENCY_CONFLICT', 409);
                }

                return json_decode($old->response, true);
            }
            $booking = Passenger::where('trip_id', $tripId)->where('user_id', $riderId)->where('passenger_type', Passenger::TYPE_PASAJERO)->orderByDesc('id')->first();
            if (! $booking) {
                throw new SegmentException('PRIVATE_BOOKING_NOT_FOUND', 404);
            }
            if ($action === 'accept') {
                $booking = $this->accept($tripId, $riderId);
            } elseif ($action === 'reject') {
                $booking = $this->changeState($tripId, $riderId, Passenger::STATE_REJECTED, ['request_state' => Passenger::STATE_PENDING]);
            } else {
                if (DB::table('mobility_trip_lifecycles')->where('trip_id', $tripId)->value('state') !== 'planned') {
                    throw new SegmentException('PRIVATE_SCHEDULE_CANCEL_REQUIRES_RECOVERY', 409);
                }
                $cancelled = (int) $booking->request_state === Passenger::STATE_PENDING ? Passenger::CANCELED_REQUEST : ($owner ? Passenger::CANCELED_DRIVER : Passenger::CANCELED_PASSENGER);
                $booking = $this->changeState($tripId, $riderId, Passenger::STATE_CANCELED, [], $cancelled);
            }
            if (! $booking) {
                throw new SegmentException('PRIVATE_BOOKING_STATE_CHANGED', 409);
            }
            $response = ['trip_id' => $tripId, 'booking_id' => (int) $booking->id, 'rider_id' => $riderId, 'request_state' => (int) $booking->request_state];
            DB::table('mobility_private_scheduled_actions')->insert(['trip_id' => $tripId, 'rider_id' => $riderId, 'kind' => $kind, 'key_hash' => $keyHash, 'request_hash' => $requestHash, 'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return $response;
        });
    }

    public function assertStartable(Trip $trip): void
    {
        $publication = DB::table('mobility_private_scheduled_publications')->where('trip_id', $trip->id)->first();
        if (! $publication || (int) $publication->driver_id !== (int) $trip->user_id || (int) $publication->car_id !== (int) $trip->car_id) throw new SegmentException('PRIVATE_ROUTE_CHANGED', 409);
        $this->canonical($trip, $publication);
        $path = json_decode($publication->driver_path, true, 512, JSON_THROW_ON_ERROR);
        if (! hash_equals($publication->route_hash, $this->routeHash($path))) throw new SegmentException('PRIVATE_ROUTE_CHANGED', 409);
        DriverReservationPolicy::fence((int) $trip->user_id);
        DispatchEligibility::driver((int) $trip->user_id, (int) $trip->car_id);
        $accepted = Passenger::where('trip_id', $trip->id)->where('passenger_type', Passenger::TYPE_PASAJERO)->where('request_state', Passenger::STATE_ACCEPTED)->lockForUpdate()->get();
        if ($accepted->isEmpty()) throw new SegmentException('ACCEPTED_BOOKING_REQUIRED',409);
        foreach ($accepted as $booking) {
            $binding = DB::table('mobility_private_scheduled_bindings')->where('booking_id', $booking->id)->first();
            $snapshot = $this->bindingSnapshot($binding);
            $this->bindingInterval($publication, $binding, $snapshot, true);
        }
        DriverReservationPolicy::starting($trip, (int) $path['duration_seconds']);
    }

    public function accept(int $tripId, int $riderId): ?Passenger
    {
        return DB::transaction(function () use ($tripId, $riderId): ?Passenger {
            $trip = Trip::whereKey($tripId)->lockForUpdate()->first();
            $publication = DB::table('mobility_private_scheduled_publications')->where('trip_id', $tripId)->first();
            if (! $trip || ! $publication) {
                return null;
            }
            $booking = Passenger::where('trip_id', $tripId)->where('user_id', $riderId)->where('passenger_type', Passenger::TYPE_PASAJERO)->whereIn('request_state', [Passenger::STATE_PENDING, Passenger::STATE_ACCEPTED])->lockForUpdate()->first();
            if (! $booking) {
                return null;
            }
            if ((int) $booking->request_state === Passenger::STATE_ACCEPTED) {
                return $booking;
            }
            $this->planned($trip, $publication);
            DriverReservationPolicy::fence((int) $trip->user_id);
            $binding = DB::table('mobility_private_scheduled_bindings')->where('booking_id', $booking->id)->first();
            $quote = $binding ? DB::table('mobility_route_quotes')->where('id', $binding->quote_id)->lockForUpdate()->first() : null;
            if (! $quote) {
                throw new SegmentException('PRIVATE_QUOTE_NOT_FOUND', 409);
            }
            $terms = PrivateJourneyTerms::forQuote($quote);
            $this->freshQuote($quote, $tripId);
            if ((int) $terms['rider_id'] !== $riderId || (int) $terms['driver_id'] !== (int) $trip->user_id || $terms['ride_mode'] !== 'scheduled'
                || ! hash_equals($binding->quote_hash, $terms['hash'])) {
                throw new SegmentException('PRIVATE_CONSENT_CHANGED', 409);
            }
            DispatchEligibility::privateCurrent($terms, (int) $trip->user_id, (int) $trip->car_id);
            $interval = $this->interval($publication, $terms);
            $snapshot = $this->bindingSnapshot($binding);
            $this->bindingInterval($publication, $binding, $snapshot, false);
            if (! hash_equals($binding->interval_hash, $interval['interval_hash'])) throw new SegmentException('PRIVATE_CONSENT_CHANGED', 409);
            $canonicalPath = json_decode($publication->driver_path, true, 512, JSON_THROW_ON_ERROR);
            DriverReservationPolicy::scheduled($trip, ['snapshot' => ['duration_seconds' => (int) $canonicalPath['duration_seconds']]]);
            if (PrivateIntervalInventory::available($trip, $interval, (string) $publication->route_hash) < 1) {
                throw new SegmentException('NOT_SEAT_AVAILABLE', 422);
            }

            return PrivateScheduledAdmissionContext::run($tripId, $riderId, function () use ($booking): Passenger {
                $booking->request_state = Passenger::STATE_ACCEPTED;
                $booking->save();
                DB::table('mobility_route_quotes')->where('id', DB::table('mobility_private_scheduled_bindings')->where('booking_id', $booking->id)->value('quote_id'))->update(['consumed_trip_id' => $booking->trip_id, 'updated_at' => now()]);

                return $booking;
            });
        });
    }

    public function changeState(int $tripId, int $riderId, int $state, array $criteria = [], ?int $cancelled = null): ?Passenger
    {
        if (! in_array($state, [Passenger::STATE_CANCELED, Passenger::STATE_REJECTED], true)) {
            throw new SegmentException('PRIVATE_SCHEDULE_STATE_INVALID', 422);
        }

        return DB::transaction(function () use ($tripId, $riderId, $state, $criteria, $cancelled): ?Passenger {
            $trip = Trip::whereKey($tripId)->lockForUpdate()->first();
            if (! $trip || ! $this->managed($tripId)) {
                return null;
            }

            $states = $state === Passenger::STATE_REJECTED
                ? [Passenger::STATE_PENDING]
                : [Passenger::STATE_PENDING, Passenger::STATE_ACCEPTED, Passenger::STATE_WAITING_PAYMENT];
            $query = Passenger::where('trip_id', $tripId)->where('user_id', $riderId)->where('passenger_type', Passenger::TYPE_PASAJERO)->whereIn('request_state', $states);
            foreach ($criteria as $field => $value) {
                $query->where($field, $value);
            }
            $booking = $query->lockForUpdate()->first();
            if (! $booking) {
                return null;
            }
            if ((int) $booking->request_state === Passenger::STATE_ACCEPTED && DB::table('mobility_trip_lifecycles')->where('trip_id',$tripId)->value('state') !== 'planned') throw new SegmentException('PRIVATE_SCHEDULE_CANCEL_REQUIRES_RECOVERY',409);

            return PrivateScheduledAdmissionContext::run($tripId, $riderId, function () use ($booking, $state, $cancelled): Passenger {
                $booking->request_state = $state;
                $booking->canceled_state = $cancelled;
                $booking->save();

                return $booking;
            });
        });
    }

    private function bindingSnapshot(?object $binding): array
    {
        if (! $binding || ! $binding->terms_snapshot || ! hash_equals($binding->quote_hash, hash('sha256', $binding->terms_snapshot))) {
            throw new SegmentException('PRIVATE_CONSENT_CHANGED', 409);
        }
        $snapshot = json_decode($binding->terms_snapshot, true, 512, JSON_THROW_ON_ERROR);
        if ((int) $binding->fare_minor !== ($snapshot['pricing']['total_fare_minor'] ?? null)) {
            throw new SegmentException('PRIVATE_CONSENT_CHANGED', 409);
        }
        return $snapshot;
    }

    private function bindingInterval(object $publication, object $binding, array $snapshot, bool $allowAcceptedLegacy): array
    {
        if ($binding->interval_snapshot === null && $binding->interval_hash === null) {
            // Previously accepted exact-path bookings retain their full-route
            // occupancy and execution obligation. Pending legacy requests need
            // new card consent before interval admission.
            if (! $allowAcceptedLegacy) throw new SegmentException('PRIVATE_SCHEDULE_CONSENT_REFRESH_REQUIRED', 409);
            if (! hash_equals($publication->route_hash, $this->routeHash($snapshot['driver_path']))) throw new SegmentException('PRIVATE_CONSENT_CHANGED', 409);
            return [];
        }
        $interval = $this->interval($publication, ['private_snapshot' => $snapshot]);
        $stored = json_decode($binding->interval_snapshot ?? 'null', true);
        if (! is_array($stored) || ! is_string($binding->interval_hash)
            || ! hash_equals($binding->interval_hash, $interval['interval_hash'])
            || ! hash_equals($binding->interval_hash, CanonicalRouteIntervalProjector::hashInterval($stored))) {
            throw new SegmentException('PRIVATE_CONSENT_CHANGED', 409);
        }
        return $interval;
    }

    private function interval(object $publication, array $terms): array
    {
        $canonical = json_decode($publication->driver_path, true, 512, JSON_THROW_ON_ERROR);
        $candidate = $terms['private_snapshot']['driver_path'];
        try {
            $interval = app(CanonicalRouteIntervalProjector::class)->project($canonical, $candidate, $candidate['waypoints'][1]['location'], $candidate['waypoints'][2]['location'], (string) $publication->route_hash);
        } catch (\InvalidArgumentException $error) {
            if ($error->getMessage() !== 'ROUTE_PROJECTION_AMBIGUOUS' || ! hash_equals((string) $publication->route_hash, $this->routeHash($candidate))) {
                throw new SegmentException('PRIVATE_ROUTE_INCOMPATIBLE', 409);
            }
            try { $interval = app(CanonicalRouteIntervalProjector::class)->reserveExact($canonical, $candidate, (string) $publication->route_hash); }
            catch (\InvalidArgumentException) { throw new SegmentException('PRIVATE_ROUTE_INCOMPATIBLE', 409); }
        }
        $walking = $terms['private_snapshot']['total_walking_meters'] + $interval['pickup_gap_meters'] + $interval['dropoff_gap_meters'];
        if ($walking >= 1000 || $walking > $terms['private_snapshot']['max_walk_meters']) throw new SegmentException('PRIVATE_WALKING_LIMIT_EXCEEDED',409);
        return $interval;
    }

    private function planned(Trip $trip, object $publication): void
    {
        if ($trip->trashed() || $trip->state !== Trip::STATE_READY || CarbonImmutable::parse($publication->departure_at, 'Asia/Kolkata')->lte(now('Asia/Kolkata'))
            || DB::table('mobility_trip_lifecycles')->where('trip_id', $trip->id)->value('state') !== 'planned') {
            throw new SegmentException('PRIVATE_SCHEDULE_NOT_PLANNED', 409);
        }
        $this->canonical($trip, $publication);
    }

    private function canonical(Trip $trip, object $publication): void
    {
        $canonical = DB::table('trips_points')->where('trip_id', $trip->id)->orderBy('id')->get();
        $points = json_decode($publication->points, true, 512, JSON_THROW_ON_ERROR);
        if ($canonical->count() !== count($points) || (int) $trip->total_seats !== (int) $publication->total_seats || (int) $trip->car_id !== (int) $publication->car_id || (int) $trip->user_id !== (int) $publication->driver_id) {
            throw new SegmentException('PRIVATE_ROUTE_CHANGED', 409);
        }
        foreach ($canonical as $i => $point) {
            if (abs((float) $point->lat - $points[$i]['lat']) > .0000001 || abs((float) $point->lng - $points[$i]['lng']) > .0000001) {
                throw new SegmentException('PRIVATE_ROUTE_CHANGED', 409);
            }
        }
    }

    private function freshQuote(object $quote, int $tripId): void
    {
        if (($quote->consumed_trip_id && (int) $quote->consumed_trip_id !== $tripId) || ($quote->dispatch_request_id ?? null) || CarbonImmutable::parse($quote->expires_at, 'UTC')->lte(now('UTC'))) {
            throw new SegmentException('PRIVATE_QUOTE_UNAVAILABLE', 409);
        }
    }

    private function routeHash(array $path): string
    {
        return hash('sha256', json_encode($path, JSON_THROW_ON_ERROR));
    }

    private function replay(object $row, string $quoteId, CarbonImmutable $departure, int $seats): array
    {
        if ((string) $row->source_quote_id !== $quoteId || (int) $row->total_seats !== $seats || CarbonImmutable::parse($row->departure_at, 'Asia/Kolkata')->getTimestamp() !== $departure->getTimestamp()) {
            throw new SegmentException('IDEMPOTENCY_CONFLICT', 409);
        }

        return $this->publication($row, true);
    }

    public static function managed(int $tripId): bool
    {
        return DB::table('mobility_private_scheduled_publications')->where('trip_id', $tripId)->exists();
    }

    private function pathPoints(array $path, string $quoteId): array
    {
        $roles = ['start', 'pickup', 'dropoff', 'end'];

        return array_map(function ($w, $i) use ($roles, $quoteId) {
            $p = $w['location'];

            return ['address' => 'Private '.$roles[$i], 'lat' => (float) $p[1], 'lng' => (float) $p[0], 'json_address' => ['private_quote_id' => $quoteId, 'role' => $roles[$i]]];
        }, $path['waypoints'], array_keys($path['waypoints']));
    }

    private function publication(object $row, bool $existing): array
    {
        return ['id' => $row->trip_id, 'trip_id' => $row->trip_id, 'publication_id' => $row->publication_id, 'quote_id' => $row->source_quote_id, 'state' => 'published', 'total_seats' => (int) $row->total_seats, 'departure_at' => CarbonImmutable::parse($row->departure_at, 'Asia/Kolkata')->toISOString(), 'fare_minor' => (int) ($row->source_fare_minor ?? DB::table('trips')->where('id', $row->trip_id)->value('seat_price_cents')), 'points' => json_decode($row->points,true)];
    }
}
