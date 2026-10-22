<?php

namespace STS\Mobility\Tracking;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;

/**
 * Private, trip-scoped driver location ingestion.
 *
 * The existing trip_live_shares row remains the current-location projection.
 * The tracking receipts table is the durable idempotency/order ledger; neither
 * endpoint exposes the legacy share token or a public URL.
 */
final class TrackingService
{
    public const MAX_ACCURACY_METERS = 500;
    public const MAX_FUTURE_SECONDS = 30;
    public const STALE_AFTER_SECONDS = 30;
    public const SESSION_LEASE_SECONDS = 120;

    public function startSession(int $tripId, User $actor, string $key, bool $consent): array
    {
        $this->assertKey($key);
        if (! $consent) {
            throw new TrackingException('DRIVER_CONSENT_REQUIRED', 422);
        }
        $fingerprint = hash('sha256', json_encode(['consent' => true], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tripId, $actor, $key, $fingerprint): array {
            $trip = $this->lockedTrip($tripId);
            $this->assertDriver($trip, $actor);
            if (! CohortPolicy::required()) throw new TrackingException('PRIVATE_TRACKING_REQUIRES_COHORT', 503);
            $this->assertLifecycle($trip);
            $share = DB::table('trip_live_shares')->where('trip_id', $tripId)->where('user_id', $actor->id)->lockForUpdate()->first();
            $existing = $this->receipt($tripId, (int) $actor->id, 'session', $key);
            if ($existing) {
                $session = (string) json_decode($existing->response_body, true, 512, JSON_THROW_ON_ERROR)['session_id'];
                return $this->replay($existing, $fingerprint, $this->sessionIsActive($share, $session));
            }
            $session = (string) Str::uuid();
            $now = now();
            $fields = ['share_token' => Str::random(64), 'is_active' => true, 'lat' => null, 'lng' => null,
                'recorded_at' => null, 'sample_recorded_at' => null, 'started_at' => $now, 'stopped_at' => null,
                'driver_session_id' => $session, 'consented_at' => $now, 'last_sequence' => 0,
                'accuracy_meters' => null, 'updated_at' => $now];
            if ($share) {
                DB::table('trip_live_shares')->where('id', $share->id)->update($fields);
            } else {
                DB::table('trip_live_shares')->insert($fields + ['trip_id' => $tripId, 'user_id' => $actor->id, 'created_at' => $now]);
            }
            $body = ['trip_id' => $tripId, 'session_id' => $session, 'consented_at' => $now->toISOString(), 'active' => true];
            $this->recordReceipt($tripId, (int) $actor->id, 'session', $key, $fingerprint, $body);

            return $body;
        });
    }

