<?php

use Illuminate\Support\Facades\Route;
use STS\Mobility\Journeys\PrivateScheduledAdmissionController as C;

Route::prefix('api/mobility')->middleware(['api', 'logged'])->group(function (): void {
    Route::get('private-scheduled-publications', [C::class, 'discover']);
    Route::get('private-scheduled-bookings', [C::class, 'bookings']);
    Route::post('trips/{trip}/private-scheduled-bookings/{rider}/{action}', [C::class, 'action'])->whereNumber('trip')->whereNumber('rider')->whereIn('action', ['accept', 'reject', 'cancel']);
    Route::post('private-scheduled-publications', [C::class, 'publish']);
    Route::post('trips/{trip}/private-scheduled-request', [C::class, 'request'])->whereNumber('trip');
});
