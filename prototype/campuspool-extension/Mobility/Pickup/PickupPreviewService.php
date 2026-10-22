<?php

namespace STS\Mobility\Pickup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Routing\RoutingException;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\User;

/** Actor-scoped route proposals. Canonical trips, seats and fares are untouched. */
final class PickupPreviewService
{
    public function __construct(private readonly PathProvider $paths, private readonly PickupPreviewPlanner $planner) {}

    public function createPlan(User $actor, array $input, string $key): array
    {
        $published = null;
        if (array_key_exists('source_publication_id', $input)) {
            self::fields($input, ['source_publication_id', 'expected_route_hash']);
            if (! is_string($input['source_publication_id']) || ! is_string($input['expected_route_hash'])) throw new PickupException('INPUT_INVALID');
            $published = PublishedPickupPlan::prepare($actor, $input['source_publication_id'], $input['expected_route_hash']);
        }
        $v = $published ? $published['input'] : self::planInput($input);
        $keyHash = self::key($key);
        $hash = self::hash($v);
        PickupPolicy::host($actor, $v['car_id'], $v['vehicle_type']);
        $query = fn () => DB::table('mobility_pickup_plans')->where('user_id', $actor->id)->where('create_key_hash', $keyHash);
        if ($existing = $query()->first()) {
            $this->planCurrent($existing);
            self::same($existing->request_hash, $hash);
            return self::result('plan', $this->planData($existing), true);
        }
        $path = $published ? $published['path'] : $this->path($v['points'], $v['vehicle_type']);
        return DB::transaction(function () use ($actor, $v, $keyHash, $hash, $path, $query): array {
            $this->lockTrust([(int) $actor->id], $v['car_id']);
            PickupPolicy::host($actor, $v['car_id'], $v['vehicle_type']);
            PickupPolicy::graph($v['vehicle_type'], $path['data_version']);
            if (isset($v['source_publication_id'])) {
                PublishedPickupPlan::current((object) ($v + ['user_id' => $actor->id, 'path_snapshot' => json_encode($path, JSON_THROW_ON_ERROR), 'path_data_version' => $path['data_version']]));
            }
            if ($existing = $query()->lockForUpdate()->first()) {
                $this->planCurrent($existing);
                self::same($existing->request_hash, $hash);
                return self::result('plan', $this->planData($existing), true);
            }
            $token = bin2hex(random_bytes(32));
            $id = (string) Str::uuid();
            $source = isset($v['source_publication_id']) ? ['source_publication_id' => $v['source_publication_id'], 'source_publication_route_hash' => $v['source_publication_route_hash']] : [];
            DB::table('mobility_pickup_plans')->insert($source + [
                'id' => $id, 'user_id' => $actor->id, 'college_code' => CohortPolicy::college(), 'car_id' => $v['car_id'],
                'vehicle_type' => $v['vehicle_type'], 'ride_mode' => $v['ride_mode'], 'points' => json_encode($v['points'], JSON_THROW_ON_ERROR),
                'path_snapshot' => json_encode($path, JSON_THROW_ON_ERROR), 'path_data_version' => $path['data_version'], 'version' => 1,
                'create_key_hash' => $keyHash, 'request_hash' => $hash, 'invite_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $result = self::result('plan', $this->planData($this->row('plans', $id)), false);
            $result['data']['invite_token'] = $token;
            return $result;
        });
    }

    public function updatePlan(User $actor, string $id, array $input): array
    {
        $v = self::planInput($input, true);
        $expected = self::integer($input['expected_version'], 1, PHP_INT_MAX);
        $plan = $this->row('plans', $id);
        $this->owner($actor, $plan);
        $this->planCurrent($plan);
        if ($plan->source_publication_id ?? null) throw new PickupException('PUBLICATION_PLAN_IMMUTABLE', 409);
        if ($v['car_id'] !== (int) $plan->car_id || $v['vehicle_type'] !== $plan->vehicle_type || $v['ride_mode'] !== $plan->ride_mode) {
            throw new PickupException('PLAN_IDENTITY_IMMUTABLE', 409);
        }
        self::version($plan->version, $expected, 'PLAN_VERSION_STALE');
        $path = $this->path($v['points'], $v['vehicle_type']);
        return DB::transaction(function () use ($actor, $id, $v, $expected, $path): array {
            $this->lockTrust([(int) $actor->id], $v['car_id'], $id);
            $plan = $this->row('plans', $id);
            $this->owner($actor, $plan);
            $this->planCurrent($plan);
            self::version($plan->version, $expected, 'PLAN_VERSION_STALE');
            PickupPolicy::graph($v['vehicle_type'], $path['data_version']);
            $token = bin2hex(random_bytes(32));
            DB::table('mobility_pickup_plans')->where('id', $id)->update([
                'points' => json_encode($v['points'], JSON_THROW_ON_ERROR), 'path_snapshot' => json_encode($path, JSON_THROW_ON_ERROR),
                'path_data_version' => $path['data_version'], 'invite_hash' => hash('sha256', $token), 'version' => $expected + 1,
                'expires_at' => now()->addMinutes(30), 'updated_at' => now(),
            ]);
            // Original creation hash remains immutable for safe creation retries.
            $result = self::result('plan', $this->planData($this->row('plans', $id)), true);
            $result['data']['invite_token'] = $token;
            return $result;
        });
    }

    public function plan(User $actor, string $id): array
    {
        $plan = $this->row('plans', $id);
        $this->owner($actor, $plan);
        $this->planCurrent($plan);
        return ['data' => ['plan' => $this->planData($plan)]];
    }

    public function createIntent(User $actor, array $input, string $key): array
    {
        self::fields($input, ['plan_id', 'invite_token', 'origin', 'max_walk_meters']);
        $origin = self::point($input['origin']);
        $walk = PickupPolicy::walkPreference(self::integer($input['max_walk_meters'], 0, 10000), 422);
        $keyHash = self::key($key);
        $plan = $this->row('plans', self::uuid($input['plan_id']));
        $this->planCurrent($plan);
        PickupPolicy::actor($actor);
        if ((int) $actor->id === (int) $plan->user_id) {
            throw new PickupException('RIDER_CANNOT_BE_DRIVER', 403);
        }
        $token = $input['invite_token'];
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token) || ! hash_equals($plan->invite_hash, hash('sha256', $token))) {
            throw new PickupException('PLAN_INVITE_INVALID', 403);
        }
        $hash = self::hash(['plan_id' => $plan->id, 'origin' => $origin, 'max_walk_meters' => $walk]);
        return DB::transaction(function () use ($actor, $plan, $origin, $walk, $token, $keyHash, $hash): array {
            $this->lockTrust([(int) $actor->id, (int) $plan->user_id], (int) $plan->car_id, $plan->id);
            $current = $this->row('plans', $plan->id);
            $this->planCurrent($current);
            PickupPolicy::actor($actor);
            if (! hash_equals($current->invite_hash, hash('sha256', $token))) {
                throw new PickupException('PLAN_INVITE_INVALID', 403);
            }
            $existing = DB::table('mobility_pickup_intents')->where('rider_id', $actor->id)->where('create_key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing) {
                self::same($existing->request_hash, $hash);
                $this->intentCurrent($actor, $existing, $current);
                return self::result('intent', $this->intentData($existing), true);
            }
            $id = (string) Str::uuid();
            DB::table('mobility_pickup_intents')->insert([
                'id' => $id, 'plan_id' => $current->id, 'rider_id' => $actor->id, 'driver_id' => $current->user_id,
                'plan_version' => $current->version, 'origin' => json_encode($origin, JSON_THROW_ON_ERROR), 'max_walk_meters' => $walk,
                'version' => 1, 'create_key_hash' => $keyHash, 'request_hash' => $hash,
                'expires_at' => $this->expiry(30, $current->expires_at), 'created_at' => now(), 'updated_at' => now(),
            ]);
            return self::result('intent', $this->intentData($this->row('intents', $id)), false);
        });
    }

