<?php

namespace STS\Mobility\Routing;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Keep published quote terms immutable through the parallel legacy API. */
final class QuotedOfferGuard
{
    public function handle(Request $request, Closure $next)
    {
        if (preg_match('~^api/trips/(\d+)(?:/(.*))?$~', $request->path(), $match)
            && DB::table('mobility_pool_publications')->where('trip_id', (int) $match[1])->exists()) {
            $suffix = $match[2] ?? '';
            if (($suffix === '' && in_array($request->method(), ['PUT', 'PATCH'], true) && array_diff(array_keys($request->all()), ['description']) !== [])
                || ($suffix === 'changeSeats' && $request->isMethod('POST'))) {
                return response()->json(['error' => ['code' => 'QUOTED_OFFER_TERMS_IMMUTABLE']], 409);
            }
        }

        return $next($request);
    }
}
