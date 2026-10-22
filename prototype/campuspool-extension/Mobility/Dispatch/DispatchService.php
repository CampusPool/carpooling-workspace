<?php

namespace STS\Mobility\Dispatch;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Trust\CohortPolicy;

final class DispatchService
{
    public function availabilityShow(int $driverId): array
    {
        $row = DB::table('mobility_dispatch_availability')->where('driver_id', $driverId)->first();

        return $row ? ['online' => (bool) $row->online, 'scope' => $row->dispatch_scope ?? 'catalog', 'car_id' => (int) $row->car_id, 'pickup_point_id' => $row->pickup_point_id === null ? null : (int) $row->pickup_point_id, 'fresh_until' => $row->fresh_until, 'active' => $row->active_request_id !== null] : ['online' => false, 'scope' => 'catalog', 'car_id' => null, 'pickup_point_id' => null, 'fresh_until' => null, 'active' => false];
    }

    public function availability(int $driverId, array $input): array
    {
        DispatchInputs::fields($input, ['online'], ['car_id', 'pickup_point_id', 'scope']);
        $online = $input['online'];
        if (! is_bool($online)) {
            throw new DispatchException('INPUT_INVALID', 422);
        }

        return DB::transaction(function () use ($driverId, $input, $online): array {
            $row = DB::table('mobility_dispatch_availability')->where('driver_id', $driverId)->lockForUpdate()->first();
            if (! $online) {
                if ($row) {
                    DB::table('mobility_dispatch_availability')->where('driver_id', $driverId)->update(['online' => false, 'last_seen_at' => CarbonImmutable::now('UTC'), 'fresh_until' => CarbonImmutable::now('UTC'), 'version' => (int) $row->version + 1, 'updated_at' => CarbonImmutable::now('UTC')]);
                }

                return ['online' => false];
            }
            DispatchInputs::id($input['car_id'] ?? null, 'CAR_REQUIRED');
            $scope = $input['scope'] ?? 'catalog';
            if (! in_array($scope, ['catalog', 'private'], true)) throw new DispatchException('INPUT_INVALID', 422);
            $pickup = null;
            if ($scope === 'catalog') {
                DispatchInputs::id($input['pickup_point_id'] ?? null, 'PICKUP_REQUIRED');
                $pickup = (int) $input['pickup_point_id'];
            } elseif (array_key_exists('pickup_point_id', $input) && $input['pickup_point_id'] !== null) {
                throw new DispatchException('PRIVATE_PICKUP_FORBIDDEN', 422);
            }
            $eligibility = app(\STS\Mobility\Dispatch\DispatchEligibility::class)->driver($driverId, (int) $input['car_id'], null, $scope === 'catalog' ? $pickup : null);
            $now = CarbonImmutable::now('UTC');
            $values = ['driver_id' => $driverId, 'car_id' => (int) $input['car_id'], 'vehicle_type' => $eligibility['vehicle_type'], 'vehicle_fingerprint' => $eligibility['fingerprint'], 'dispatch_scope' => $scope, 'pickup_point_id' => $pickup, 'online' => true, 'last_seen_at' => $now, 'fresh_until' => $now->addSeconds(60), 'version' => (int) ($row->version ?? 0) + 1, 'updated_at' => $now];
            if ($row?->active_request_id !== null && ((int) $row->car_id !== (int) $input['car_id'] || (string) ($row->dispatch_scope ?? 'catalog') !== $scope || (int) ($row->pickup_point_id ?? 0) !== (int) ($pickup ?? 0))) {
                throw new DispatchException('DRIVER_ASSIGNED', 409);
            }
            DB::table('mobility_dispatch_availability')->updateOrInsert(['driver_id' => $driverId], $values + ['created_at' => $row->created_at ?? $now]);

            return ['online' => true, 'scope' => $scope, 'car_id' => (int) $input['car_id'], 'pickup_point_id' => $pickup, 'fresh_until' => $now->addSeconds(60)->toISOString()];
        });
    }

