<?php

namespace STS\MobilityIntegration;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use STS\Http\Middleware\UserLoggin;
use STS\Models\Trip;
use STS\Models\Passenger;

/** Serialize legacy HTTP booking/edit writes with the new lifecycle transitions. */
class LegacyLifecycleGuard
{
    public function handle(Request $request, Closure $next)
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || ! preg_match('~^api/trips/([0-9]+)(?:/(.*))?$~', $request->path(), $match)) {
            return $next($request);
        }
        $tripId = (int) $match[1];
        $suffix = $match[2] ?? '';
        // Feedback and stopping a share are valid after a trip. Starting/updating
        // sharing is serialized too, and must stop at a terminal lifecycle state.
        if (preg_match('~^(?:rate|reply)/[0-9]+$~', $suffix)
            || $suffix === 'live-share/stop') {
            return $next($request);
        }

        // This group middleware can precede controller middleware. Authenticate before
        // reading a lifecycle state, then let the existing controller enforce its rules.
        return app(UserLoggin::class)->handle($request, function ($authenticated) use ($next, $tripId, $suffix) {
            return DB::transaction(function () use ($authenticated, $next, $tripId, $suffix) {
                $trip = Trip::query()->lockForUpdate()->find($tripId);
                if (! $trip) {
                    return response()->json(['error' => 'trip_not_found'], 404);
                }
                // This legacy endpoint promotes WAITING_PAYMENT to ACCEPTED without
                // verifying provider capture or rechecking capacity. Fail closed
                // until one trusted payment/booking service owns that transition.
                // Preserve legacy validation on invalid pay attempts, but do
                // not allow an actual waiting booking to bypass trusted capture
                // and the capacity recheck enforced by normal host acceptance.
                if ($authenticated->method() === 'POST'
                    && preg_match('~^requests/([0-9]+)/pay$~', $suffix, $paymentMatch)
                    && Passenger::query()->where('trip_id', $tripId)
                        ->where('user_id', (int) $paymentMatch[1])
                        ->where('request_state', Passenger::STATE_WAITING_PAYMENT)
                        ->exists()) {
                    return response()->json([
                        'error' => ['code' => 'LEGACY_PAYMENT_PROMOTION_DISABLED'],
                    ], 409);
                }
                $state = DB::table('mobility_trip_lifecycles')->where('trip_id', $tripId)->value('state');
                $sharing = in_array($suffix, ['live-share/start', 'live-share/location'], true);
                $allowed = $sharing ? ['planned', 'in_progress'] : ['planned'];
                if (($state !== null && ! in_array($state, $allowed, true)) || $trip->state === Trip::STATE_CANCELED) {
                    return response()->json(['error' => 'trip_not_planned'], 409);
                }

                return $next($authenticated);
            });
        });
    }
}
