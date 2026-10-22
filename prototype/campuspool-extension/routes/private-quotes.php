<?php

use Illuminate\Support\Facades\Route;
use STS\Mobility\Journeys\PrivateJourneyQuoteController;

Route::prefix('api/mobility/private-quotes')->middleware(['api', 'logged'])->group(function (): void {
    Route::post('/', [PrivateJourneyQuoteController::class, 'prepare']);
    Route::get('{id}', [PrivateJourneyQuoteController::class, 'show'])->whereUuid('id');
    Route::post('{id}/driver-approve', [PrivateJourneyQuoteController::class, 'action'])->defaults('kind', 'driver-approve')->whereUuid('id');
    Route::post('{id}/rider-consent', [PrivateJourneyQuoteController::class, 'action'])->defaults('kind', 'rider-consent')->whereUuid('id');
    Route::post('{id}/decline', [PrivateJourneyQuoteController::class, 'action'])->defaults('kind', 'decline')->whereUuid('id');
    Route::post('{id}/revoke', [PrivateJourneyQuoteController::class, 'action'])->defaults('kind', 'revoke')->whereUuid('id');
});
