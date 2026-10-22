<?php

namespace STS\Mobility\Communications;

use Closure;
use Illuminate\Http\Request;
use STS\Mobility\Trust\CohortPolicy;

final class CommunicationBoundary
{
    public function handle(Request $request, Closure $next)
    {
        // The legacy endpoints can create arbitrary two-person conversations,
        // change participants and emit provider push. Cohort chat uses the
        // participant-scoped canonical API, including for historical reads.
        if (CohortPolicy::required() && preg_match('~^api/conversations(?:/|$)~', $request->path())) {
            return response()->json(['error' => ['code' => 'CANONICAL_CHAT_REQUIRED']], 409)->header('Cache-Control', 'private, no-store, max-age=0');
        }
        $response = $next($request);
        if (preg_match('~^api/mobility/(?:notifications$|trips/\d+/(?:messages|blocks)(?:/|$))~', $request->path())) $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        return $response;
    }
}
