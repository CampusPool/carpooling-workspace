<?php

namespace STS\Mobility\Pickup;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Routing\RouteDataVersion;
use STS\Mobility\Trust\CohortPolicy;
use STS\Mobility\Trust\DriverOnboardingPolicy;
use STS\Models\Car;
use STS\Models\User;

final class PickupPolicy
{
    /** Walking preferences are strictly below one kilometre, including access gaps. */
    public const MAX_WALK_METERS = 999;

    public static function walkPreference(mixed $meters, int $status = 409): int
    {
        if (! is_int($meters) || $meters < 0 || $meters > self::MAX_WALK_METERS) {
            throw new PickupException('PICKUP_WALK_LIMIT_EXCEEDED', $status);
        }
        return $meters;
    }

    public static function actor(User $actor): void
    {
        if (! CohortPolicy::college()) {
            throw new PickupException('COHORT_NOT_CONFIGURED', 503);
        }
        $fresh = User::find($actor->id);
        if (! CohortPolicy::activeUser($fresh)) {
            throw new PickupException('ACCOUNT_RESTRICTED', 403);
        }
        if (! CohortPolicy::approved((int) $actor->id)) {
            throw new PickupException('MEMBERSHIP_REQUIRED', 403);
        }
    }

    public static function host(User $actor, int $carId, string $mode): object
    {
        self::actor($actor);
        $car = Car::withTrashed()->find($carId);
        $approval = DB::table('mobility_host_applications')->where('user_id', $actor->id)->where('car_id', $carId)->where('status', 'approved')->first();
        if (! in_array($mode, ['car', 'bike'], true) || ! $car || $car->trashed() || (int) $car->user_id !== (int) $actor->id || ! $car->isComplete()
            || ! $approval || $approval->vehicle_type !== $mode || ! hash_equals((string) $approval->vehicle_fingerprint, CohortPolicy::vehicleFingerprint($car))) {
            throw new PickupException('HOST_APPROVAL_REQUIRED', 403);
        }
        if (! DriverOnboardingPolicy::approvedApplication($approval)) throw new PickupException('HOST_APPROVAL_REQUIRED', 403);
        return $car;
    }

    public static function currentHost(User $actor, int $carId, string $mode): object
    {
        return self::host($actor, $carId, $mode);
    }

    public static function graph(string $mode, string $stored): void
    {
        $valid = $mode === 'foot' ? preg_match('/^foot\|crop_sha256=[a-f0-9]{64}\|profile_sha256=[a-f0-9]{64}$/D', $stored) === 1 : RouteDataVersion::valid($stored, $mode);
        $expected = config('mobility_routing.'.$mode.'_expected_data_version');
        if (! $valid || ! is_string($expected) || ! hash_equals($expected, $stored)) {
            throw new PickupException('PICKUP_GRAPH_MISMATCH', 409);
        }
    }
}
