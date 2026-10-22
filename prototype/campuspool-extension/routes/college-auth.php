<?php

use Illuminate\Support\Facades\Route;
use STS\Mobility\Identity\CollegeEmailAuthController;

Route::prefix('api/mobility/college-auth')->middleware('api')->group(function (): void {
    Route::get('config', [CollegeEmailAuthController::class, 'config']);
    Route::post('request', [CollegeEmailAuthController::class, 'requestCode']);
    Route::post('verify', [CollegeEmailAuthController::class, 'verify']);
});
