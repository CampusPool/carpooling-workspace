<?php

use Illuminate\Support\Facades\Route;
use STS\Mobility\Journeys\PrivateEndpointController;

Route::prefix('api/mobility')->middleware(['api', 'logged'])->group(function (): void {
    Route::post('private-endpoints', [PrivateEndpointController::class, 'store']);
    Route::get('private-endpoints/{id}', [PrivateEndpointController::class, 'show'])->whereUuid('id');
});
