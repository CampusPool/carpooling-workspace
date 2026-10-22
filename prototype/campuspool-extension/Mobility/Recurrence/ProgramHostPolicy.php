<?php

namespace STS\Mobility\Recurrence;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Trust\CohortPolicy;
use STS\Mobility\Trust\DriverOnboardingPolicy;
use STS\Models\Car;
use STS\Models\User;

final class ProgramHostPolicy
{
    public static function lock(User $actor, int $carId, ?string $mode = null): User
    {
        $owner = User::query()->whereKey($actor->id)->lockForUpdate()->first();
        if (! CohortPolicy::activeUser($owner)) {
            throw new ProgramException('ACCOUNT_RESTRICTED', 403);
        }
        $college = CohortPolicy::college();
        if (! $college) {
            throw new ProgramException('COHORT_NOT_CONFIGURED', 503);
        }
        if (config('app.timezone') !== 'Asia/Kolkata') {
            throw new ProgramException('MOBILITY_TIMEZONE_NOT_CONFIGURED', 503);
        }
        $member = DB::table('mobility_memberships')->where('user_id', $owner->id)->lockForUpdate()->first();
        if (! $member || $member->status !== 'approved' || $member->college_code !== $college) {
            throw new ProgramException('MEMBERSHIP_REQUIRED', 403);
        }
        $car = Car::query()->whereKey($carId)->where('user_id', $owner->id)->lockForUpdate()->first();
        $approval = DB::table('mobility_host_applications')->where('user_id', $owner->id)->where('car_id', $carId)->lockForUpdate()->first();
        if (! $car || ! $approval || ! DriverOnboardingPolicy::approvedApplication($approval) || ! $car->isComplete() || ($mode !== null && $mode !== $approval->vehicle_type) || CohortPolicy::vehicleFingerprint($car) !== $approval->vehicle_fingerprint) {
            throw new ProgramException('HOST_APPROVAL_REQUIRED', 403);
        }

        return $owner;
    }
}
