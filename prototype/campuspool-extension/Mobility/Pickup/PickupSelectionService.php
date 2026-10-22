<?php

namespace STS\Mobility\Pickup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\User;

/** Exact private pickup consent. No canonical trip, seat, quote or money writes. */
final class PickupSelectionService
{
    public function select(User $actor, string $previewId, array $input, string $key): array
    {
        self::fields($input, ['choice', 'expected_plan_version', 'expected_intent_version']);
        $choice = $input['choice'];
        if (! in_array($choice, ['DIRECT_RIDER_PICKUP', 'ON_ROUTE_MEETING_POINT'], true)) {
            throw new PickupException('INPUT_INVALID');
        }
        $planVersion = self::positive($input['expected_plan_version']);
        $intentVersion = self::positive($input['expected_intent_version']);
        $keyHash = self::key($key);
        $requestHash = self::hash([$previewId, $choice, $planVersion, $intentVersion]);
        $preview = $this->row('previews', $previewId);
        $intent = $this->row('intents', $preview->intent_id);
        $plan = $this->row('plans', $preview->plan_id);
        if ((int) $actor->id !== (int) $plan->user_id) throw new PickupException('WRONG_ACTOR', 403);

        return DB::transaction(function () use ($actor, $preview, $intent, $plan, $choice, $planVersion, $intentVersion, $keyHash, $requestHash): array {
            // Immutable preflight identities only; no consistent read before these locks.
            $this->lockTrust([(int) $plan->user_id, (int) $intent->rider_id], (int) $plan->car_id, $plan->id, $intent->id, $preview->id);
            $p = $this->row('plans', $plan->id);
            $i = $this->row('intents', $intent->id);
            $v = $this->row('previews', $preview->id);
            if ((int) $actor->id !== (int) $p->user_id) throw new PickupException('WRONG_ACTOR', 403);
            self::version($p->version, $planVersion);
            self::version($i->version, $intentVersion);
            $this->sourceCurrent($v, $i, $p);
            $find = DB::table('mobility_pickup_selections')->where('driver_id', $actor->id)->where('create_key_hash', $keyHash);
            if ($existing = $find->lockForUpdate()->first()) {
                self::same($existing->request_hash, $requestHash, 'IDEMPOTENCY_CONFLICT');
                $this->integrity($actor, $existing, $v, $i, $p, true);
                if (! in_array($existing->state, ['pending', 'confirmed'], true) || $existing->active_intent_id !== $i->id) {
                    throw new PickupException('SELECTION_CLOSED', 409);
                }
                return $this->result($existing, true, 200);
            }
            $snapshot = self::decode($v->snapshot);
            $option = $this->option($snapshot, $choice, $p, $i);
            $car = PickupPolicy::host(User::find($p->user_id), (int) $p->car_id, $p->vehicle_type);
            $termsJson = self::encode($this->terms($v, $i, $p, $option, $car));
            // The locked intent serializes sequence allocation even when no active row exists.
            $version = ((int) DB::table('mobility_pickup_selections')->where('intent_id', $i->id)->max('version')) + 1;
            $active = DB::table('mobility_pickup_selections')->where('active_intent_id', $i->id)->lockForUpdate()->first();
            if ($active) {
                DB::table('mobility_pickup_selections')->where('id', $active->id)->update([
                    'active_intent_id' => null, 'state' => 'superseded', 'closed_at' => now(), 'updated_at' => now(),
                ]);
            }
            $id = (string) Str::uuid();
            DB::table('mobility_pickup_selections')->insert([
                'id' => $id, 'plan_id' => $p->id, 'intent_id' => $i->id, 'preview_id' => $v->id, 'active_intent_id' => $i->id,
                'plan_version' => $p->version, 'intent_version' => $i->version, 'version' => $version,
                'driver_id' => $p->user_id, 'rider_id' => $i->rider_id, 'car_id' => $p->car_id,
                'college_code' => $p->college_code, 'vehicle_type' => $p->vehicle_type, 'ride_mode' => $p->ride_mode,
                'choice' => $choice, 'state' => 'pending', 'preview_hash' => hash('sha256', $v->snapshot),
                'terms' => $termsJson, 'terms_hash' => hash('sha256', $termsJson),
                'create_key_hash' => $keyHash, 'request_hash' => $requestHash,
                'expires_at' => $this->expiry($v->expires_at, $i->expires_at, $p->expires_at),
                'driver_selected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            // MySQL JSON normalizes keys/whitespace. Consent binds the actual persisted bytes.
            $row = $this->row('selections', $id);
            DB::table('mobility_pickup_selections')->where('id', $id)->update(['terms_hash' => hash('sha256', $row->terms)]);
            return $this->result($this->row('selections', $id), false, 201);
        });
    }

    public function show(User $actor, string $id): array
    {
        $row = $this->row('selections', $id);
        $this->integrity($actor, $row, $this->row('previews', $row->preview_id), $this->row('intents', $row->intent_id), $this->row('plans', $row->plan_id), true);
        return $this->result($row, false, 200);
    }

    public function action(User $actor, string $id, string $kind, array $input, string $key): array
    {
        if (! in_array($kind, ['confirm', 'decline', 'revoke'], true)) throw new PickupException('INPUT_INVALID');
        self::fields($input, $kind === 'confirm' ? ['expected_version', 'terms_hash', 'access_acknowledged'] : ['expected_version', 'terms_hash']);
        $version = self::positive($input['expected_version']);
        $termsHash = $input['terms_hash'];
        if (! is_string($termsHash) || ! preg_match('/^[a-f0-9]{64}$/D', $termsHash) || ($kind === 'confirm' && $input['access_acknowledged'] !== true)) {
            throw new PickupException('INPUT_INVALID');
        }
        $keyHash = self::key($key);
        $requestHash = self::hash([$id, $kind, $version, $termsHash, $kind === 'confirm']);
        $preflight = $this->row('selections', $id);
        $this->participant($actor, $preflight, $kind);
        return DB::transaction(function () use ($actor, $id, $kind, $version, $termsHash, $keyHash, $requestHash, $preflight): array {
            $this->lockTrust([(int) $preflight->driver_id, (int) $preflight->rider_id], (int) $preflight->car_id, $preflight->plan_id, $preflight->intent_id, $preflight->preview_id);
            $row = DB::table('mobility_pickup_selections')->where('id', $id)->lockForUpdate()->first();
            $this->participant($actor, $row, $kind);
            self::version($row->version, $version, 'VERSION_MISMATCH');
            self::same($row->terms_hash, $termsHash, 'HASH_MISMATCH');
            $this->integrity($actor, $row, $this->row('previews', $row->preview_id), $this->row('intents', $row->intent_id), $this->row('plans', $row->plan_id), $kind === 'confirm');
            $retry = DB::table('mobility_pickup_selection_actions')->where('actor_id', $actor->id)->where('key_hash', $keyHash)->lockForUpdate()->first();
            if ($retry) {
                self::same($retry->request_hash, $requestHash, 'IDEMPOTENCY_CONFLICT');
                if ($retry->selection_id !== $id || $retry->action !== $kind) throw new PickupException('IDEMPOTENCY_CONFLICT', 409);
                return $this->result($row, true, 200);
            }
            $update = ['updated_at' => now()];
            if ($kind === 'confirm') {
                if ($row->state === 'confirmed' && $row->active_intent_id === $row->intent_id) {
                    // A new key cannot produce a second consent transition or change its timestamp.
                    $update = [];
                } elseif ($row->state === 'pending' && $row->active_intent_id === $row->intent_id) {
                    $update += ['state' => 'confirmed', 'rider_confirmed_at' => now()];
                } else {
                    throw new PickupException('SELECTION_CLOSED', 409);
                }
            } elseif ($kind === 'decline') {
                if ($row->state === 'confirmed') throw new PickupException('CONFIRMATION_REQUIRES_REVOKE', 409);
                if ($row->state !== 'pending' || $row->active_intent_id !== $row->intent_id) throw new PickupException('SELECTION_CLOSED', 409);
                $update += ['state' => 'declined', 'active_intent_id' => null, 'closed_at' => now()];
            } else {
                if (! in_array($row->state, ['pending', 'confirmed'], true) || $row->active_intent_id !== $row->intent_id) throw new PickupException('SELECTION_CLOSED', 409);
                $update += ['state' => 'revoked', 'active_intent_id' => null, 'closed_at' => now()];
            }
            if ($update !== []) DB::table('mobility_pickup_selections')->where('id', $id)->update($update);
            DB::table('mobility_pickup_selection_actions')->insert([
                'selection_id' => $id, 'actor_id' => $actor->id, 'action' => $kind, 'key_hash' => $keyHash,
                'request_hash' => $requestHash, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $this->result($this->row('selections', $id), false, 200);
        });
    }

    private function integrity(User $actor, object $row, object $preview, object $intent, object $plan, bool $fresh): void
    {
        if (! in_array((int) $actor->id, [(int) $row->driver_id, (int) $row->rider_id], true)) throw new PickupException('SELECTION_FORBIDDEN', 403);
        if ($row->plan_id !== $plan->id || $row->intent_id !== $intent->id || $row->preview_id !== $preview->id
            || $preview->plan_id !== $plan->id || $preview->intent_id !== $intent->id || $intent->plan_id !== $plan->id
            || (int) $plan->user_id !== (int) $row->driver_id || (int) $intent->driver_id !== (int) $row->driver_id
            || (int) $intent->rider_id !== (int) $row->rider_id || (int) $row->car_id !== (int) $plan->car_id
            || $row->college_code !== $plan->college_code || $row->vehicle_type !== $plan->vehicle_type || $row->ride_mode !== $plan->ride_mode) {
            throw new PickupException('LINKAGE_MISMATCH', 409);
        }
        $this->eligible($intent, $plan);
        self::same($row->terms_hash, hash('sha256', $row->terms), 'HASH_MISMATCH');
        self::same($row->preview_hash, hash('sha256', $preview->snapshot), 'HASH_MISMATCH');
        $terms = self::decode($row->terms);
        $car = Car::find($row->car_id);
        self::same((string) ($terms['vehicle_fingerprint'] ?? ''), CohortPolicy::vehicleFingerprint($car), 'VEHICLE_CHANGED');
        foreach (['plan_id', 'intent_id', 'preview_id', 'college_code', 'vehicle_type', 'ride_mode', 'driver_id', 'rider_id', 'car_id', 'plan_version', 'intent_version', 'preview_hash'] as $key) {
            if (($terms[$key] ?? null) != $row->$key) throw new PickupException('LINKAGE_MISMATCH', 409);
        }
        if (($terms['schema'] ?? null) !== 'pickup-agreement-v1' || ($terms['scope'] ?? null) !== 'pickup_only' || ($terms['booking_enabled'] ?? null) !== false || ($terms['access_verified'] ?? null) !== false) {
            throw new PickupException('LINKAGE_MISMATCH', 409);
        }
        if ($fresh) {
            self::version($row->plan_version, (int) $plan->version);
            self::version($row->intent_version, (int) $intent->version);
            $this->sourceCurrent($preview, $intent, $plan);
            $this->notExpired($row->expires_at);
            $option = $this->option(self::decode($preview->snapshot), $row->choice, $plan, $intent);
            self::same(self::canonical($terms), self::canonical($this->terms($preview, $intent, $plan, $option, $car)), 'HASH_MISMATCH');
        }
    }

    private function sourceCurrent(object $preview, object $intent, object $plan): void
    {
        $this->eligible($intent, $plan);
        PublishedPickupPlan::current($plan);
        if ($intent->plan_id !== $plan->id || $preview->plan_id !== $plan->id || $preview->intent_id !== $intent->id
            || (int) $intent->plan_version !== (int) $plan->version || (int) $preview->plan_version !== (int) $plan->version
            || (int) $preview->intent_version !== (int) $intent->version) throw new PickupException('REVISION_MISMATCH', 409);
        foreach ([$plan->expires_at, $intent->expires_at, $preview->expires_at] as $expiry) $this->notExpired($expiry);
        $snapshot = self::decode($preview->snapshot);
        $preferences = $snapshot['preferences'] ?? null;
        if (! is_array($preferences) || ! array_key_exists('max_walk_meters', $preferences)
            || ! is_int($preferences['max_walk_meters'])) {
            throw new PickupException('PICKUP_WALK_POLICY_MISSING', 409);
        }
        PickupPolicy::walkPreference($preferences['max_walk_meters']);
        if ($preferences['max_walk_meters'] !== (int) $intent->max_walk_meters) {
            throw new PickupException('PICKUP_WALK_PREFERENCE_MISMATCH', 409);
        }
        if (($snapshot['vehicle_data_version'] ?? null) !== $plan->path_data_version) throw new PickupException('PICKUP_GRAPH_MISMATCH', 409);
        PickupPolicy::graph($plan->vehicle_type, $plan->path_data_version);
        if (($snapshot['foot_data_version'] ?? null) !== null) PickupPolicy::graph('foot', (string) $snapshot['foot_data_version']);
    }

    private function eligible(object $intent, object $plan): void
    {
        if ($plan->college_code !== CohortPolicy::college() || (int) $plan->user_id !== (int) $intent->driver_id) throw new PickupException('LINKAGE_MISMATCH', 409);
        $driver = User::find($intent->driver_id); $rider = User::find($intent->rider_id);
        if (! $driver || ! $rider) throw new PickupException('MEMBERSHIP_REQUIRED', 403);
        PickupPolicy::actor($rider);
        PickupPolicy::host($driver, (int) $plan->car_id, $plan->vehicle_type);
    }

    private function option(array $snapshot, string $choice, object $plan, object $intent): array
    {
        $option = $snapshot[$choice === 'DIRECT_RIDER_PICKUP' ? 'direct' : 'meeting'] ?? null;
        if (! is_array($option) || ($option['available'] ?? false) !== true || ($option['choice'] ?? null) !== $choice) throw new PickupException('CHOICE_UNAVAILABLE');
        $path = $option[$choice === 'DIRECT_RIDER_PICKUP' ? 'path' : 'vehicle_path'] ?? null;
        $this->validatePath($path, $plan->vehicle_type, (string) ($snapshot['vehicle_data_version'] ?? ''), 3);
        $points = self::decode($plan->points);
        $origin = self::decode($intent->origin);
        if ($plan->source_publication_id ?? null) {
            try {
                $pickup = $choice === 'DIRECT_RIDER_PICKUP' ? $origin : $path['waypoints'][1]['location'];
                $expected = (new \STS\Mobility\Journeys\CanonicalRouteIntervalProjector())->pickupPath(self::decode($plan->path_snapshot), $pickup, (string) $plan->source_publication_route_hash);
                if (self::canonical($expected) !== self::canonical($path)) throw new \InvalidArgumentException('PUBLICATION_PREVIEW_CHANGED');
            } catch (\InvalidArgumentException) { throw new PickupException('PUBLICATION_PREVIEW_CHANGED', 409); }
        }
        foreach ([0, 1] as $index) {
            $point = $points[$index];
            if ($this->distance([$point['lng'], $point['lat']], $path['waypoints'][$index * 2]['location']) > 250) throw new PickupException('MALFORMED_OPTION');
        }
        $this->pair($option['vehicle_pickup_location'] ?? null);
        if ($option['vehicle_pickup_location'] != $path['waypoints'][1]['location']) throw new PickupException('MALFORMED_OPTION');
        if ($choice === 'DIRECT_RIDER_PICKUP') {
            $this->nonnegative($option['vehicle_snap_meters'] ?? null);
            if (abs((float) $option['vehicle_snap_meters'] - (float) $path['waypoints'][1]['distance_meters']) > 0.01
                || (float) $option['vehicle_snap_meters'] + 0.01 < $this->distance([$origin['lng'], $origin['lat']], $option['vehicle_pickup_location'])) {
                throw new PickupException('MALFORMED_OPTION');
            }
        }
        $this->nonnegative($option['detour']['added_distance_meters'] ?? null);
        $this->nonnegative($option['detour']['added_duration_seconds'] ?? null);
        if (($option['access_verified'] ?? null) !== false || ($option['requires_pickup_confirmation'] ?? null) !== true) throw new PickupException('MALFORMED_OPTION');
        if ($choice === 'ON_ROUTE_MEETING_POINT') {
            $this->validatePath($option['walking_path'] ?? null, 'foot', (string) ($snapshot['foot_data_version'] ?? ''), 2);
            $this->pair($option['pedestrian_pickup_location'] ?? null);
            if ($option['pedestrian_pickup_location'] != $option['walking_path']['waypoints'][1]['location']) throw new PickupException('MALFORMED_OPTION');
            foreach (['walking_distance_meters', 'walking_duration_seconds', 'unmapped_access_gap_meters', 'handoff_gap_meters', 'total_access_meters'] as $key) $this->nonnegative($option[$key] ?? null);
            if ($this->distance([$origin['lng'], $origin['lat']], $option['walking_path']['waypoints'][0]['location']) > 50
                || $this->distance($option['vehicle_pickup_location'], $option['pedestrian_pickup_location']) > 50
                || $option['walking_distance_meters'] !== $option['walking_path']['distance_meters']
                || $option['walking_duration_seconds'] !== $option['walking_path']['duration_seconds']) throw new PickupException('MALFORMED_OPTION');
            $snaps = array_sum(array_map(static fn (array $waypoint): float => (float) ($waypoint['distance_meters'] ?? 0), $option['walking_path']['waypoints']));
            $handoff = $this->distance($option['vehicle_pickup_location'], $option['pedestrian_pickup_location']);
            if (! is_int($option['total_access_meters'])
                || $option['unmapped_access_gap_meters'] !== (int) ceil($snaps)
                || abs((float) $option['handoff_gap_meters'] - $handoff) > 0.01
                || $option['total_access_meters'] !== $option['walking_distance_meters']
                    + $option['unmapped_access_gap_meters'] + (int) ceil($handoff)
                || $option['total_access_meters'] > (int) $this->intentWalkLimit($intent)) {
                throw new PickupException('PICKUP_WALK_LIMIT_EXCEEDED', 409);
            }
        } elseif ($this->distance([$origin['lng'], $origin['lat']], $option['vehicle_pickup_location']) > 250) {
            throw new PickupException('MALFORMED_OPTION');
        }
        return $option;
    }

    private function intentWalkLimit(object $intent): int
    {
        return PickupPolicy::walkPreference((int) $intent->max_walk_meters);
    }

    private function validatePath(mixed $path, string $mode, string $version, int $pointCount): void
    {
        PickupPolicy::graph($mode, $version);
        if (! is_array($path) || ($path['data_version'] ?? null) !== $version || ($path['provider'] ?? null) !== 'osrm'
            || ! is_string($path['profile'] ?? null) || $path['profile'] === '') throw new PickupException('MALFORMED_OPTION');
        foreach (['distance_meters' => 100000, 'duration_seconds' => 86400] as $key => $bound) {
            if (! is_int($path[$key] ?? null) || $path[$key] < 0 || $path[$key] > $bound) throw new PickupException('MALFORMED_OPTION');
        }
        if (! is_array($path['geometry'] ?? null) || ! array_is_list($path['geometry']) || count($path['geometry']) < 2 || count($path['geometry']) > 20000
            || ! is_array($path['waypoints'] ?? null) || ! array_is_list($path['waypoints']) || count($path['waypoints']) !== $pointCount
            || ! is_array($path['legs'] ?? null) || ! array_is_list($path['legs']) || count($path['legs']) !== $pointCount - 1) throw new PickupException('MALFORMED_OPTION');
        foreach ($path['geometry'] as $pair) $this->pair($pair);
        foreach ($path['waypoints'] as $waypoint) {
            $this->pair($waypoint['location'] ?? null);
            $this->nonnegative($waypoint['distance_meters'] ?? null);
        }
        if ($this->distance($path['geometry'][0], $path['waypoints'][0]['location']) > 1
            || $this->distance($path['geometry'][count($path['geometry']) - 1], $path['waypoints'][$pointCount - 1]['location']) > 1) throw new PickupException('MALFORMED_OPTION');
        $distance = 0; $duration = 0;
        foreach ($path['legs'] as $leg) {
            foreach (['distance_meters', 'duration_seconds'] as $key) {
                if (! is_int($leg[$key] ?? null) || $leg[$key] < 0) throw new PickupException('MALFORMED_OPTION');
            }
            $distance += $leg['distance_meters']; $duration += $leg['duration_seconds'];
        }
        // Whole route and individual leg rounding can differ by one per leg.
        if (abs($distance - $path['distance_meters']) > $pointCount || abs($duration - $path['duration_seconds']) > $pointCount) throw new PickupException('MALFORMED_OPTION');
    }

    private function terms(object $preview, object $intent, object $plan, array $option, Car $car): array
    {
        $snapshot = self::decode($preview->snapshot);
        return [
            'schema' => 'pickup-agreement-v1', 'scope' => 'pickup_only', 'booking_enabled' => false,
            'plan_id' => $plan->id, 'intent_id' => $intent->id, 'preview_id' => $preview->id,
            'plan_version' => (int) $preview->plan_version, 'intent_version' => (int) $preview->intent_version,
            'preview_hash' => hash('sha256', $preview->snapshot),
            'driver_id' => (int) $plan->user_id, 'rider_id' => (int) $intent->rider_id, 'car_id' => (int) $plan->car_id,
            'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'college_code' => $plan->college_code,
            'vehicle_type' => $plan->vehicle_type, 'ride_mode' => $plan->ride_mode,
            'rider_origin' => self::decode($intent->origin), 'driver_endpoints' => self::decode($plan->points),
            'graph' => ['vehicle_data_version' => $snapshot['vehicle_data_version'], 'foot_data_version' => $snapshot['foot_data_version'] ?? null],
            'preferences' => $snapshot['preferences'] ?? [], 'selected_option' => $option, 'access_verified' => false,
        ];
    }

    private function lockTrust(array $ids, int $carId, string $plan, string $intent, string $preview): void
    {
        $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC);
        DB::table('users')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        DB::table('mobility_memberships')->whereIn('user_id', $ids)->orderBy('user_id')->lockForUpdate()->get();
        DB::table('cars')->where('id', $carId)->lockForUpdate()->first();
        DB::table('mobility_host_applications')->where('car_id', $carId)->orderBy('id')->lockForUpdate()->get();
        DB::table('mobility_pickup_plans')->where('id', $plan)->lockForUpdate()->first();
        DB::table('mobility_pickup_intents')->where('id', $intent)->lockForUpdate()->first();
        DB::table('mobility_pickup_previews')->where('id', $preview)->lockForUpdate()->first();
    }

    private function participant(User $actor, object $row, string $kind): void
    {
        $allowed = $kind === 'revoke' ? [(int) $row->driver_id, (int) $row->rider_id] : [(int) $row->rider_id];
        if (! in_array((int) $actor->id, $allowed, true)) throw new PickupException('WRONG_ACTOR', 403);
    }

    private function row(string $suffix, string $id): object
    {
        if (! Str::isUuid($id)) throw new PickupException('PICKUP_NOT_FOUND', 404);
        $row = DB::table('mobility_pickup_'.$suffix)->where('id', $id)->first();
        if (! $row) throw new PickupException('PICKUP_NOT_FOUND', 404);
        return $row;
    }

    private function result(object $r, bool $existing, int $status): array
    {
        return ['status' => $status, 'data' => ['selection' => [
            'id' => $r->id, 'version' => (int) $r->version, 'state' => $r->state, 'choice' => $r->choice,
            'plan_id' => $r->plan_id, 'intent_id' => $r->intent_id, 'preview_id' => $r->preview_id,
            'plan_version' => (int) $r->plan_version, 'intent_version' => (int) $r->intent_version,
            'terms' => self::decode($r->terms), 'terms_hash' => $r->terms_hash, 'expires_at' => $r->expires_at,
            'rider_confirmed_at' => $r->rider_confirmed_at, 'closed_at' => $r->closed_at,
            'scope' => 'pickup_only', 'booking_enabled' => false,
            'actionable' => $r->state === 'pending' && $r->active_intent_id === $r->intent_id,
        ], 'existing' => $existing]];
    }

    private function pair(mixed $pair): void
    {
        if (! is_array($pair) || ! array_is_list($pair) || count($pair) !== 2) throw new PickupException('MALFORMED_OPTION');
        foreach ($pair as $value) if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) throw new PickupException('MALFORMED_OPTION');
        if (abs($pair[0]) > 180 || abs($pair[1]) > 90) throw new PickupException('MALFORMED_OPTION');
    }

    private function nonnegative(mixed $value): void
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0 || $value > 100000000) throw new PickupException('MALFORMED_OPTION');
    }