    public function updateIntent(User $actor, string $id, array $input): array
    {
        self::fields($input, ['expected_version', 'origin', 'max_walk_meters']);
        $origin = self::point($input['origin']);
        $walk = PickupPolicy::walkPreference(self::integer($input['max_walk_meters'], 0, 10000), 422);
        $expected = self::integer($input['expected_version'], 1, PHP_INT_MAX);
        $intent = $this->row('intents', $id);
        if ((int) $actor->id !== (int) $intent->rider_id) {
            throw new PickupException('INTENT_OWNER_REQUIRED', 403);
        }
        $plan = $this->row('plans', $intent->plan_id);
        return DB::transaction(function () use ($actor, $id, $plan, $origin, $walk, $expected): array {
            $this->lockTrust([(int) $actor->id, (int) $plan->user_id], (int) $plan->car_id, $plan->id, $id);
            $currentPlan = $this->row('plans', $plan->id);
            $current = $this->row('intents', $id);
            $this->intentCurrent($actor, $current, $currentPlan, false);
            self::version($current->version, $expected, 'INTENT_VERSION_STALE');
            DB::table('mobility_pickup_intents')->where('id', $id)->update([
                'origin' => json_encode($origin, JSON_THROW_ON_ERROR), 'max_walk_meters' => $walk,
                'plan_version' => $currentPlan->version, 'version' => $expected + 1,
                'expires_at' => $this->expiry(30, $currentPlan->expires_at), 'updated_at' => now(),
            ]);
            return self::result('intent', $this->intentData($this->row('intents', $id)), true);
        });
    }