    public function ingest(int $tripId, User $actor, string $session, int $sequence, float $lat, float $lng, float $accuracy, CarbonImmutable $recordedAt, string $key): array
    {
        $this->assertKey($key);
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $session)) throw new TrackingException('INVALID_SESSION', 422);
        if (! is_finite($lat) || ! is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) throw new TrackingException('INVALID_COORDINATES', 422);
        if (! is_finite($accuracy)) throw new TrackingException('INVALID_ACCURACY', 422);
        $recordedAt = $recordedAt->setMicrosecond(intdiv($recordedAt->microsecond, 1000) * 1000);
        $input = compact('session', 'sequence', 'lat', 'lng', 'accuracy') + ['recorded_at' => $recordedAt->toISOString()];
        $fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tripId, $actor, $session, $sequence, $lat, $lng, $accuracy, $recordedAt, $key, $fingerprint): array {
            $trip = $this->lockedTrip($tripId);
            $this->assertDriver($trip, $actor);
            if (! CohortPolicy::required()) throw new TrackingException('PRIVATE_TRACKING_REQUIRES_COHORT', 503);
            $this->assertLifecycle($trip);
            $share = DB::table('trip_live_shares')->where('trip_id', $tripId)->where('user_id', $actor->id)->lockForUpdate()->first();
            $existing = $this->receipt($tripId, (int) $actor->id, 'location', $key);
            if ($existing) {
                return $this->replay($existing, $fingerprint, $this->sessionIsActive($share, $session));
            }
            if ($sequence < 1 || $sequence > 2147483647) throw new TrackingException('INVALID_SEQUENCE', 422);
            if ($accuracy <= 0 || $accuracy > self::MAX_ACCURACY_METERS) throw new TrackingException('INVALID_ACCURACY', 422);
            $now = CarbonImmutable::now('UTC');
            if ($recordedAt->gt($now->addSeconds(self::MAX_FUTURE_SECONDS))) throw new TrackingException('TIMESTAMP_IN_FUTURE', 422);
            if ($recordedAt->lt($now->subHours(24))) throw new TrackingException('TIMESTAMP_TOO_OLD', 422);
            if (! $this->sessionIsActive($share, $session)) throw new TrackingException('TRACKING_SESSION_STALE', 409);
            if ($sequence <= (int) $share->last_sequence) throw new TrackingException('SEQUENCE_OUT_OF_ORDER', 409);
            if (($share->sample_recorded_at ?? $share->recorded_at) && $recordedAt->lte(CarbonImmutable::parse($share->sample_recorded_at ?? $share->recorded_at, 'UTC'))) throw new TrackingException('TIMESTAMP_OUT_OF_ORDER', 409);
            $body = ['trip_id' => $tripId, 'session_id' => $session, 'sequence' => $sequence, 'recorded_at' => $recordedAt->toISOString(), 'accepted' => true, 'active' => true];
            DB::table('trip_live_shares')->where('id', $share->id)->update([
                'lat' => $lat, 'lng' => $lng, 'accuracy_meters' => $accuracy, 'recorded_at' => $recordedAt, 'sample_recorded_at' => $recordedAt->format('Y-m-d H:i:s.v'),
                'last_sequence' => $sequence, 'updated_at' => now(),
            ]);
            $this->recordReceipt($tripId, (int) $actor->id, 'location', $key, $fingerprint, $body);

            return $body;
        });
    }

    public function show(int $tripId, User $actor): array
    {
        return DB::transaction(function () use ($tripId, $actor): array {
            $trip = $this->lockedTrip($tripId);
            $this->assertReader($trip, $actor);
            $this->assertLifecycle($trip);
            $share = DB::table('trip_live_shares')->where('trip_id', $tripId)->where('user_id', $trip->user_id)->first();
            if (! $share || ! $this->sessionIsActive($share, (string) $share->driver_session_id) || ! $share->recorded_at) return ['trip_id' => $tripId, 'location' => null, 'stale' => true];
            $recorded = CarbonImmutable::parse($share->sample_recorded_at ?? $share->recorded_at, 'UTC');
            $stale = $recorded->lt(CarbonImmutable::now('UTC')->subSeconds(self::STALE_AFTER_SECONDS));
            return ['trip_id' => $tripId, 'location' => $stale ? null : ['lat' => (float) $share->lat, 'lng' => (float) $share->lng, 'accuracy_meters' => (float) $share->accuracy_meters, 'recorded_at' => $recorded->toISOString(), 'sequence' => (int) $share->last_sequence], 'stale' => $stale];
        });
    }

    public function stop(int $tripId, User $actor, string $key, ?string $session = null): array
    {
        $this->assertKey($key);
        if ($session !== null && ! Str::isUuid($session)) {
            throw new TrackingException('INVALID_SESSION', 422);
        }
        $fingerprint = $session === null ? hash('sha256', 'stop') : hash('sha256', json_encode(['session_id' => $session], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tripId, $actor, $key, $session, $fingerprint): array {
            $trip = $this->lockedTrip($tripId);
            $this->assertStopDriver($trip, $actor);
            $existing = $this->receipt($tripId, (int) $actor->id, 'stop', $key);
            if ($existing) return $this->replay($existing, $fingerprint);
            $share = DB::table('trip_live_shares')
                ->where('trip_id', $tripId)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();
            $matchesCurrent = $session === null
                || ($share && $share->is_active && hash_equals((string) $share->driver_session_id, $session));
            if (! $matchesCurrent) {
                $body = [
                    'trip_id' => $tripId,
                    'stopped' => false,
                    'noop' => true,
                    'detail' => 'STALE_SESSION',
                ];
                $this->recordReceipt($tripId, (int) $actor->id, 'stop', $key, $fingerprint, $body);
                return $body;
            }
            if ($share) {
                DB::table('trip_live_shares')->where('id', $share->id)->update([
                    'is_active' => false,
                    'lat' => null,
                    'lng' => null,
                    'accuracy_meters' => null,
                    'recorded_at' => null,
                    'sample_recorded_at' => null,
                    'driver_session_id' => null,
                    'consented_at' => null,
                    'stopped_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $body = ['trip_id' => $tripId, 'stopped' => true];
            if ($session !== null) {
                $body['noop'] = false;
            }
            $this->recordReceipt($tripId, (int) $actor->id, 'stop', $key, $fingerprint, $body);
            return $body;
        });
    }

    private function lockedTrip(int $tripId): Trip
    {
        $trip = Trip::query()->whereKey($tripId)->lockForUpdate()->first();
        if (! $trip) throw new TrackingException('TRIP_NOT_FOUND', 404);
        return $trip;
    }

    private function assertLifecycle(Trip $trip): void
    {
        $state = DB::table('mobility_trip_lifecycles')->where('trip_id', $trip->id)->value('state');
        if ($trip->state === Trip::STATE_CANCELED || in_array($state, ['completed', 'cancelled'], true)) throw new TrackingException('TRACKING_STOPPED', 410);
        if (($state ?? 'planned') === 'planned' && ! \STS\Mobility\Dispatch\DispatchTripPolicy::managed((int) $trip->id) && $trip->expired()) throw new TrackingException('TRACKING_UNAVAILABLE', 409);
        if (! in_array($state ?? 'planned', ['planned', 'in_progress'], true)) throw new TrackingException('TRACKING_UNAVAILABLE', 409);
    }

    private function assertDriver(Trip $trip, User $actor): void
    {
        if ((int) $trip->user_id !== (int) $actor->id || ! $this->activeMember($actor->id)) throw new TrackingException('TRACKING_DRIVER_FORBIDDEN', 403);
    }

    private function assertStopDriver(Trip $trip, User $actor): void
    {
        $user = User::query()->find($actor->id);
        if ((int) $trip->user_id !== (int) $actor->id || ! $user || ! $user->active || $user->banned) throw new TrackingException('TRACKING_DRIVER_FORBIDDEN', 403);
    }

    private function assertReader(Trip $trip, User $actor): void
    {
        if (! $this->activeMember($actor->id) || ((int) $trip->user_id !== (int) $actor->id && ! Passenger::query()->where('trip_id', $trip->id)->where('user_id', $actor->id)->where('passenger_type', Passenger::TYPE_PASAJERO)->where('request_state', Passenger::STATE_ACCEPTED)->exists())) throw new TrackingException('TRACKING_FORBIDDEN', 403);
    }

    private function activeMember(int $userId): bool
    {
        return CohortPolicy::approved($userId);
    }

    private function sessionIsActive(?object $share, string $session): bool
    {
        if (! $share || ! Str::isUuid($session) || ! $share->is_active || ! hash_equals((string) $share->driver_session_id, $session)) {
            return false;
        }
        $anchor = $share->sample_recorded_at !== null
            ? CarbonImmutable::parse($share->updated_at, 'UTC')
            : CarbonImmutable::parse($share->started_at, 'UTC');
        return $anchor->gt(CarbonImmutable::now('UTC')->subSeconds($this->sessionLeaseSeconds()));
    }

    private function sessionLeaseSeconds(): int
    {
        $configured = config('mobility_tracking.session_lease_seconds', self::SESSION_LEASE_SECONDS);
        if (is_string($configured) && ctype_digit($configured)) {
            $configured = (int) $configured;
        }
        return is_int($configured) && $configured > 0 ? $configured : self::SESSION_LEASE_SECONDS;
    }

    private function receipt(int $tripId, int $actorId, string $kind, string $key): ?object
    { return DB::table('mobility_tracking_receipts')->where(['trip_id' => $tripId, 'actor_id' => $actorId, 'kind' => $kind, 'idempotency_key' => $key])->lockForUpdate()->first(); }

    private function replay(object $row, string $fingerprint, ?bool $active = null): array
    { if (! hash_equals((string) $row->request_fingerprint, $fingerprint)) throw new TrackingException('IDEMPOTENCY_CONFLICT', 409); $body = json_decode((string) $row->response_body, true, 512, JSON_THROW_ON_ERROR); if ($active !== null && array_key_exists('active', $body)) $body['active'] = $active; return $body; }

    private function assertKey(string $key): void
    { if ($key === '' || strlen($key) > 100 || ! preg_match('/^[\x21-\x7E]+$/D', $key)) throw new TrackingException('INVALID_IDEMPOTENCY_KEY', 422); }

    private function recordReceipt(int $tripId, int $actorId, string $kind, string $key, string $fingerprint, array $body): void
    { DB::table('mobility_tracking_receipts')->insert(['trip_id' => $tripId, 'actor_id' => $actorId, 'kind' => $kind, 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint, 'response_body' => json_encode($body, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]); }
}
