<?php

use Illuminate\Support\Facades\Route;
use STS\Mobility\Tracking\TrackingController;

Route::prefix('api')->middleware(['api', 'logged'])->group(function (): void {
    Route::post('mobility/trips/{trip}/tracking/session', [TrackingController::class, 'session'])->whereNumber('trip');
    Route::post('mobility/trips/{trip}/tracking/locations', [TrackingController::class, 'location'])->whereNumber('trip');
    Route::get('mobility/trips/{trip}/tracking', [TrackingController::class, 'show'])->whereNumber('trip');
    Route::post('mobility/trips/{trip}/tracking/stop', [TrackingController::class, 'stop'])->whereNumber('trip');
});
