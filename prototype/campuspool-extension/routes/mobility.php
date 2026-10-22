<?php

use Illuminate\Support\Facades\Route;
use STS\Mobility\CashReceiptController;
use STS\Mobility\DropoffController;
use STS\Mobility\MobilityController;
use STS\Mobility\PaymentViewController;
use STS\Mobility\Pilot\PilotOfferController;
use STS\Mobility\Recurrence\WeeklyScheduleController;
use STS\Mobility\Routing\OfferController;
use STS\Mobility\Routing\RouteQuoteController;
use STS\Mobility\Trust\CohortController;

Route::prefix('api')->middleware(['api', 'logged'])->group(function (): void {
    Route::post('mobility/pickup-plans', [\STS\Mobility\Pickup\PickupController::class, 'planStore']);
    Route::put('mobility/pickup-plans/{id}', [\STS\Mobility\Pickup\PickupController::class, 'planUpdate'])->whereUuid('id');
    Route::get('mobility/pickup-plans/{id}', [\STS\Mobility\Pickup\PickupController::class, 'planShow'])->whereUuid('id');
    Route::post('mobility/pickup-intents', [\STS\Mobility\Pickup\PickupController::class, 'intentStore']);
    Route::put('mobility/pickup-intents/{id}', [\STS\Mobility\Pickup\PickupController::class, 'intentUpdate'])->whereUuid('id');
    Route::get('mobility/pickup-intents/{id}', [\STS\Mobility\Pickup\PickupController::class, 'intentShow'])->whereUuid('id');
    Route::post('mobility/pickup-intents/{id}/previews', [\STS\Mobility\Pickup\PickupController::class, 'previewStore'])->whereUuid('id');
    Route::get('mobility/pickup-previews/{id}', [\STS\Mobility\Pickup\PickupController::class, 'previewShow'])->whereUuid('id');
    Route::post('mobility/pickup-previews/{id}/selections', [\STS\Mobility\Pickup\PickupSelectionController::class, 'store'])->whereUuid('id');
    Route::get('mobility/pickup-selections/{id}', [\STS\Mobility\Pickup\PickupSelectionController::class, 'show'])->whereUuid('id');
    Route::post('mobility/pickup-selections/{id}/confirm', [\STS\Mobility\Pickup\PickupSelectionController::class, 'confirm'])->whereUuid('id');
    Route::post('mobility/pickup-selections/{id}/decline', [\STS\Mobility\Pickup\PickupSelectionController::class, 'decline'])->whereUuid('id');
    Route::post('mobility/pickup-selections/{id}/revoke', [\STS\Mobility\Pickup\PickupSelectionController::class, 'revoke'])->whereUuid('id');
    Route::post('mobility/trips/{trip}/reports', [\STS\Mobility\Support\TripReportController::class, 'store'])
        ->whereNumber('trip')->middleware(\STS\Mobility\Support\TripReportThrottle::class.':mobility-trip-report-create');
    Route::put('mobility/dispatch/availability', [\STS\Mobility\Dispatch\DispatchController::class, 'availability']);
    Route::get('mobility/dispatch/availability', [\STS\Mobility\Dispatch\DispatchController::class, 'availabilityShow']);
    Route::get('mobility/dispatch/requests', [\STS\Mobility\Dispatch\DispatchController::class, 'index']);
    Route::post('mobility/dispatch/requests', [\STS\Mobility\Dispatch\DispatchController::class, 'create']);
    Route::get('mobility/dispatch/requests/{id}', [\STS\Mobility\Dispatch\DispatchController::class, 'show'])->whereUuid('id');
    Route::get('mobility/dispatch/offers', [\STS\Mobility\Dispatch\DispatchController::class, 'offers']);
    Route::post('mobility/dispatch/offers/{id}/accept', [\STS\Mobility\Dispatch\DispatchController::class, 'accept'])->whereUuid('id');
    Route::post('mobility/dispatch/offers/{id}/decline', [\STS\Mobility\Dispatch\DispatchController::class, 'decline'])->whereUuid('id');
    Route::post('mobility/dispatch/requests/{id}/cancel', [\STS\Mobility\Dispatch\DispatchController::class, 'cancel'])->whereUuid('id');
    Route::post('mobility/pool-matches', [\STS\Mobility\Segments\MatchController::class, 'search']);
    Route::post('mobility/pool-matches/{match}/requests', [\STS\Mobility\Segments\MatchController::class, 'request'])->whereUuid('match');
    Route::get('mobility/pickup-points', [RouteQuoteController::class, 'catalog']);
    Route::post('mobility/routes/quote', [RouteQuoteController::class, 'quote']);
    Route::get('mobility/pool-offers', [\STS\Mobility\Routing\OfferDiscoveryController::class, 'index']);
    Route::post('mobility/pool-offers', [OfferController::class, 'publish']);
    Route::get('mobility/recurring-programs', [\STS\Mobility\Recurrence\ProgramController::class, 'index']);
    Route::post('mobility/recurring-programs', [\STS\Mobility\Recurrence\ProgramController::class, 'store']);
    Route::get('mobility/recurring-programs/{program}', [\STS\Mobility\Recurrence\ProgramController::class, 'show'])->whereUuid('program');
    Route::post('mobility/recurring-programs/{program}/generate', [\STS\Mobility\Recurrence\ProgramController::class, 'generate'])->whereUuid('program');
    Route::post('mobility/recurring-programs/{program}/control', [\STS\Mobility\Recurrence\ProgramControlController::class, 'control'])->whereUuid('program');
    Route::post('mobility/recurring-programs/{program}/edit', [\STS\Mobility\Recurrence\ProgramControlController::class, 'edit'])->whereUuid('program');
    Route::post('mobility/recurring-programs/{program}/exceptions', [\STS\Mobility\Recurrence\ProgramControlController::class, 'exception'])->whereUuid('program');
    // Always authenticated and additionally testing-environment gated in controller.
    Route::get('mobility/pilot/routes', [PilotOfferController::class, 'routes']);
    Route::post('mobility/pilot/offers', [PilotOfferController::class, 'publish']);
    Route::get('mobility/membership/me', [CohortController::class, 'me']);
    Route::post('mobility/membership/apply', [CohortController::class, 'apply']);
    Route::get('mobility/operator/memberships', [CohortController::class, 'memberships']);
    Route::post('mobility/operator/memberships/{user}/decision', [CohortController::class, 'decideMembership'])->whereNumber('user');
    Route::post('mobility/host-applications', [CohortController::class, 'applyHost']);
    Route::get('mobility/operator/host-applications', [CohortController::class, 'hostApplications']);
    Route::post('mobility/operator/host-applications/{id}/decision', [CohortController::class, 'decideHost'])->whereNumber('id');
    Route::get('mobility/trips/{trip}/schedule-preview', [WeeklyScheduleController::class, 'preview'])
        ->whereNumber('trip');
    Route::get('mobility/trips/{trip}/lifecycle', [MobilityController::class, 'show'])
        ->whereNumber('trip');
    Route::post('mobility/trips/{trip}/transitions', [MobilityController::class, 'transition'])
        ->whereNumber('trip');
    Route::post('mobility/trips/{trip}/dropoff-confirmation', [DropoffController::class, 'confirm'])
        ->whereNumber('trip');
    Route::get('mobility/trips/{trip}/payments', [PaymentViewController::class, 'show'])
        ->whereNumber('trip');
    Route::post('mobility/trips/{trip}/bookings/{booking}/cash-receipts', [CashReceiptController::class, 'store'])
        ->whereNumber('trip')->whereNumber('booking');
    Route::get('mobility/operator/trips/{trip}/payments', [PaymentViewController::class, 'operatorShow'])
        ->whereNumber('trip');
    Route::get('mobility/operator/cash-disputes', [\STS\Mobility\OperatorCashQueueController::class, 'index']);
    Route::get('mobility/operator/trips/{trip}', [MobilityController::class, 'operatorShow'])
        ->whereNumber('trip');
});

