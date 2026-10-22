<?php

namespace STS\Mobility\Tracking;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Private tracking must never acquire a public legacy sharing URL. */
final class TrackingBoundary
{
    public function handle(Request $request, Closure $next)
    {
        $path = $request->path();
        if ((str_starts_with($path, 'api/live/') || preg_match('~^api/trips/\d+/live-share~', $path)) && ! DB::getSchemaBuilder()->hasTable('mobility_tracking_receipts')) return $next($request);
        if (preg_match('~^api/live/([^/]+)$~', $path, $match)) {
            $share = DB::table('trip_live_shares')->where('share_token', $match[1])->first();
            if ($share && DB::table('mobility_tracking_receipts')->where('trip_id', $share->trip_id)->exists()) return response()->json(['message' => 'Not found.'], 404, ['Cache-Control' => 'private, no-store']);
        }
        if (preg_match('~^api/trips/(\d+)/live-share(?:/|$)~', $path, $match)
            && DB::table('mobility_tracking_receipts')->where('trip_id', $match[1])->exists()) {
            return response()->json(['error' => ['code' => 'PRIVATE_TRACKING_API_REQUIRED']], 409, ['Cache-Control' => 'private, no-store']);
        }
        $response = $next($request);
        if (preg_match('~^api/mobility/(?:trips/\d+/tracking(?:/|$)|driver-onboarding(?:/|$)|operator/driver-onboarding(?:/|$))~', $path)) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }
        return $response;
    }
}
