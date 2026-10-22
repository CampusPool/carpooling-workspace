<?php

namespace STS\Mobility\Recurrence;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use STS\Models\Trip;

/** Trip-first admission fence; lifecycle and recovery routes pass through. */
final class ProgramAdmissionGuard
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethod('POST') || ! $this->isAdmission($request)) {
            return $next($request);
        }

        return app(\STS\Http\Middleware\UserLoggin::class)->handle($request, function ($authenticated) use ($next) {
            return $this->admit($authenticated, $next);
        });
    }

    private function admit(Request $request, Closure $next)
    {
        $routeTrip = $request->route('trip') ?? $request->route('id');
        if (! $routeTrip && preg_match('~^api/trips/(\d+)(?:/|$)~', trim($request->path(), '/'), $match)) {
            $routeTrip = $match[1];
        }
        $trip = $routeTrip instanceof Trip ? $routeTrip : (is_numeric($routeTrip) ? Trip::find((int) $routeTrip) : null);
        if (! $trip) {
            return $next($request);
        }

        return DB::transaction(function () use ($request, $next, $trip) {
            $lockedTrip = Trip::query()->whereKey($trip->getKey())->lockForUpdate()->firstOrFail();
            $occ = DB::table('mobility_recurring_occurrences')->where('trip_id', $lockedTrip->id)->first();
            if (! $occ) {
                return $next($request);
            }
            $program = DB::table('mobility_recurring_programs')->where('id', $occ->program_id)->lockForUpdate()->first();
            if (! $program) {
                return $next($request);
            }
            if ((int) $occ->trip_id !== (int) $lockedTrip->id) {
                return response()->json(['error' => ['code' => 'PROGRAM_MAPPING_CHANGED']], 409);
            }
            if ($program->state === 'paused') {
                return response()->json(['error' => ['code' => 'PROGRAM_PAUSED']], 409);
            }
            if (DB::table('mobility_recurring_exceptions')->where(['program_id' => $program->id, 'service_date' => $occ->service_date, 'action' => 'skip'])->exists()) {
                return response()->json(['error' => ['code' => 'PROGRAM_DATE_SKIPPED']], 409);
            }

            return $next($request);
        });
    }

    private function isAdmission(Request $request): bool
    {
        $path = trim($request->path(), '/');

        return (bool) preg_match('~^api/trips/\d+/requests$~', $path)
            || (bool) preg_match('~^api/trips/\d+/requests/\d+/accept$~', $path);
    }
}
