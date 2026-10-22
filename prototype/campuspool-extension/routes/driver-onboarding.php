<?php

use Illuminate\Support\Facades\Route;
use STS\Mobility\Trust\DriverOnboardingController;

Route::prefix('api')->middleware(['api', 'logged'])->group(function (): void {
    Route::post('mobility/driver-onboarding', [DriverOnboardingController::class, 'create']);
    Route::get('mobility/driver-onboarding/me', [DriverOnboardingController::class, 'mine']);
    Route::put('mobility/driver-onboarding/{id}/profile', [DriverOnboardingController::class, 'profile'])->whereNumber('id');
    Route::post('mobility/driver-onboarding/{id}/documents', [DriverOnboardingController::class, 'upload'])->whereNumber('id');
    Route::post('mobility/driver-onboarding/{id}/submit', [DriverOnboardingController::class, 'submit'])->whereNumber('id');
    Route::get('mobility/driver-onboarding/{id}/documents/{document}', [DriverOnboardingController::class, 'document'])->whereNumber('id')->whereNumber('document');
    Route::get('mobility/operator/driver-onboarding', [DriverOnboardingController::class, 'queue']);
    Route::post('mobility/operator/driver-onboarding/{id}/review', [DriverOnboardingController::class, 'review'])->whereNumber('id');
});
