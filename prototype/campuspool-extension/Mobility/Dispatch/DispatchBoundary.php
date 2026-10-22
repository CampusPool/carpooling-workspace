<?php

namespace STS\Mobility\Dispatch;

use Closure;
use Illuminate\Http\Request;
use STS\Http\Middleware\UserLoggin;

/** Dispatch trips are private canonical execution, never a second pool offer. */
final class DispatchBoundary
{
    public static function denyLegacy(int $tripId): void
    {
        if (DispatchTripPolicy::managed($tripId)) {
            throw new DispatchException('DISPATCH_CANONICAL_ACTION_REQUIRED');
        }
    }

    public static function scopePool($query): void
    {
        $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('mobility_commute_demo_trips')->whereColumn('mobility_commute_demo_trips.trip_id', 'trips.id'));
        $query->whereNotExists(function ($q): void {
            $q->selectRaw('1')->from('mobility_private_scheduled_publications')->whereColumn('mobility_private_scheduled_publications.trip_id', 'trips.id');
        });
        $query->whereNotExists(function ($q): void {
            $q->selectRaw('1')->from('mobility_dispatch_assignments')->whereColumn('mobility_dispatch_assignments.trip_id', 'trips.id');
        });
    }

    public static function canView(int $tripId, object $user): bool
    {
        if (\Illuminate\Support\Facades\DB::table('mobility_commute_demo_trips')->where('trip_id', $tripId)->exists()) {
            return (bool) $user->is_admin || \Illuminate\Support\Facades\DB::table('trips')->where('id', $tripId)->where('user_id', $user->id)->exists()
                || \STS\Models\Passenger::where('trip_id', $tripId)->where('user_id', $user->id)->where('request_state', \STS\Models\Passenger::STATE_ACCEPTED)->exists();
        }
        if (\STS\Mobility\Journeys\PrivateScheduledAdmissionService::managed($tripId)) {
            return (bool) $user->is_admin || \Illuminate\Support\Facades\DB::table('trips')->where('id', $tripId)->where('user_id', $user->id)->exists()
                || \STS\Models\Passenger::where('trip_id', $tripId)->where('user_id', $user->id)->where('passenger_type', \STS\Models\Passenger::TYPE_PASAJERO)->where('request_state', \STS\Models\Passenger::STATE_ACCEPTED)->exists();
        }
        $assignment = DispatchTripPolicy::assignment($tripId);

        return ! $assignment || (bool) $user->is_admin || in_array((int) $user->id, [(int) $assignment->driver_id, (int) $assignment->rider_id], true);
    }

    public function handle(Request $request, Closure $next)
    {
        if (preg_match('~^api/trips/(\d+)(?:/(.*))?$~', $request->path(), $demo) && \Illuminate\Support\Facades\DB::table('mobility_commute_demo_trips')->where('trip_id', (int) $demo[1])->exists()) {
            return response()->json(['error'=>['code'=>'COMMUTE_DEMO_ACTION_REQUIRED']],403);
        }
        if (! preg_match('~^api/trips/(\d+)(?:/(.*))?$~', $request->path(), $match) || ! DispatchTripPolicy::managed((int) $match[1])) {
            return $next($request);
        }

        return app(UserLoggin::class)->handle($request, function ($authenticated) use ($next, $match) {
            $actor = $authenticated->user() ?: auth('api')->user();
            if (! $actor || ! self::canView((int) $match[1], $actor)) {
                return response()->json(['error' => ['code' => 'DISPATCH_TRIP_FORBIDDEN']], 403);
            }
            $suffix = $match[2] ?? '';
            if ($authenticated->isMethod('GET') || ($authenticated->isMethod('POST') && (preg_match('~^(?:rate|reply)/\d+$~', $suffix) || $suffix === 'live-share/stop'))) {
                $response = $next($authenticated);
                $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
                return $response;
            }

            return response()->json(['error' => ['code' => 'DISPATCH_CANONICAL_ACTION_REQUIRED']], 409);
        });
    }
}