    public function intent(User $actor, string $id): array
    {
        $intent = $this->row('intents', $id);
        $plan = $this->row('plans', $intent->plan_id);
        $this->intentCurrent($actor, $intent, $plan);
        return ['data' => ['intent' => $this->intentData($intent)]];
    }

    public function preview(User $actor, string $intentId, array $input, string $key): array
    {
        self::fields($input, ['expected_plan_version', 'expected_intent_version', 'max_detour_meters', 'max_detour_seconds']);
        $v = [
            'expected_plan_version' => self::integer($input['expected_plan_version'], 1, PHP_INT_MAX),
            'expected_intent_version' => self::integer($input['expected_intent_version'], 1, PHP_INT_MAX),
            'max_detour_meters' => self::integer($input['max_detour_meters'], 0, 100000),
            'max_detour_seconds' => self::integer($input['max_detour_seconds'], 0, 86400),
        ];
        $hash = self::hash($v); $keyHash = self::key($key);
        $intent = $this->row('intents', $intentId); $plan = $this->row('plans', $intent->plan_id);
        $this->owner($actor, $plan); $this->intentCurrent($actor, $intent, $plan);
        self::version($plan->version, $v['expected_plan_version'], 'PREVIEW_VERSION_STALE');
        self::version($intent->version, $v['expected_intent_version'], 'PREVIEW_VERSION_STALE');
        $find = fn () => DB::table('mobility_pickup_previews')->where('intent_id', $intentId)->where('create_key_hash', $keyHash);
        if ($existing = $find()->first()) {
            self::same($existing->request_hash, $hash);
            $this->previewCurrent($actor, $existing, $intent, $plan);
            return self::result('preview', $this->previewData($existing), true);
        }
        $snapshot = ($plan->source_publication_id ?? null) ? $this->planner->previewPublished(
            json_decode($plan->path_snapshot, true, 512, JSON_THROW_ON_ERROR),
            json_decode($intent->origin, true, 512, JSON_THROW_ON_ERROR), $plan->vehicle_type,
            (int) $intent->max_walk_meters, $v['max_detour_meters'], $v['max_detour_seconds'], (string) $plan->source_publication_route_hash
        ) : $this->planner->preview(
            json_decode($plan->path_snapshot, true, 512, JSON_THROW_ON_ERROR) + ['points' => json_decode($plan->points, true, 512, JSON_THROW_ON_ERROR)],
            json_decode($intent->origin, true, 512, JSON_THROW_ON_ERROR), $plan->vehicle_type,
            (int) $intent->max_walk_meters, $v['max_detour_meters'], $v['max_detour_seconds']
        );
        return DB::transaction(function () use ($actor, $plan, $intent, $intentId, $v, $hash, $keyHash, $snapshot, $find): array {
            $riderId = (int) $intent->rider_id;
            $this->lockTrust([(int) $actor->id, $riderId], (int) $plan->car_id, $plan->id, $intentId);
            $currentPlan = $this->row('plans', $plan->id); $currentIntent = $this->row('intents', $intentId);
            $this->owner($actor, $currentPlan); $this->intentCurrent($actor, $currentIntent, $currentPlan);
            self::version($currentPlan->version, $v['expected_plan_version'], 'PREVIEW_VERSION_STALE');
            self::version($currentIntent->version, $v['expected_intent_version'], 'PREVIEW_VERSION_STALE');
            $this->snapshotGraphs($snapshot, $currentPlan, $currentIntent);
            if ($existing = $find()->lockForUpdate()->first()) {
                self::same($existing->request_hash, $hash);
                $this->previewCurrent($actor, $existing, $currentIntent, $currentPlan);
                return self::result('preview', $this->previewData($existing), true);
            }
            $id = (string) Str::uuid();
            DB::table('mobility_pickup_previews')->insert([
                'id' => $id, 'plan_id' => $plan->id, 'intent_id' => $intentId,
                'plan_version' => $currentPlan->version, 'intent_version' => $currentIntent->version,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'create_key_hash' => $keyHash, 'request_hash' => $hash,
                'expires_at' => $this->expiry(5, $currentPlan->expires_at, $currentIntent->expires_at), 'created_at' => now(), 'updated_at' => now(),
            ]);
            return self::result('preview', $this->previewData($this->row('previews', $id)), false);
        });
    }

