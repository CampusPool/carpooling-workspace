<?php

namespace STS\Mobility\Routing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use STS\Mobility\Trust\CohortPolicy;
use STS\Mobility\Trust\DriverOnboardingPolicy;
use STS\Models\Car;
use STS\Models\Trip;
use STS\Models\User;
use STS\Services\Logic\TripsManager;

final class OfferService
{
    public function __construct(private TripsManager $manager) {}

    public function publish(User $user, array $input, string $key, CarbonImmutable $departure): array
    {
        $keyHash = hash('sha256', $key);
        $requestHash = hash('sha256', json_encode([
            'quote_id' => (string) $input['quote_id'], 'car_id' => (int) $input['car_id'],
            'trip_date' => $departure->format('Y-m-d\\TH:i'), 'total_seats' => (int) $input['total_seats'],
            'description' => (string) ($input['description'] ?? ''),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $input, $keyHash, $requestHash, $departure): array {
            $owner = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (! $owner || ! CohortPolicy::activeUser($owner)) {
                throw new OfferException('ACCOUNT_RESTRICTED', 403);
            }
            $college = CohortPolicy::college();
            if (! $college) {
                throw new OfferException('COHORT_NOT_CONFIGURED', 503);
            }
            $membership = DB::table('mobility_memberships')->where('user_id', $owner->id)->lockForUpdate()->first();
            if (! $membership || $membership->status !== 'approved' || $membership->college_code !== $college) {
                throw new OfferException('MEMBERSHIP_REQUIRED', 403);
            }
            $car = Car::query()->whereKey((int) $input['car_id'])->where('user_id', $owner->id)->lockForUpdate()->first();
            $application = DB::table('mobility_host_applications')->where('user_id', $owner->id)->where('car_id', (int) $input['car_id'])->where('status', 'approved')->lockForUpdate()->first();
            if (! $car || ! $application || ! DriverOnboardingPolicy::approvedApplication($application) || $car->trashed() || ! $car->isComplete() || CohortPolicy::vehicleFingerprint($car) !== $application->vehicle_fingerprint) {
                throw new OfferException('HOST_APPROVAL_REQUIRED', 403);
            }
            $existing = DB::table('mobility_pool_publications')->where('user_id', $owner->id)->where('key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new OfferException('IDEMPOTENCY_CONFLICT', 409);
                }

                return ['status' => 200, 'data' => $this->responseForExisting($existing)];
            }
            if (config('app.timezone') !== 'Asia/Kolkata') {
                throw new OfferException('MOBILITY_TIMEZONE_NOT_CONFIGURED', 503);
            }
            if ($departure->lessThanOrEqualTo(now('Asia/Kolkata')->addMinutes(15)) || $departure->greaterThan(now('Asia/Kolkata')->addDays(30))) {
                throw new OfferException('DEPARTURE_INVALID', 422);
            }

            if (config('carpoolear.module_trip_creation_payment_enabled')) {
                throw new OfferException('LEGACY_CREATION_PAYMENT_NOT_SUPPORTED', 503);
            }
            $quote = DB::table('mobility_route_quotes')->where('id', $input['quote_id'])->lockForUpdate()->first();
            if (! $quote || (int) $quote->user_id !== (int) $owner->id) {
                throw new OfferException('QUOTE_NOT_FOUND', 404);
            }
            $snapshot = json_decode($quote->snapshot, true, 512, JSON_THROW_ON_ERROR);
            if (($snapshot['vehicle_type'] ?? null) !== $application->vehicle_type || (string) $quote->college_code !== $college || ($snapshot['college_code'] ?? $college) !== $college) {
                throw new OfferException('QUOTE_VEHICLE_INVALID', 409);
            }
            if (($quote->dispatch_request_id ?? null) !== null || $quote->consumed_trip_id !== null || CarbonImmutable::parse($quote->expires_at, 'UTC')->isPast()) {
                throw new OfferException('QUOTE_EXPIRED', 409);
            }
            if (($snapshot['currency'] ?? null) !== 'INR' || ! is_int($snapshot['seat_price_minor'] ?? null) || $snapshot['seat_price_minor'] < 0 || $snapshot['seat_price_minor'] > 100000000
                || ! is_int($snapshot['distance_meters'] ?? null) || $snapshot['distance_meters'] < 1 || $snapshot['distance_meters'] > 100000
                || ! is_int($snapshot['duration_seconds'] ?? null) || $snapshot['duration_seconds'] < 1 || $snapshot['duration_seconds'] > 86400
                || ! is_bool($snapshot['synthetic_only'] ?? null) || ($snapshot['synthetic_only'] && ! app()->environment('testing'))) {
                throw new OfferException('QUOTE_INVALID', 409);
            }
            $synthetic = (bool) $snapshot['synthetic_only'];
            if (! RouteDataVersion::allowed($snapshot['data_version'] ?? null, (string) $snapshot['vehicle_type'], $synthetic)) {
                throw new OfferException('QUOTE_INVALID', 409);
            }
            $points = $snapshot['points'] ?? [];
            $pickupInOrder = collect($snapshot['pickup_points'] ?? [])->values();
            if (! is_array($points) || ! array_is_list($points) || count($points) < 2 || count($points) > 8 || $pickupInOrder->count() !== count($points)
                || $pickupInOrder->pluck('id')->unique()->count() !== $pickupInOrder->count() || $pickupInOrder->pluck('canonical_node_id')->unique()->count() !== $pickupInOrder->count()) {
                throw new OfferException('QUOTE_STOPS_CHANGED', 409);
            }
            $pickup = $pickupInOrder->sortBy('id')->values();
            $pointIds = $pickup->pluck('id')->map(fn ($id) => (int) $id)->all();
            $rows = DB::table('mobility_pickup_points')->whereIn('id', $pointIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $nodeIds = $rows->pluck('canonical_node_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $nodeRows = DB::table('nodes_geo')->whereIn('id', $nodeIds->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $nodes = [];
            foreach ($pickupInOrder as $index => $point) {
                $row = $rows->get((int) $point['id']);
                $canonical = $points[$index] ?? null;
                $modes = json_decode((string) ($row->vehicle_modes ?? '[]'), true);
                $node = $row ? $nodeRows->get((int) $row->canonical_node_id) : null;
                if (! $row || ! $row->active || ! $row->verified_at || $row->college_code !== $college
                    || (int) $row->version !== (int) $point['version'] || (int) $row->canonical_node_id !== (int) $point['canonical_node_id']
                    || ! is_array($modes) || ! in_array($application->vehicle_type, $modes, true)
                    || ((bool) $row->synthetic_only && ! $snapshot['synthetic_only'])
                    || ! is_array($canonical) || ! is_numeric($canonical['lat'] ?? null) || ! is_numeric($canonical['lng'] ?? null) || (string) $row->name !== (string) ($canonical['address'] ?? '')
                    || abs((float) $row->lat - (float) ($canonical['lat'] ?? NAN)) > 0.0000001
                    || abs((float) $row->lng - (float) ($canonical['lng'] ?? NAN)) > 0.0000001
                    || (int) ($canonical['json_address']['id'] ?? 0) !== (int) $row->canonical_node_id
                    || ! $node || (string) $node->name !== (string) ($canonical['json_address']['name'] ?? '')
                    || abs((float) $node->lat - (float) $canonical['lat']) > 0.0000001
                    || abs((float) $node->lng - (float) $canonical['lng']) > 0.0000001) {
                    throw new OfferException('QUOTE_STOPS_CHANGED', 409);
                }
                $nodes[] = (int) $row->canonical_node_id;
            }
            if (count($points) < 2 || (int) $input['total_seats'] < 1 || (int) $input['total_seats'] > 5 || (($application->vehicle_type === 'bike') && (int) $input['total_seats'] !== 1)) {
                throw new OfferException('OFFER_INPUT_INVALID', 422);
            }
            $routeSchema = $snapshot['route_schema'] ?? null;
            $legs = $snapshot['legs'] ?? null;
            if ($routeSchema === 'ordered-legs-v1') {
                if (! is_array($legs) || ! array_is_list($legs) || count($legs) !== count($points) - 1) {
                    throw new OfferException('QUOTE_INVALID', 409);
                }
                $distance = 0;
                $duration = 0;
                $fare = 0;
                foreach ($legs as $index => $leg) {
                    if (! is_array($leg) || ($leg['from_index'] ?? null) !== $index || ($leg['to_index'] ?? null) !== $index + 1
                        || ! is_int($leg['distance_meters'] ?? null) || $leg['distance_meters'] < 1 || ! is_int($leg['duration_seconds'] ?? null) || $leg['duration_seconds'] < 1
                        || ! is_int($leg['seat_price_minor'] ?? null) || $leg['seat_price_minor'] < 0 || ($leg['provider'] ?? null) !== $snapshot['provider'] || ($leg['profile'] ?? null) !== $snapshot['profile']
                        || ($leg['data_version'] ?? null) !== ($snapshot['data_version'] ?? null)) {
                        throw new OfferException('QUOTE_INVALID', 409);
                    }
                    $distance += $leg['distance_meters'];
                    $duration += $leg['duration_seconds'];
                    $fare += $leg['seat_price_minor'];
                }
                if ($distance !== $snapshot['distance_meters'] || $duration !== $snapshot['duration_seconds'] || $fare !== $snapshot['seat_price_minor']) {
                    throw new OfferException('QUOTE_INVALID', 409);
                }
            } elseif ($routeSchema !== null || $legs !== null || count($points) > 2) {
                throw new OfferException('QUOTE_INVALID', 409);
            }
            $quoteForContext = array_merge($snapshot, ['distance_meters' => $snapshot['distance_meters'], 'duration_seconds' => $snapshot['duration_seconds'], 'seat_price_minor' => $snapshot['seat_price_minor']]);
            TrustedRouteContext::push($points, $quoteForContext);
            try {
                $trip = $this->manager->create($owner, [
                    'is_passenger' => 0, 'car_id' => (int) $car->id, 'state' => Trip::STATE_READY,
                    'from_town' => (string) $points[0]['address'], 'to_town' => (string) end($points)['address'],
                    'punto_partida' => (string) $points[0]['address'], 'punto_llegada' => (string) end($points)['address'],
                    'trip_date' => $departure->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
                    'total_seats' => (int) $input['total_seats'], 'friendship_type_id' => 2,
                    'estimated_time' => gmdate('H:i:s', (int) $snapshot['duration_seconds']), 'distance' => ((int) $snapshot['distance_meters']) / 1000,
                    'co2' => 0, 'description' => (string) ($input['description'] ?? ''), 'points' => $points,
                    'seat_price_cents' => (int) $snapshot['seat_price_minor'],
                ]);
            } finally {
                TrustedRouteContext::reset();
            }
            if (! $trip || ($trip->existing ?? false)) {
                throw new OfferException($trip ? 'CANONICAL_DUPLICATE' : 'CANONICAL_VALIDATION', $trip ? 409 : 422);
            }
            if ((int) $trip->seat_price_cents !== $snapshot['seat_price_minor']) {
                throw new OfferException('QUOTE_FARE_CHANGED', 409);
            }
            DB::table('mobility_route_quotes')->where('id', $quote->id)->update(['consumed_trip_id' => $trip->id, 'updated_at' => now()]);
            DB::table('mobility_pool_publications')->insert(['user_id' => $owner->id, 'key_hash' => $keyHash, 'request_hash' => $requestHash, 'quote_id' => $quote->id, 'trip_id' => $trip->id, 'created_at' => now(), 'updated_at' => now()]);

            return ['status' => 201, 'data' => ['id' => (int) $trip->id, 'existing' => false, 'quote_id' => (string) $quote->id, 'currency' => 'INR', 'seat_price_minor' => (int) $snapshot['seat_price_minor'], 'distance_meters' => (int) $snapshot['distance_meters'], 'duration_seconds' => (int) $snapshot['duration_seconds'], 'data_version' => $snapshot['data_version'] ?? null, 'points' => $points, 'pickup_points' => $snapshot['pickup_points'], 'legs' => $snapshot['legs'] ?? null, 'expires_at' => CarbonImmutable::parse($quote->expires_at, 'UTC')->toISOString(), 'vehicle_type' => $application->vehicle_type, 'synthetic_only' => (bool) ($snapshot['synthetic_only'] ?? false)]];
        });
    }

    private function responseForExisting(object $publication): array
    {
        $quote = DB::table('mobility_route_quotes')->where('id', $publication->quote_id)->first();
        $snapshot = $quote ? json_decode($quote->snapshot, true) : [];
        $trip = Trip::find($publication->trip_id);

        return ['id' => (int) $publication->trip_id, 'existing' => true, 'quote_id' => (string) $publication->quote_id, 'currency' => 'INR', 'seat_price_minor' => (int) ($snapshot['seat_price_minor'] ?? $trip?->seat_price_cents ?? 0), 'distance_meters' => (int) ($snapshot['distance_meters'] ?? 0), 'duration_seconds' => (int) ($snapshot['duration_seconds'] ?? 0), 'data_version' => $snapshot['data_version'] ?? null, 'points' => $snapshot['points'] ?? [], 'pickup_points' => $snapshot['pickup_points'] ?? [], 'legs' => $snapshot['legs'] ?? null, 'expires_at' => $quote ? CarbonImmutable::parse($quote->expires_at, 'UTC')->toISOString() : null, 'vehicle_type' => (string) ($snapshot['vehicle_type'] ?? ''), 'synthetic_only' => (bool) ($snapshot['synthetic_only'] ?? false)];
    }
}
