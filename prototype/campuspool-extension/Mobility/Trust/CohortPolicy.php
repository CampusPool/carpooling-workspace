<?php

namespace STS\Mobility\Trust;

use Illuminate\Support\Facades\DB;
use STS\Models\Car;
use STS\Models\User;

final class CohortPolicy
{
    public static function required(): bool
    {
        $value = getenv('MOBILITY_COHORT_REQUIRED');

        return $value === false ? app()->environment() !== 'testing' : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function college(): ?string
    {
        $value = getenv('MOBILITY_COLLEGE_CODE');

        return $value === false || trim($value) === '' ? null : trim($value);
    }

    public static function membership(int $userId): ?object
    {
        return DB::table('mobility_memberships')->where('user_id', $userId)->first();
    }

    public static function approved(int $userId): bool
    {
        $membership = self::membership($userId);

        return $membership && $membership->status === 'approved' && $membership->college_code === self::college()
            && self::activeUser(User::find($userId));
    }

    public static function activeUser(?object $user): bool
    {
        return $user && (bool) $user->active && ! (bool) $user->banned;
    }

    public static function hostApproved(int $userId, ?int $carId = null, ?string $vehicleType = null): bool
    {
        $query = DB::table('mobility_host_applications')->where('user_id', $userId)->where('status', 'approved');
        if ($carId !== null) {
            $query->where('car_id', $carId);
        }
        if ($vehicleType !== null) {
            $query->where('vehicle_type', $vehicleType);
        }
        foreach ($query->get() as $application) {
            $car = Car::withTrashed()->find($application->car_id);
            if ($car && ! $car->trashed() && (int) $car->user_id === $userId && $car->isComplete() && self::vehicleFingerprint($car) === $application->vehicle_fingerprint) {
                if (! DriverOnboardingPolicy::approvedApplication($application)
                    || ! DriverOnboardingPolicy::publishable($userId, (int) $application->car_id, $application->vehicle_type)) {
                    continue;
                }
                return true;
            }
        }

        return false;
    }

    public static function vehicleFingerprint(Car $car): string
    {
        return hash('sha256', json_encode([$car->id, $car->user_id, $car->patente, $car->description, $car->car_brand_id, $car->car_model_id, $car->brand_other, $car->model_other, $car->car_color_id, $car->year], JSON_THROW_ON_ERROR));
    }

    public static function scopeOffers($query): void
    {
        \STS\Mobility\Recurrence\ProgramVisibility::scope($query);
        $cars = Car::query()->join('mobility_host_applications as approval', 'approval.car_id', '=', 'cars.id')
            ->join('mobility_memberships as member', 'member.user_id', '=', 'cars.user_id')
            ->join('users as owner', 'owner.id', '=', 'cars.user_id')
            ->whereColumn('approval.user_id', 'cars.user_id')->where('approval.status', 'approved')
            ->where('member.status', 'approved')->where('member.college_code', self::college())
            ->where('owner.active', true)->where('owner.banned', false)
            ->select('cars.*', 'approval.vehicle_fingerprint as approved_fingerprint')->get();
        $ids = $cars->filter(function ($car): bool {
            $approval = DB::table('mobility_host_applications')->where('car_id', $car->id)->where('user_id', $car->user_id)->where('status', 'approved')->first();
            return $car->isComplete() && $approval && DriverOnboardingPolicy::approvedApplication($approval) && self::vehicleFingerprint($car) === $car->approved_fingerprint;
        })
            ->pluck('id')->all();
        $query->whereIn('trips.car_id', $ids)->whereExists(function ($owners): void {
            $owners->selectRaw('1')->from('cars')->whereColumn('cars.id', 'trips.car_id')
                ->whereColumn('cars.user_id', 'trips.user_id');
        });
    }
}
