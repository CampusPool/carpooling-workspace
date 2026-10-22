<?php

namespace STS\Mobility\Pickup;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Routing\RouteDataVersion;
use STS\Models\Trip;
use STS\Models\User;

/**
 * Server-derived input and current-state checks for a pickup plan bound to a
 * private scheduled publication.  This helper has no provider or write side
 * effects; the caller owns the plan transaction and migration columns.
 */
final class PublishedPickupPlan
{
    /**
     * @return array{input:array<string,mixed>,path:array<string,mixed>}
     */
    public static function prepare(User $driver, string $publicationId, string $expectedHash): array
    {
        $publicationId = self::uuid($publicationId);
        $expectedHash = self::hash($expectedHash);

        try {
            return DB::transaction(function () use ($driver, $publicationId, $expectedHash): array {
                $publication = DB::table('mobility_private_scheduled_publications')
                    ->where('publication_id', $publicationId)
                    ->lock('for share nowait')->first();
                if (! $publication || (int) $publication->driver_id !== (int) $driver->id) {
                    throw new PickupException('PRIVATE_PUBLICATION_NOT_FOUND', 404);
                }

                self::currentRows($publication);
                $source = self::source($publication);
                self::driver($driver, (int) $publication->car_id, $source['vehicle_type'], $source['path']);
                self::hashMatch((string) $publication->route_hash, $expectedHash);
                self::publicationIdentity($publication, $source['path'], $source['vehicle_type']);

                $points = self::points($publication->points, 4);
                return [
                    'input' => [
                        'car_id' => (int) $publication->car_id,
                        'vehicle_type' => $source['vehicle_type'],
                        'ride_mode' => 'scheduled',
                        'points' => [$points[0], $points[3]],
                        'source_publication_id' => (string) $publication->publication_id,
                        'source_publication_route_hash' => (string) $publication->route_hash,
                    ],
                    'path' => $source['path'],
                ];
            });
        } catch (QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 3572) {
                throw new PickupException('PRIVATE_PUBLICATION_BUSY', 409);
            }
            throw $error;
        }
    }

    /** Normal plans do not need publication policy checks. */
    public static function current(object $plan): void
    {
        $publicationId = $plan->source_publication_id ?? null;
        if ($publicationId === null || $publicationId === '') {
            return;
        }
        if (! is_string($publicationId) || ! Str::isUuid($publicationId)) {
            throw new PickupException('PRIVATE_PUBLICATION_INVALID', 409);
        }
        $expectedHash = $plan->source_publication_route_hash ?? null;
        if (! is_string($expectedHash) || preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1) {
            throw new PickupException('PRIVATE_PUBLICATION_INVALID', 409);
        }

        try {
            DB::transaction(function () use ($plan, $publicationId, $expectedHash): void {
                $publication = DB::table('mobility_private_scheduled_publications')
                    ->where('publication_id', $publicationId)
                    ->lock('for share nowait')->first();
                if (! $publication || (int) $publication->driver_id !== (int) $plan->user_id) {
                    throw new PickupException('PRIVATE_PUBLICATION_INVALID', 409);
                }
                self::currentRows($publication);
                $source = self::source($publication);
                self::driver(User::find((int) $plan->user_id), (int) $publication->car_id, $source['vehicle_type'], $source['path']);
                self::hashMatch((string) $publication->route_hash, $expectedHash);
                self::publicationIdentity($publication, $source['path'], $source['vehicle_type']);

                if ((int) $plan->car_id !== (int) $publication->car_id
                    || $plan->vehicle_type !== $source['vehicle_type']
                    || $plan->ride_mode !== 'scheduled'
                    || (string) ($plan->source_publication_route_hash ?? '') !== (string) $publication->route_hash) {
                    throw new PickupException('PRIVATE_PUBLICATION_INVALID', 409);
                }
                $planPath = self::decode($plan->path_snapshot, 'PRIVATE_PUBLICATION_INVALID');
                if (self::jsonHash($planPath) !== (string) $publication->route_hash) {
                    throw new PickupException('PRIVATE_PUBLICATION_INVALID', 409);
                }
                $planPoints = self::points($plan->points, 2);
                $publicationPoints = self::points($publication->points, 4);
                if ($planPoints !== [$publicationPoints[0], $publicationPoints[3]]) {
                    throw new PickupException('PRIVATE_PUBLICATION_INVALID', 409);
                }
            });
        } catch (QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 3572) {
                throw new PickupException('PRIVATE_PUBLICATION_BUSY', 409);
            }
            throw $error;
        }
    }

    /** @return array{path:array<string,mixed>,vehicle_type:string} */
    private static function source(object $publication): array
    {
        $path = self::decode($publication->driver_path, 'PRIVATE_PUBLICATION_INVALID');
        if (($path['provider'] ?? null) !== 'osrm' || ! is_string($path['profile'] ?? null) || $path['profile'] === '') {
            throw new PickupException('PRIVATE_PUBLICATION_INVALID', 409);
        }
        $vehicles = array_values(array_filter(['car', 'bike'], static fn (string $vehicle): bool => RouteDataVersion::valid($path['data_version'] ?? null, $vehicle)));
        if (count($vehicles) !== 1) {
            throw new PickupException('PRIVATE_PUBLICATION_INVALID', 409);
        }
        return ['path' => $path, 'vehicle_type' => $vehicles[0]];
    }

    private static function currentRows(object $publication): void
    {
        $trip = Trip::whereKey((int) $publication->trip_id)->lock('for share nowait')->first();
        $lifecycle = DB::table('mobility_trip_lifecycles')->where('trip_id', $publication->trip_id)
            ->lock('for share nowait')->first();
        $points = DB::table('trips_points')->where('trip_id', $publication->trip_id)
            ->orderBy('id')->lock('for share nowait')->get();
        if (! $trip || $trip->trashed() || $trip->state !== Trip::STATE_READY
            || ! $lifecycle || $lifecycle->state !== 'planned'
            || CarbonImmutable::parse($publication->departure_at, 'Asia/Kolkata')->lte(CarbonImmutable::now('Asia/Kolkata'))
            || (int) $trip->user_id !== (int) $publication->driver_id
            || (int) $trip->car_id !== (int) $publication->car_id
            || (int) $trip->total_seats !== (int) $publication->total_seats
            || CarbonImmutable::parse($trip->trip_date, 'Asia/Kolkata')->getTimestamp() !== CarbonImmutable::parse($publication->departure_at, 'Asia/Kolkata')->getTimestamp()) {
            throw new PickupException('PRIVATE_PUBLICATION_INVALID', 409);
        }
        $expected = self::points($publication->points, 4);
        if ($points->count() !== count($expected)) {
            throw new PickupException('PRIVATE_PUBLICATION_INVALID', 409);
        }
        foreach ($points as $index => $point) {
            if (abs((float) $point->lat - $expected[$index]['lat']) > 0.0000001
                || abs((float) $point->lng - $expected[$index]['lng']) > 0.0000001) {
                throw new PickupException('PRIVATE_PUBLICATION_INVALID', 409);
            }
        }
    }

    private static function driver(?User $driver, int $carId, string $vehicleType, array $path): void
    {
        if (! $driver || ! in_array($vehicleType, ['car', 'bike'], true)
            || ($path['profile'] ?? null) === '' || ! RouteDataVersion::valid($path['data_version'] ?? null, $vehicleType)) {
            throw new PickupException('PRIVATE_PUBLICATION_INVALID', 409);
        }
        PickupPolicy::host($driver, $carId, $vehicleType);
    }

    private static function publicationIdentity(object $publication, array $path, string $vehicleType): void
    {
        if (self::jsonHash($path) !== (string) $publication->route_hash
            || ! is_array($path['waypoints'] ?? null) || count($path['waypoints']) !== 4
            || ! is_array($path['legs'] ?? null) || count($path['legs']) !== 3
            || ! is_array($path['geometry'] ?? null) || count($path['geometry']) < 2) {
            throw new PickupException('PRIVATE_PUBLICATION_INVALID', 409);
        }
        PickupPolicy::graph($vehicleType, (string) $path['data_version']);
        $points = self::points($publication->points, 4);
        foreach ([0, 1, 2, 3] as $index => $waypointIndex) {
            $location = $path['waypoints'][$waypointIndex]['location'] ?? null;
            if (! is_array($location) || count($location) !== 2
                || abs((float) $location[1] - $points[$index]['lat']) > 0.0000001
                || abs((float) $location[0] - $points[$index]['lng']) > 0.0000001) {
                throw new PickupException('PRIVATE_PUBLICATION_INVALID', 409);
            }
        }
    }

    /** @return list<array{lat:float,lng:float}> */
    private static function points(mixed $raw, int $expectedCount): array
    {
        $points = self::decode($raw, 'PRIVATE_PUBLICATION_INVALID');
        if (count($points) !== $expectedCount || ! array_is_list($points)) {
            throw new PickupException('PRIVATE_PUBLICATION_INVALID', 409);
        }
        return array_map(static function (mixed $point): array {
            if (! is_array($point) || ! is_numeric($point['lat'] ?? null) || ! is_numeric($point['lng'] ?? null)
                || ! is_finite((float) $point['lat']) || ! is_finite((float) $point['lng'])) {
                throw new PickupException('PRIVATE_PUBLICATION_INVALID', 409);
            }
            return ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
        }, $points);
    }

    private static function decode(mixed $raw, string $code): array
    {
        try {
            $value = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : $raw;
        } catch (\Throwable) {
            throw new PickupException($code, 409);
        }
        if (! is_array($value)) {
            throw new PickupException($code, 409);
        }
        return $value;
    }

    private static function uuid(string $value): string
    {
        $value = strtolower(trim($value));
        if (! Str::isUuid($value)) {
            throw new PickupException('INPUT_INVALID', 422);
        }
        return $value;
    }

    private static function hash(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new PickupException('INPUT_INVALID', 422);
        }
        return $value;
    }

    private static function hashMatch(string $actual, string $expected): void
    {
        if (! hash_equals($actual, $expected)) {
            throw new PickupException('PRIVATE_PUBLICATION_STALE', 409);
        }
    }

    private static function jsonHash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