    private function distance(array $a, array $b): float
    {
        $h = sin(deg2rad($b[1] - $a[1]) / 2) ** 2 + cos(deg2rad($a[1])) * cos(deg2rad($b[1])) * sin(deg2rad($b[0] - $a[0]) / 2) ** 2;
        return 6371000 * 2 * asin(sqrt(min(1, max(0, $h))));
    }

    private function expiry(string ...$values): CarbonImmutable
    {
        $expiry = CarbonImmutable::now()->addMinutes(5);
        foreach ($values as $value) $expiry = $expiry->min(CarbonImmutable::parse($value));
        return $expiry;
    }

    private function notExpired(string $value): void
    {
        if (CarbonImmutable::parse($value)->lessThanOrEqualTo(CarbonImmutable::now())) throw new PickupException('SELECTION_EXPIRED', 410);
    }

    private static function fields(array $input, array $fields): void
    {
        if (array_diff(array_keys($input), $fields) || array_diff($fields, array_keys($input))) throw new PickupException('INPUT_INVALID');
    }

    private static function positive(mixed $value): int
    {
        if (! is_int($value) || $value < 1) throw new PickupException('INPUT_INVALID');
        return $value;
    }

    private static function key(string $value): string
    {
        if (! preg_match('/^[\x21-\x7E]{1,100}$/D', $value)) throw new PickupException('INVALID_IDEMPOTENCY_KEY');
        return hash('sha256', $value);
    }

    private static function decode(string $value): array { return json_decode($value, true, 512, JSON_THROW_ON_ERROR); }
    private static function encode(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); }
    private static function hash(array $value): string { return hash('sha256', self::encode($value)); }
    private static function same(string $actual, string $expected, string $code): void
    {
        if (! hash_equals($actual, $expected)) throw new PickupException($code, 409);
    }
    private static function version(mixed $actual, int $expected, string $code = 'REVISION_MISMATCH'): void
    {
        if ((int) $actual !== $expected) throw new PickupException($code, 409);
    }
    private static function canonical(array $value): string
    {
        $sort = function (array $array) use (&$sort): array {
            if (! array_is_list($array)) ksort($array);
            foreach ($array as &$item) if (is_array($item)) $item = $sort($item);
            return $array;
        };
        return self::encode($sort($value));
    }
}