    public function showPreview(User $actor, string $id): array
    {
        $preview = $this->row('previews', $id); $intent = $this->row('intents', $preview->intent_id); $plan = $this->row('plans', $preview->plan_id);
        $this->previewCurrent($actor, $preview, $intent, $plan);
        return ['data' => ['preview' => $this->previewData($preview)]];
    }

    private function path(array $points, string $mode): array
    {
        try {
            $path = $this->paths->path($points, $mode);
        } catch (RoutingException $error) {
            throw new PickupException($error->machineCode, 503);
        }
        PickupPolicy::graph($mode, (string) ($path['data_version'] ?? ''));
        return $path;
    }

    private function owner(User $actor, object $plan): void
    {
        if ((int) $actor->id !== (int) $plan->user_id) {
            throw new PickupException('PLAN_OWNER_REQUIRED', 403);
        }
        PickupPolicy::host($actor, (int) $plan->car_id, $plan->vehicle_type);
    }

    private function planCurrent(object $plan): void
    {
        if ($plan->college_code !== CohortPolicy::college()) {
            throw new PickupException('PLAN_FORBIDDEN', 403);
        }
        $this->notExpired($plan->expires_at, 'PLAN_EXPIRED');
        $host = User::find($plan->user_id);
        if (! $host) {
            throw new PickupException('HOST_APPROVAL_REQUIRED', 403);
        }
        PickupPolicy::host($host, (int) $plan->car_id, $plan->vehicle_type);
        PickupPolicy::graph($plan->vehicle_type, $plan->path_data_version);
        PublishedPickupPlan::current($plan);
    }

