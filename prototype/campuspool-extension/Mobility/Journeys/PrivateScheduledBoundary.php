<?php

namespace STS\Mobility\Journeys;

use Closure;
use Illuminate\Http\Request;
use STS\Http\Middleware\UserLoggin;
use STS\Mobility\Dispatch\DispatchBoundary;

/** Private scheduled routes never become mutable legacy pool offers. */
final class PrivateScheduledBoundary
{
    public function handle(Request $request, Closure $next)
    {
        if (! preg_match('~^api/trips/(\d+)(?:/(.*))?$~', $request->path(), $match) || ! PrivateScheduledAdmissionService::managed((int) $match[1])) {
            return $next($request);
        }

        return app(UserLoggin::class)->handle($request, function ($authenticated) use ($next, $match) {
            $actor = $authenticated->user() ?: auth('api')->user();
            $suffix = $match[2] ?? '';
            if ($authenticated->isMethod('POST') && $suffix === 'requests') {
                return response()->json(['error' => ['code' => 'PRIVATE_SCHEDULE_CARD_CONSENT_REQUIRED']], 409)->header('Cache-Control', 'private, no-store, max-age=0');
            }
            $admission = $authenticated->isMethod('POST') && ($suffix === 'requests' || preg_match('~^requests/\d+/(accept|cancel|reject)$~', $suffix));
            if (! $actor || (! $admission && ! DispatchBoundary::canView((int) $match[1], $actor))) {
                return response()->json(['error' => ['code' => 'PRIVATE_SCHEDULE_FORBIDDEN']], 403);
            }
            if ($admission || ($authenticated->isMethod('GET') && ! str_starts_with($suffix, 'live-share')) || ($authenticated->isMethod('POST') && preg_match('~^(rate|reply)/\d+$~', $suffix))) {
                $response = $next($authenticated);
                $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

                return $response;
            }

            return response()->json(['error' => ['code' => 'PRIVATE_SCHEDULE_TERMS_IMMUTABLE']], 409);
        });
    }
}
