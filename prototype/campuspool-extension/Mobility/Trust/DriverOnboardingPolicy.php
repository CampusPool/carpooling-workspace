<?php

namespace STS\Mobility\Trust;

use Illuminate\Support\Facades\DB;
use STS\Models\Car;

final class DriverOnboardingPolicy
{
    public const TYPES = [
        'driver_license', 'profile_photo', 'rc', 'insurance', 'permit',
        'fitness', 'puc', 'identity_or_address', 'ownership_noc',
    ];

    private const EXPIRING_TYPES = ['driver_license', 'rc', 'insurance', 'permit', 'fitness', 'puc'];

    public static function requiredTypes(): array
    {
        $configured = getenv('MOBILITY_DRIVER_REQUIRED_DOCUMENTS');
        if ($configured !== false && trim($configured) !== '') {
            $types = array_values(array_unique(array_filter(array_map('trim', explode(',', $configured)), fn ($type) => in_array($type, self::TYPES, true))));
            if ($types !== []) return $types;
        }
        return ['driver_license', 'profile_photo', 'rc', 'insurance'];
    }

    public static function documentsRequired(): bool
    {
        $value = getenv('MOBILITY_DRIVER_DOCUMENTS_REQUIRED');
        return $value === false ? app()->environment() !== 'testing' : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function approvedApplication(?object $approval): bool
    {
        if (! $approval || $approval->status !== 'approved') return false;
        if (! DB::getSchemaBuilder()->hasTable('mobility_driver_onboardings')) return ! self::documentsRequired();
        $onboarding = DB::table('mobility_driver_onboardings')->where('host_application_id', $approval->id)->first();
        if (! $onboarding) return ! self::documentsRequired();
        $failure = null;
        return $onboarding->status === 'approved' && self::checklistComplete((int) $onboarding->id, $failure);
    }

    /** Actual manual approval is required here, including in testing. No legacy identity flags are written. */
    public static function canonicalProfileComplete(int $userId, int $carId): bool
    {
        $user = DB::table('users')->where('id', $userId)->where('active', true)->where('banned', false)->first();
        if (! $user || $carId < 1 || ! CohortPolicy::approved($userId)) return false;

        $car = Car::withTrashed()->where('id', $carId)->where('user_id', $userId)->first();
        if (! $car || $car->trashed() || ! $car->isComplete()) return false;

        $application = DB::table('mobility_host_applications')->where('user_id', $userId)->where('car_id', $carId)->where('status', 'approved')->latest('id')->first();
        if (! $application || ! hash_equals((string) $application->vehicle_fingerprint, CohortPolicy::vehicleFingerprint($car))) return false;

        $onboarding = DB::table('mobility_driver_onboardings')->where('user_id', $userId)->where('car_id', $carId)->where('host_application_id', $application->id)->where('status', 'approved')->latest('id')->first();
        if (! $onboarding || trim((string) $onboarding->profile_display_name) === '') return false;

        $failure = null;
        if (! hash_equals((string) $onboarding->vehicle_fingerprint, CohortPolicy::vehicleFingerprint($car)) || ! self::checklistComplete((int) $onboarding->id, $failure)) return false;
        foreach (self::requiredTypes() as $type) {
            if ((self::currentDocuments((int) $onboarding->id)[$type]->status ?? null) !== 'accepted') return false;
        }
        return true;
    }

    public static function expiring(string $type): bool
    {
        return in_array($type, self::EXPIRING_TYPES, true);
    }

    public static function currentDocuments(int $onboardingId): array
    {
        return DB::table('mobility_driver_documents')
            ->where('onboarding_id', $onboardingId)
            ->orderByDesc('version')->orderByDesc('id')->get()
            ->unique('document_type')->keyBy('document_type')->all();
    }

    public static function checklistComplete(int $onboardingId, ?string &$failure = null): bool
    {
        $today = now()->toDateString();
        $documents = self::currentDocuments($onboardingId);
        foreach (self::requiredTypes() as $type) {
            $document = $documents[$type] ?? null;
            if (! $document || $document->status !== 'submitted' && $document->status !== 'accepted') {
                $failure = 'DOCUMENTS_INCOMPLETE';
                return false;
            }
            if (self::expiring($type) && (! $document->expires_at || $document->expires_at < $today)) {
                $failure = 'DOCUMENT_EXPIRED';
                return false;
            }
        }
        return true;
    }

    public static function publishable(int $userId, int $carId, ?string $vehicleType = null): bool
    {
        $query = DB::table('mobility_driver_onboardings')->where('user_id', $userId)->where('car_id', $carId)->where('status', 'approved');
        if ($vehicleType !== null) {
            $query->where('vehicle_type', $vehicleType);
        }
        foreach ($query->get() as $onboarding) {
            $car = Car::withTrashed()->find($carId);
            $failure = null;
            if ($car && ! $car->trashed() && (int) $car->user_id === $userId
                && $car->isComplete() && CohortPolicy::vehicleFingerprint($car) === $onboarding->vehicle_fingerprint
                && self::checklistComplete((int) $onboarding->id, $failure)) {
                return true;
            }
        }
        return ! self::documentsRequired() && ! DB::table('mobility_driver_onboardings')->where('user_id', $userId)->where('car_id', $carId)->exists();
    }
}