    private function intentCurrent(User $actor, object $intent, object $plan, bool $requirePlanVersion = true): void
    {
        PickupPolicy::walkPreference((int) $intent->max_walk_meters);
        if (! in_array((int) $actor->id, [(int) $intent->rider_id, (int) $intent->driver_id], true)) {
            throw new PickupException('INTENT_FORBIDDEN', 403);
        }
        PickupPolicy::actor($actor);
        $rider = User::find($intent->rider_id);
        if (! $rider) {
            throw new PickupException('MEMBERSHIP_REQUIRED', 403);
        }
        PickupPolicy::actor($rider);
        $this->planCurrent($plan);
        $this->notExpired($intent->expires_at, 'INTENT_EXPIRED');
        if ($requirePlanVersion) {
            self::version($intent->plan_version, (int) $plan->version, 'INTENT_STALE');
        }
    }

    private function previewCurrent(User $actor, object $preview, object $intent, object $plan): void
    {
        $this->intentCurrent($actor, $intent, $plan);
        self::version($preview->plan_version, (int) $plan->version, 'PREVIEW_STALE');
        self::version($preview->intent_version, (int) $intent->version, 'PREVIEW_STALE');
        $this->notExpired($preview->expires_at, 'PREVIEW_EXPIRED');
        $this->snapshotGraphs(json_decode($preview->snapshot, true, 512, JSON_THROW_ON_ERROR), $plan, $intent);
    }

    private function snapshotGraphs(array $snapshot, object $plan, object $intent): void
    {
        $preferences = $snapshot['preferences'] ?? null;
        if (! is_array($preferences) || ! array_key_exists('max_walk_meters', $preferences) || ! is_int($preferences['max_walk_meters'])) {
            throw new PickupException('PICKUP_WALK_POLICY_MISSING', 409);
        }
        PickupPolicy::walkPreference($preferences['max_walk_meters']);
        if ($preferences['max_walk_meters'] !== (int) $intent->max_walk_meters) {
            throw new PickupException('PICKUP_WALK_PREFERENCE_MISMATCH', 409);
        }
        if (($snapshot['vehicle_data_version'] ?? null) !== $plan->path_data_version) {
            throw new PickupException('PICKUP_GRAPH_MISMATCH', 409);
        }
        $foot = $snapshot['foot_data_version'] ?? null;
        if ($foot !== null) {
            PickupPolicy::graph('foot', $foot);
        }
    }