// The demo credential is public to this local map page; server credentials
// must never be placed in this file. No provider content is persisted here.
Route::middleware(['web'])->get('mobility-pilot/maps-config', function () {
    if (app()->environment() !== 'testing' ||
        ! filter_var(getenv('MOBILITY_PILOT_UI_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
        abort(404);
    }
    $path = '/tmp/mobility-google-maps-demo.json';
    $data = is_file($path) && filesize($path) <= 4096
        ? json_decode((string) file_get_contents($path), true) : null;
    if (! is_array($data) || ($data['kind'] ?? null) !== 'google_maps_demo' ||
        ! is_string($data['browser_key'] ?? null) ||
        ! preg_match('/^AIza[0-9A-Za-z_-]{30,60}$/D', $data['browser_key'])) {
        return response()->json(['error' => 'MAPS_NOT_CONFIGURED'], 503,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
    return response()->json([
        'browser_key' => $data['browser_key'],
        'map_id' => 'DEMO_MAP_ID',
        'kind' => 'google_maps_demo',
    ], 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
});

// Evaluation-only same-origin client. Disabled unless explicitly enabled locally.
// It has no independent booking store and never authorizes a payment.
Route::middleware(['web'])->get('mobility-pilot/{asset?}', function (?string $asset = null) {
    if (! filter_var(getenv('MOBILITY_PILOT_UI_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
        abort(404);
    }
    $files = [
        null => ['index.html', 'text/html; charset=utf-8'],
        'app.css' => ['app.css', 'text/css; charset=utf-8'],
        'app.js' => ['app.js', 'text/javascript; charset=utf-8'],
        'maps' => ['maps.html', 'text/html; charset=utf-8'],
        'maps.css' => ['maps.css', 'text/css; charset=utf-8'],
        'maps.js' => ['maps.js', 'text/javascript; charset=utf-8'],
        'maps-state.mjs' => ['maps-state.mjs', 'text/javascript; charset=utf-8'],
        'private-journey-ui.mjs' => ['private-journey-ui.mjs', 'text/javascript; charset=utf-8'],
        'trip-chat-ui.mjs' => ['trip-chat-ui.mjs', 'text/javascript; charset=utf-8'],
    ];
    if (! array_key_exists($asset, $files)) {
        abort(404);
    }
    [$filename, $type] = $files[$asset];
    $mapsAsset = str_starts_with((string) $asset, 'maps');
    if ($mapsAsset && app()->environment() !== 'testing') {
        abort(404);
    }
    $root = rtrim((string) (getenv('MOBILITY_EXTENSION_ROOT') ?: dirname(__DIR__)), '/');
    $response = response()->file($root.'/web/'.$filename, [
        'Content-Type' => $type,
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "default-src 'none'; connect-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
    ]);
    $response->setPrivate();
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    if ($asset === null) {
        $response->headers->set('Content-Security-Policy', "default-src 'none'; connect-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; frame-src 'self' blob:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }
    if ($mapsAsset) {
        // Google access is isolated from the authenticated booking pilot.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(self)');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; script-src 'self' https://maps.googleapis.com https://maps.gstatic.com 'unsafe-eval' blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; connect-src 'self' https://*.googleapis.com https://*.google.com https://*.gstatic.com data: blob:; img-src 'self' data: blob: https://*.googleapis.com https://*.gstatic.com https://*.google.com https://*.googleusercontent.com; font-src https://fonts.gstatic.com; frame-src https://*.google.com; worker-src blob:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }

    if ($asset === 'maps' && (in_array(request()->query('picker'), ['origin', 'destination'], true) || request()->query('view') === 'tracking')) {
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Content-Security-Policy', str_replace("frame-ancestors 'none'", "frame-ancestors 'self'", $response->headers->get('Content-Security-Policy')));
    }
    return $response;
})->where('asset', '(?:app\.css|app\.js|maps|maps\.css|maps\.js|maps-state\.mjs|private-journey-ui[.]mjs|trip-chat-ui[.]mjs)');
