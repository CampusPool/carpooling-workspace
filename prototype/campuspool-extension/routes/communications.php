<?php

use Illuminate\Support\Facades\Route;
use STS\Mobility\Communications\TripCommunicationController as C;

Route::prefix('api/mobility')->middleware(['api', 'logged'])->group(function (): void {
    Route::get('notifications', [C::class, 'notifications']);
    Route::get('trips/{trip}/messages', [C::class, 'show'])->whereNumber('trip');
    Route::post('trips/{trip}/messages', [C::class, 'send'])->middleware('throttle:mobility-chat-send')->whereNumber('trip');
    Route::post('trips/{trip}/messages/read', [C::class, 'read'])->whereNumber('trip');
    Route::post('trips/{trip}/blocks', [C::class, 'block'])->middleware('throttle:mobility-chat-block')->whereNumber('trip');
});