    private function lockTrust(array $ids, int $carId, ?string $planId = null, ?string $intentId = null): void
    {
        $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC);
        DB::table('users')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        DB::table('mobility_memberships')->whereIn('user_id', $ids)->orderBy('user_id')->lockForUpdate()->get();
        DB::table('cars')->where('id', $carId)->lockForUpdate()->first();
        DB::table('mobility_host_applications')->where('car_id', $carId)->orderBy('id')->lockForUpdate()->get();
        if ($planId !== null) DB::table('mobility_pickup_plans')->where('id', $planId)->lockForUpdate()->first();
        if ($intentId !== null) DB::table('mobility_pickup_intents')->where('id', $intentId)->lockForUpdate()->first();
    }

    private function row(string $suffix, string $id): object
    {
        self::uuid($id);
        $row = DB::table('mobility_pickup_'.$suffix)->where('id', $id)->first();
        if (! $row) throw new PickupException('PICKUP_NOT_FOUND', 404);
        return $row;
    }

    private function planData(object $plan): array
    {
        return ['id' => $plan->id, 'owner_id' => (int) $plan->user_id, 'car_id' => (int) $plan->car_id,
            'vehicle_type' => $plan->vehicle_type, 'ride_mode' => $plan->ride_mode, 'points' => json_decode($plan->points, true),
            'path' => json_decode($plan->path_snapshot, true), 'version' => (int) $plan->version, 'expires_at' => $plan->expires_at,
            'source_publication_id' => $plan->source_publication_id ?? null, 'source_publication_route_hash' => $plan->source_publication_route_hash ?? null];
    }

    private function intentData(object $intent): array
    {
        return ['id' => $intent->id, 'plan_id' => $intent->plan_id, 'plan_version' => (int) $intent->plan_version,
            'rider_id' => (int) $intent->rider_id, 'driver_id' => (int) $intent->driver_id, 'origin' => json_decode($intent->origin, true),
            'max_walk_meters' => (int) $intent->max_walk_meters, 'version' => (int) $intent->version, 'expires_at' => $intent->expires_at];
    }

    private function previewData(object $preview): array
    {
        $intent = $this->row('intents', $preview->intent_id);
        return ['id' => $preview->id, 'plan_id' => $preview->plan_id, 'intent_id' => $preview->intent_id,
            'driver_id' => (int) $intent->driver_id, 'rider_id' => (int) $intent->rider_id,
            'plan_version' => (int) $preview->plan_version, 'intent_version' => (int) $preview->intent_version,
            'snapshot' => json_decode($preview->snapshot, true), 'booking_enabled' => false, 'expires_at' => $preview->expires_at];
    }

    private function expiry(int $minutes, string ...$values): CarbonImmutable
    {
        $expires = CarbonImmutable::now()->addMinutes($minutes);
        foreach ($values as $value) $expires = $expires->min(CarbonImmutable::parse($value));
        return $expires;
    }

    private function notExpired(string $value, string $code): void
    {
        if (CarbonImmutable::parse($value)->lessThanOrEqualTo(CarbonImmutable::now())) throw new PickupException($code, 410);
    }

    private static function result(string $field, array $value, bool $existing): array
    {
        return ['status' => $existing ? 200 : 201, 'data' => [$field => $value, 'existing' => $existing]];
    }

    private static function same(string $stored, string $expected): void
    {
        if (! hash_equals($stored, $expected)) throw new PickupException('IDEMPOTENCY_CONFLICT', 409);
    }

    private static function version(mixed $actual, int $expected, string $code): void
    {
        if ((int) $actual !== $expected) throw new PickupException($code, 409);
    }

    private static function uuid(mixed $value): string
    {
        if (! is_string($value) || ! Str::isUuid($value)) throw new PickupException('ID_INVALID');
        return $value;
    }

    private static function key(string $key): string
    {
        if (! preg_match('/^[\x21-\x7E]{1,100}$/D', $key)) throw new PickupException('INVALID_IDEMPOTENCY_KEY');
        return hash('sha256', $key);
    }

    private static function integer(mixed $value, int $min, int $max): int
    {
        if (! is_int($value) || $value < $min || $value > $max) throw new PickupException('INPUT_INVALID');
        return $value;
    }

    private static function point(mixed $value): array
    {
        if (! is_array($value)) throw new PickupException('POINT_INVALID');
        self::fields($value, ['lat', 'lng']);
        foreach (['lat', 'lng'] as $field) {
            if ((! is_int($value[$field]) && ! is_float($value[$field])) || ! is_finite((float) $value[$field])) throw new PickupException('POINT_INVALID');
        }
        if ($value['lat'] < 12.70 || $value['lat'] > 13.30 || $value['lng'] < 77.30 || $value['lng'] > 77.90) throw new PickupException('POINT_OUTSIDE_COHORT');
        return ['lat' => (float) $value['lat'], 'lng' => (float) $value['lng']];
    }

    private static function planInput(array $input, bool $update = false): array
    {
        self::fields($input, $update ? ['car_id', 'vehicle_type', 'ride_mode', 'points', 'expected_version'] : ['car_id', 'vehicle_type', 'ride_mode', 'points']);
        if (! in_array($input['vehicle_type'], ['car', 'bike'], true) || ! in_array($input['ride_mode'], ['scheduled', 'on_demand'], true)
            || ! is_array($input['points']) || ! array_is_list($input['points']) || count($input['points']) !== 2) throw new PickupException('INPUT_INVALID');
        return ['car_id' => self::integer($input['car_id'], 1, PHP_INT_MAX), 'vehicle_type' => $input['vehicle_type'], 'ride_mode' => $input['ride_mode'],
            'points' => array_map([self::class, 'point'], $input['points'])];
    }

    private static function fields(array $input, array $fields): void
    {
        if (array_diff(array_keys($input), $fields) || array_diff($fields, array_keys($input))) throw new PickupException('INPUT_INVALID');
    }

    private static function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
