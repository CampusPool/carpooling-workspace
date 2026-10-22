<?php

namespace STS\Mobility\Journeys;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Pickup\PickupException;
use STS\Mobility\Pickup\PickupPolicy;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\User;

/** Actor-private coordinate identity. Never creates a global node or address cache. */
final class PrivateEndpointResolver
{
    public function create(User $actor, array $input, string $key): array
    {
        PickupPolicy::actor($actor);
        if (array_diff(array_keys($input), ['lat', 'lng', 'source']) || array_diff(['lat', 'lng', 'source'], array_keys($input))) throw new PickupException('ENDPOINT_INPUT_INVALID');
        foreach (['lat', 'lng'] as $field) if ((! is_int($input[$field]) && ! is_float($input[$field])) || ! is_finite((float) $input[$field])) throw new PickupException('ENDPOINT_INPUT_INVALID');
        if ($input['lat'] < 12.65 || $input['lat'] > 13.35 || $input['lng'] < 77.2 || $input['lng'] > 77.95
            || ! in_array($input['source'], ['map_pin', 'device_gps', 'google_place'], true)) throw new PickupException('ENDPOINT_INPUT_INVALID');
        if (! preg_match('/^[\x21-\x7E]{1,100}$/D', $key)) throw new PickupException('INVALID_IDEMPOTENCY_KEY');
        $point = ['lat' => round((float) $input['lat'], 7), 'lng' => round((float) $input['lng'], 7)];
        $hash = hash('sha256', json_encode($point + ['source' => $input['source']], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($actor, $input, $key, $point, $hash): array {
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            DB::table('mobility_memberships')->where('user_id', $actor->id)->lockForUpdate()->first();
            PickupPolicy::actor($actor);
            $existing = DB::table('mobility_private_endpoints')->where('user_id', $actor->id)->where('key_hash', hash('sha256', $key))->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->coordinate_hash, $hash)) throw new PickupException('IDEMPOTENCY_CONFLICT', 409);
                return $this->resolve($actor, $existing->id) + ['existing' => true];
            }
            $id = (string) Str::uuid(); $now = CarbonImmutable::now('UTC');
            DB::table('mobility_private_endpoints')->insert(['id' => $id, 'user_id' => $actor->id, 'college_code' => CohortPolicy::college(),
                'lat' => $point['lat'], 'lng' => $point['lng'], 'source' => $input['source'], 'coordinate_hash' => $hash,
                'key_hash' => hash('sha256', $key), 'expires_at' => $now->addDays(30), 'created_at' => $now, 'updated_at' => $now]);
            return $this->resolve($actor, $id) + ['existing' => false];
        });
    }

    /** Internal actor binding must be supplied by the trusted quote/admission service. */
    public function resolve(User $owner, string $id, bool $lock = false): array
    {
        PickupPolicy::actor($owner);
        if (! Str::isUuid($id)) throw new PickupException('ENDPOINT_NOT_FOUND', 404);
        $query = DB::table('mobility_private_endpoints')->where('id', $id)->where('user_id', $owner->id)->where('college_code', CohortPolicy::college());
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row) throw new PickupException('ENDPOINT_NOT_FOUND', 404);
        if ($row->redacted_at || $row->lat === null || $row->lng === null || CarbonImmutable::parse($row->expires_at, 'UTC')->lte(CarbonImmutable::now('UTC'))) throw new PickupException('ENDPOINT_EXPIRED', 410);
        $point = ['lat' => (float) $row->lat, 'lng' => (float) $row->lng];
        $hash = hash('sha256', json_encode($point + ['source' => $row->source], JSON_THROW_ON_ERROR));
        if (! hash_equals($row->coordinate_hash, $hash)) throw new PickupException('ENDPOINT_INTEGRITY_FAILED', 409);
        return ['id' => $row->id, 'point' => $point, 'source' => $row->source, 'hash' => $row->coordinate_hash, 'expires_at' => CarbonImmutable::parse($row->expires_at, 'UTC')->toISOString()];
    }

    public function redactExpired(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) throw new \InvalidArgumentException('RETENTION_LIMIT_INVALID');
        return DB::transaction(function () use ($limit): int {
            $ids = DB::table('mobility_private_endpoints')->where('expires_at', '<=', CarbonImmutable::now('UTC'))->whereNull('redacted_at')->orderBy('id')->limit($limit)->lockForUpdate()->pluck('id');
            return DB::table('mobility_private_endpoints')->whereIn('id', $ids)->update(['lat' => null, 'lng' => null, 'redacted_at' => CarbonImmutable::now('UTC'), 'updated_at' => CarbonImmutable::now('UTC')]);
        });
    }
}