    public function createRequest(int $riderId, string $key, array $input): array
    {
        DispatchInputs::fields($input, ['quote_id']);
        $key = DispatchInputs::key($key);
        $quoteId = DispatchInputs::uuid($input['quote_id']);
        $hash = DispatchInputs::hash(['quote_id' => $quoteId]);

        return DB::transaction(function () use ($riderId, $key, $hash, $quoteId): array {
            $user = DB::table('users')->where('id', $riderId)->lockForUpdate()->first();
            $membership = DB::table('mobility_memberships')->where('user_id', $riderId)->lockForUpdate()->first();
            $existing = DB::table('mobility_dispatch_requests')->where('rider_id', $riderId)->where('key_hash', hash('sha256', $key))->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals((string) $existing->request_hash, $hash)) {
                    throw new DispatchException('IDEMPOTENCY_CONFLICT');
                }

                return ['status' => 200, 'id' => $existing->id];
            }
            if (! $user || ! $user->active || $user->banned || ! $membership || $membership->status !== 'approved' || $membership->college_code !== CohortPolicy::college()) {
                throw new DispatchException('MEMBERSHIP_REQUIRED', 403);
            }
            if (DB::table('mobility_dispatch_requests')->where('active_rider_id', $riderId)->exists()) {
                throw new DispatchException('RIDER_REQUEST_ACTIVE');
            }
            $quote = DB::table('mobility_route_quotes')->where('id', $quoteId)->lockForUpdate()->first();
            if (! $quote || (int) $quote->user_id !== $riderId) {
                throw new DispatchException('QUOTE_NOT_FOUND', 404);
            }
            if (($quote->dispatch_request_id ?? null) !== null || ($quote->consumed_trip_id ?? null) !== null || ($quote->parent_quote_id ?? null) !== null || DB::table('mobility_pool_publications')->where('quote_id', $quoteId)->exists() || DB::table('mobility_recurring_occurrences')->where('quote_id', $quoteId)->exists() || CarbonImmutable::parse($quote->expires_at, 'UTC')->isPast()) {
                throw new DispatchException('QUOTE_UNAVAILABLE');
            }
            if (DB::table('mobility_dispatch_requests')->where('rider_id', $riderId)->where('created_at', '>=', CarbonImmutable::now('UTC')->subHour())->count() >= 10) {
                throw new DispatchException('REQUEST_RATE_LIMIT', 429);
            }
            if (DB::table('mobility_recurring_programs')->where('source_quote_id', $quoteId)->exists()
                || DB::table('mobility_recurring_actions')->where('actor_id', $riderId)->where('after_snapshot->approval_quote_id', $quoteId)->exists()) {
                throw new DispatchException('QUOTE_UNAVAILABLE');
            }
            $snapshot = json_decode((string) $quote->snapshot, true, 512, JSON_THROW_ON_ERROR);
            $deadline = min(CarbonImmutable::now('UTC')->addSeconds(120)->timestamp, CarbonImmutable::parse($quote->expires_at, 'UTC')->timestamp);
            $terms = DispatchTerms::forQuote($quote);
            $private = ($terms['private'] ?? false) === true;
            if ($private) {
                if (($terms['ride_mode'] ?? null) !== 'on_demand' || (int) ($terms['driver_id'] ?? 0) < 1) {
                    throw new DispatchException('PRIVATE_MODE_UNSUPPORTED', 409);
                }
                // The quote row is locked after the route quote and before trust
                // validation. Quote actions retain trust -> source -> quote order;
                // this lock is only a final accepted-state/CAS gate.
                $privateQuote = DB::table('mobility_private_journey_quotes')->where('id', $terms['journey_quote_id'])->lockForUpdate()->first();
                if (! $privateQuote || $privateQuote->state !== 'accepted' || (string) $privateQuote->route_quote_id !== $quoteId
                    || ! hash_equals((string) $privateQuote->quote_hash, (string) $terms['hash'])) {
                    throw new DispatchException('SOURCE_CHANGED', 409);
                }
                $sourcePlanId = DB::table('mobility_pickup_selections')->where('id', $privateQuote->selection_id)->value('plan_id');
                $sourceCarId = (int) DB::table('mobility_pickup_plans')->where('id', $sourcePlanId)->value('car_id');
                DispatchEligibility::privateCurrent($terms, (int) $terms['driver_id'], $sourceCarId);
            }
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now('UTC');
            DB::table('mobility_dispatch_requests')->insert(['id' => $id, 'rider_id' => $riderId, 'quote_id' => $quoteId, 'quote_hash' => hash('sha256', (string) $quote->snapshot), 'vehicle_type' => (string) ($terms['vehicle_type'] ?? $snapshot['vehicle_type'] ?? ''), 'dispatch_scope' => $private ? 'private' : 'catalog', 'target_driver_id' => $private ? (int) $terms['driver_id'] : null, 'pickup_point_id' => $private ? null : (int) ($snapshot['pickup_points'][0]['id'] ?? 0), 'private_journey_quote_id' => $private ? $terms['journey_quote_id'] : null, 'private_source_hash' => $private ? $terms['hash'] : null, 'key_hash' => hash('sha256', $key), 'request_hash' => $hash, 'state' => 'searching', 'attempt' => 0, 'expires_at' => CarbonImmutable::createFromTimestampUTC($deadline), 'next_attempt_at' => $now, 'active_rider_id' => $riderId, 'created_at' => $now, 'updated_at' => $now]);
            $claimed = DB::table('mobility_route_quotes')->where('id', $quoteId)->whereNull('dispatch_request_id')->update(['dispatch_request_id' => $id, 'updated_at' => $now]);
            if ($claimed !== 1) {
                throw new DispatchException('QUOTE_UNAVAILABLE');
            }
            $this->event($id, null, null, 'request.created', $key, ['quote_id' => $quoteId]);

            return ['status' => 201, 'id' => $id, 'expires_at' => CarbonImmutable::createFromTimestampUTC($deadline)->toISOString()];
        });
    }

    public function showRequest(int $actorId, string $id, bool $admin = false): array
    {
        $request = DB::table('mobility_dispatch_requests')->where('id', DispatchInputs::uuid($id))->first();
        if (! $request) {
            throw new DispatchException('NOT_FOUND', 404);
        }
        $assignment = $request->current_assignment_id ? DB::table('mobility_dispatch_assignments')->where('id', $request->current_assignment_id)->first() : null;
        if (! $admin && (int) $request->rider_id !== $actorId && (! $assignment || (int) $assignment->driver_id !== $actorId)) {
            throw new DispatchException('FORBIDDEN', 403);
        }
        $quote = DB::table('mobility_route_quotes')->where('id', $request->quote_id)->first();
        $snapshot = $quote ? $this->displaySnapshot($quote) : [];

        return ['execution' => $assignment ? ['state' => DB::table('mobility_trip_lifecycles')->where('trip_id', $assignment->trip_id)->value('state') ?? 'planned', 'driver_arrived_at' => DB::table('mobility_trip_events')->where('trip_id', $assignment->trip_id)->where('action', 'arrive')->value('created_at')] : null, 'scope' => $request->dispatch_scope ?? 'catalog', 'pricing' => $snapshot['pricing'] ?? null, 'walking' => $snapshot['walking'] ?? null, 'points' => $snapshot['points'] ?? [], 'id' => $request->id, 'state' => $request->state, 'expires_at' => $request->expires_at, 'vehicle_type' => $request->vehicle_type, 'pickup_point_id' => (int) $request->pickup_point_id, 'fare_minor' => (int) ($snapshot['seat_price_minor'] ?? 0), 'currency' => $snapshot['currency'] ?? 'INR', 'data_version' => $snapshot['data_version'] ?? null, 'synthetic_only' => (bool) ($snapshot['synthetic_only'] ?? false), 'assignment' => $assignment ? ['id' => $assignment->id, 'trip_id' => (int) $assignment->trip_id, 'booking_id' => (int) $assignment->booking_id, 'driver_id' => (int) $assignment->driver_id, 'fare_minor' => (int) $assignment->fare_minor] : null];
    }

    public function requests(int $actorId): array
    {
        return DB::table('mobility_dispatch_requests as r')
            ->where(function ($query) use ($actorId): void {
                $query->where('r.rider_id',$actorId)->orWhereExists(function ($assignment) use ($actorId): void {
                    $assignment->selectRaw('1')->from('mobility_dispatch_assignments as a')->whereColumn('a.request_id','r.id')->where('a.driver_id',$actorId);
                });
            })->orderByDesc('r.created_at')->orderByDesc('r.id')->limit(30)
            ->get(['r.id','r.state','r.vehicle_type','r.dispatch_scope','r.created_at'])->map(fn($row)=>(array)$row)->all();
    }

    private function displaySnapshot(object $quote): array
    {
        $snapshot = json_decode((string)$quote->snapshot,true,512,JSON_THROW_ON_ERROR);
        if (($snapshot['schema'] ?? null) !== 'private-full-journey-quote-v1') return $snapshot;
        $terms = \STS\Mobility\Journeys\PrivateJourneyTerms::forQuote($quote);
        return $terms['snapshot'] + ['pricing'=>$snapshot['pricing'], 'walking'=>[
            'access_meters'=>$snapshot['access']['total_meters'], 'egress_meters'=>$snapshot['egress']['total_meters'],
            'total_meters'=>$snapshot['total_walking_meters'], 'max_meters'=>$snapshot['max_walk_meters']]];
    }

    public function offers(int $driverId): array
    {
        return DB::table('mobility_dispatch_offers as o')->join('mobility_dispatch_requests as r', 'r.id', '=', 'o.request_id')->join('mobility_route_quotes as q', 'q.id', '=', 'r.quote_id')->where('o.driver_id', $driverId)->where('o.state', 'pending')->where('o.expires_at', '>', now('UTC'))->orderBy('o.expires_at')->limit(20)->get(['o.id', 'o.request_id', 'o.expires_at', 'r.vehicle_type', 'r.pickup_point_id', 'r.dispatch_scope', 'r.quote_id', 'q.snapshot'])->map(function ($row) {
            $quote = DB::table('mobility_route_quotes')->where('id',$row->quote_id)->first();
            $snapshot = $quote ? $this->displaySnapshot($quote) : [];

            return ['scope' => $row->dispatch_scope, 'pricing' => $snapshot['pricing'] ?? null, 'walking' => $snapshot['walking'] ?? null, 'points' => $snapshot['points'] ?? [], 'id' => $row->id, 'request_id' => $row->request_id, 'expires_at' => $row->expires_at, 'vehicle_type' => $row->vehicle_type, 'pickup_point_id' => (int) $row->pickup_point_id, 'fare_minor' => (int) ($snapshot['seat_price_minor'] ?? 0), 'currency' => $snapshot['currency'] ?? 'INR', 'data_version' => $snapshot['data_version'] ?? null, 'synthetic_only' => (bool) ($snapshot['synthetic_only'] ?? false)];
        })->all();
    }

    public function decline(int $driverId, string $offerId, string $key): array
    {
        DispatchInputs::uuid($offerId);
        DispatchInputs::key($key);
        $requestId = DB::table('mobility_dispatch_offers')->where('id', $offerId)->value('request_id');
        if (! $requestId) {
            throw new DispatchException('NOT_FOUND', 404);
        }

        return DB::transaction(function () use ($driverId, $offerId, $key, $requestId): array {
            $request = DB::table('mobility_dispatch_requests')->where('id', $requestId)->lockForUpdate()->first();
            $offer = DB::table('mobility_dispatch_offers')->where('id', $offerId)->lockForUpdate()->first();
            if (! $offer || (int) $offer->driver_id !== $driverId) {
                throw new DispatchException('NOT_FOUND', 404);
            }
            if ($offer->state === 'declined') {
                return ['id' => $offerId, 'state' => 'declined'];
            }
            $now = CarbonImmutable::now('UTC');
            if ($offer->state !== 'pending' || CarbonImmutable::parse($offer->expires_at, 'UTC')->lte($now)) {
                throw new DispatchException('OFFER_EXPIRED');
            }
            if (! $request || ! in_array($request->state, ['searching', 'offered'], true) || CarbonImmutable::parse($request->expires_at, 'UTC')->lte($now)) {
                throw new DispatchException('REQUEST_UNAVAILABLE');
            }
            DB::table('mobility_dispatch_offers')->where('id', $offerId)->update(['state' => 'declined', 'updated_at' => $now]);
            if (! DB::table('mobility_dispatch_offers')->where('request_id', $requestId)->where('state', 'pending')->exists()) {
                DB::table('mobility_dispatch_requests')->where('id', $requestId)->update(['state' => 'searching', 'next_attempt_at' => $now, 'updated_at' => $now]);
            }
            $this->event($requestId, $driverId, $offerId, 'offer.declined', $driverId.'|'.$key, ['id' => $offerId, 'state' => 'declined']);

            return ['id' => $offerId, 'state' => 'declined'];
        });
    }

    public function accept(int $driverId, string $offerId, string $key): array
    {
        DispatchInputs::uuid($offerId);
        DispatchInputs::key($key);
        $requestId = DB::table('mobility_dispatch_offers')->where('id', $offerId)->value('request_id');
        if (! $requestId) {
            throw new DispatchException('NOT_FOUND', 404);
        }

        return DB::transaction(function () use ($driverId, $offerId, $key, $requestId): array {
            $request = $requestId ? DB::table('mobility_dispatch_requests')->where('id', $requestId)->lockForUpdate()->first() : null;
            $offer = DB::table('mobility_dispatch_offers')->where('id', $offerId)->lockForUpdate()->first();
            if (! $offer || (int) $offer->driver_id !== $driverId) {
                throw new DispatchException('NOT_FOUND', 404);
            }
            if ($offer->state === 'accepted') {
                $a = DB::table('mobility_dispatch_assignments')->where('offer_id', $offerId)->first();

                return ['id' => $a->id, 'trip_id' => (int) $a->trip_id, 'booking_id' => (int) $a->booking_id];
            }
            if ($offer->state !== 'pending' || CarbonImmutable::parse($offer->expires_at, 'UTC')->lte(CarbonImmutable::now('UTC'))) {
                throw new DispatchException('OFFER_EXPIRED');
            }
            if (! $request || ! in_array($request->state, ['searching', 'offered'], true) || CarbonImmutable::parse($request->expires_at, 'UTC')->lte(CarbonImmutable::now('UTC'))) {
                throw new DispatchException('REQUEST_UNAVAILABLE');
            }
            $availability = DB::table('mobility_dispatch_availability')->where('driver_id', $driverId)->lockForUpdate()->first();
            if (! $availability || ! $availability->online || CarbonImmutable::parse($availability->fresh_until, 'UTC')->lte(CarbonImmutable::now('UTC')) || $availability->active_request_id !== null) {
                throw new DispatchException('DRIVER_UNAVAILABLE');
            }
            $quote = DB::table('mobility_route_quotes')->where('id', $request->quote_id)->lockForUpdate()->first();
            if (! $quote || (string) ($quote->dispatch_request_id ?? '') !== (string) $request->id || ! hash_equals((string) $request->quote_hash, hash('sha256', (string) $quote->snapshot))) {
                throw new DispatchException('QUOTE_CHANGED');
            }
            $terms = app(\STS\Mobility\Dispatch\DispatchTerms::class)->forQuote($quote);
            $private = ($request->dispatch_scope ?? 'catalog') === 'private';
            if (($private ? ((int) $request->target_driver_id !== $driverId || $availability->pickup_point_id !== null) : (int) $availability->pickup_point_id !== (int) $request->pickup_point_id)
                || (int) $terms['rider_id'] !== (int) $request->rider_id || $terms['vehicle_type'] !== $request->vehicle_type || (string) $offer->vehicle_fingerprint !== (string) $availability->vehicle_fingerprint || (int) $offer->car_id !== (int) $availability->car_id || (string) $request->vehicle_type !== (string) $availability->vehicle_type) {
                throw new DispatchException('ELIGIBILITY_CHANGED');
            }
            \STS\Mobility\Admission\DriverReservationPolicy::fence($driverId);
            $currentFingerprint = $private
                ? DispatchEligibility::privateCurrent($terms, $driverId, (int) $availability->car_id)
                : app(\STS\Mobility\Dispatch\DispatchEligibility::class)->current($terms, $driverId, (int) $availability->car_id);
            if (! hash_equals((string) $availability->vehicle_fingerprint, $currentFingerprint)) {
                throw new DispatchException('ELIGIBILITY_CHANGED');
            }
            $created = app(\STS\Mobility\Dispatch\DispatchCanonicalFactory::class)->create($request, $offer, $availability, $terms, CarbonImmutable::now('UTC')->startOfSecond());
            $aid = (string) Str::uuid();
            $now = CarbonImmutable::now('UTC');
            DB::table('mobility_dispatch_assignments')->insert(['id' => $aid, 'request_id' => $request->id, 'offer_id' => $offer->id, 'quote_id' => $request->quote_id, 'quote_hash' => $request->quote_hash, 'driver_id' => $driverId, 'rider_id' => $request->rider_id, 'trip_id' => $created['trip_id'], 'booking_id' => $created['booking_id'], 'fare_minor' => $terms['fare_minor'], 'active_request_id' => $request->id, 'active_driver_id' => $driverId, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('mobility_dispatch_offers')->where('id', $offerId)->update(['state' => 'accepted', 'updated_at' => $now]);
            DB::table('mobility_dispatch_requests')->where('id', $request->id)->update(['state' => 'assigned', 'current_assignment_id' => $aid, 'next_attempt_at' => $now, 'updated_at' => $now]);
            DB::table('mobility_dispatch_availability')->where('driver_id', $driverId)->update(['active_request_id' => $request->id, 'online' => false, 'updated_at' => $now]);
            DB::table('mobility_route_quotes')->where('id', $quote->id)->whereNull('consumed_trip_id')->update(['consumed_trip_id' => $created['trip_id'], 'updated_at' => $now]);
            DB::table('mobility_dispatch_offers')->where('request_id', $request->id)->where('id', '<>', $offerId)->where('state', 'pending')->update(['state' => 'revoked', 'updated_at' => $now]);
            $this->event($request->id, $driverId, $offerId, 'offer.accepted', $key, ['assignment_id' => $aid, 'trip_id' => $created['trip_id']]);

            return ['id' => $aid, 'trip_id' => (int) $created['trip_id'], 'booking_id' => (int) $created['booking_id']];
        });
    }

    public function cancel(int $actorId, string $requestId, string $key): array
    {
        DispatchInputs::uuid($requestId);
        DispatchInputs::key($key);

        return DB::transaction(function () use ($actorId, $requestId, $key): array {
            $request = DB::table('mobility_dispatch_requests')->where('id', $requestId)->lockForUpdate()->first();
            if (! $request) {
                throw new DispatchException('NOT_FOUND', 404);
            }
            // Actor + key identify the original action across later reassignment.
            // Its stored payload binds the original assignment. A retry cannot choose a new one.
            $eventKey = hash('sha256', 'request.cancel|'.$requestId.'|'.$actorId.'|'.$key);
            $replay = DB::table('mobility_dispatch_events')->where('event_key', $eventKey)->first();
            if ($replay) {
                $payload = json_decode($replay->payload, true, 512, JSON_THROW_ON_ERROR);
                if (($payload['actor_id'] ?? null) !== $actorId) {
                    throw new DispatchException('FORBIDDEN', 403);
                }

                return $payload;
            }
            $assignment = $request->current_assignment_id ? DB::table('mobility_dispatch_assignments')->where('id', $request->current_assignment_id)->first() : null;
            if ((int) $request->rider_id !== $actorId && (! $assignment || (int) $assignment->driver_id !== $actorId || $request->state !== 'assigned')) {
                throw new DispatchException('FORBIDDEN', 403);
            }
            if (! in_array($request->state, ['searching', 'offered', 'assigned'], true)) {
                throw new DispatchException('REQUEST_TERMINAL');
            }
            $now = CarbonImmutable::now('UTC');
            $state = 'cancelled';
            if ($request->state === 'assigned') {
                if (! $assignment) {
                    throw new DispatchException('ASSIGNMENT_REQUIRED');
                }
                DB::table('mobility_dispatch_offers')->where('request_id', $requestId)->orderBy('id')->lockForUpdate()->get();
                DB::table('mobility_dispatch_availability')->where('driver_id', $assignment->driver_id)->lockForUpdate()->first();
                $trip = \STS\Models\Trip::whereKey($assignment->trip_id)->lockForUpdate()->firstOrFail();
                $r = Request::create('/api/mobility/trips/'.$trip->id.'/transitions', 'POST', ['action' => 'cancel']);
                $r->headers->set('Idempotency-Key', 'dispatch:'.substr(hash('sha256', $actorId.'|'.$key), 0, 64));
                $r->setUserResolver(fn () => \STS\Models\User::findOrFail($actorId));
                $response = app(\STS\Mobility\MobilityController::class)->transition($r, $trip);
                if ($response->getStatusCode() >= 400) {
                    throw new DispatchException('CANONICAL_CANCEL_FAILED', $response->getStatusCode());
                }
                DB::table('mobility_dispatch_assignments')->where('id', $assignment->id)->update(['active_request_id' => null, 'active_driver_id' => null, 'updated_at' => $now]);
                DB::table('mobility_dispatch_availability')->where('driver_id', $assignment->driver_id)->where('active_request_id', $requestId)->update(['active_request_id' => null, 'online' => false, 'updated_at' => $now]);
                if ((int) $assignment->driver_id === $actorId && (int) $request->attempt < 3 && CarbonImmutable::parse($request->expires_at, 'UTC')->gt($now)) {
                    $state = 'searching';
                }
            }
            DB::table('mobility_dispatch_offers')->where('request_id', $requestId)->where('state', 'pending')->update(['state' => 'revoked', 'updated_at' => $now]);
            DB::table('mobility_dispatch_requests')->where('id', $requestId)->update(['state' => $state, 'active_rider_id' => $state === 'searching' ? $request->rider_id : null, 'next_attempt_at' => $now, 'updated_at' => $now]);
            $result = ['id' => $requestId, 'state' => $state, 'actor_id' => $actorId, 'assignment_id' => $assignment?->id];
            $this->event($requestId, $assignment?->driver_id, $assignment?->offer_id, 'request.cancel', $actorId.'|'.$key, $result);

            return $result;
        });
    }

    private function event(string $requestId, ?int $driverId, ?string $offerId, string $action, string $key, array $payload): void
    {
        DB::table('mobility_dispatch_events')->insertOrIgnore(['request_id' => $requestId, 'driver_id' => $driverId, 'offer_id' => $offerId, 'action' => $action, 'event_key' => hash('sha256', $action.'|'.$requestId.'|'.$key), 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => CarbonImmutable::now('UTC')]);
    }
}
