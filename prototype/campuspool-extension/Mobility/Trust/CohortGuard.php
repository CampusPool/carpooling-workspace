<?php

namespace STS\Mobility\Trust;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\Trip;

/** HTTP cohort boundary; canonical services still own booking/lifecycle rules. */
final class CohortGuard
{
    public function handle(Request $request, Closure $next)
    {
        $path = $request->path();
        $s1 = (bool) preg_match('~^api/mobility/(membership|host-applications|operator/memberships|operator/host-applications)(?:/|$)~', $path);
        if (! CohortPolicy::required() && ! $s1) {
            return $next($request);
        }
        $trips = (bool) preg_match('~^api/trips(?:/|$)~', $path);
        $pilot = (bool) preg_match('~^api/mobility/pilot/(routes|offers)$~', $path);
        $history = (bool) preg_match('~^api/users/(get-trips|get-old-trips|my-trips|my-old-trips|ongoing-trip|requests|seat-requests|payment-pending|sellado-viaje)$~', $path);
        $publicLive = (bool) preg_match('~^api/live/[^/]+$~', $path);
        if (! $s1 && ! $trips && ! $pilot && ! $history && ! $publicLive) {
            return $next($request);
        }
        if (! CohortPolicy::college()) {
            return $this->error('COHORT_NOT_CONFIGURED', 503);
        }
        if ($publicLive) {
            return $this->error('PUBLIC_LIVE_LINK_DISABLED', 403);
        }
        $user = $request->user() ?: auth('api')->user();
        if (! CohortPolicy::activeUser($user)) {
            return $this->error('ACCOUNT_RESTRICTED', 403);
        }
        // Membership/application controllers enforce their own role and object rules.
        // History controllers already scope their result to the authenticated user.
        if ($s1 || $history) {
            return $next($request);
        }

        $trip = null;
        $suffix = null;
        if (preg_match('~^api/trips/(\d+)(?:/(.*))?$~', $path, $match)) {
            $trip = Trip::find((int) $match[1]);
            if (! $trip) {
                return $this->error('TRIP_NOT_FOUND', 404);
            }
            $suffix = $match[2] ?? '';
            $owner = (int) $trip->user_id === (int) $user->id;
            $booking = DB::table('trip_passengers')->where('trip_id', $trip->id)->where('user_id', $user->id)->first();
            $participant = $owner || $booking !== null;
            // Stop/exit actions remain available after cohort suspension. Existing
            // canonical controllers still enforce target ownership and trip state.
            if ($request->isMethod('POST') && preg_match('~^requests/\d+/(cancel|reject)$~', $suffix)) {
                return $participant ? $next($request) : $this->error('TRIP_FORBIDDEN', 403);
            }
            if ($request->isMethod('DELETE') && $suffix === '') {
                return $owner ? $next($request) : $this->error('TRIP_FORBIDDEN', 403);
            }
            if (str_starts_with($suffix, 'live-share')) {
                if ($suffix === 'live-share/stop' && $request->isMethod('POST') && $participant) {
                    return $next($request);
                }

                return $this->error('LIVE_SHARE_DISABLED', 403);
            }
            if ($request->isMethod('GET') && $suffix === 'requests') {
                return $owner ? $next($request) : $this->error('TRIP_FORBIDDEN', 403);
            }
            if ($request->isMethod('GET') && $suffix === 'passengers') {
                return $owner || ($booking && (int) $booking->request_state === Passenger::STATE_ACCEPTED)
                    ? $next($request) : $this->error('TRIP_FORBIDDEN', 403);
            }
            if ($request->isMethod('GET') && $suffix === '' && $participant) {
                return $next($request);
            }
            if ($request->isMethod('POST') && preg_match('~^(rate|reply)/\d+$~', $suffix) && $participant) {
                return $next($request);
            }
        }

        if (! CohortPolicy::approved((int) $user->id)) {
            return $this->error('MEMBERSHIP_REQUIRED', 403);
        }
        if ($pilot && $request->isMethod('POST')) {
            $cars = DB::table('mobility_host_applications')->where('user_id', $user->id)->where('status', 'approved')->get()
                ->filter(fn ($a) => CohortPolicy::hostApproved((int) $user->id, (int) $a->car_id, $a->vehicle_type));
            if ($cars->count() !== 1) {
                return $this->error('HOST_APPROVAL_REQUIRED', 403);
            }
            $approval = $cars->first();
            $error = $this->validateHost($request, (int) $user->id, (int) $approval->car_id);
            if ($error) {
                return $error;
            }
            $request->attributes->set('mobility.approved_car_id', (int) $approval->car_id);

            return $next($request);
        }
        if ($path === 'api/trips' || $path === 'api/trips/') {
            if ($request->isMethod('GET')) {
                return $next($request);
            }
            if ($request->isMethod('POST')) {
                $car = $this->positiveInteger($request->input('car_id'));
                if ($car === null) {
                    return $this->error('VEHICLE_REQUIRED', 422);
                }
                $error = $this->validateHost($request, (int) $user->id, $car);

                return $error ?: $this->error('TRUSTED_ROUTE_QUOTE_REQUIRED', 409);
            }
        }
        if ($trip) {
            if ($request->isMethod('GET')) {
                return $next($request);
            }
            if ($suffix === 'requests' && $request->isMethod('POST')) {
                if (! CohortPolicy::approved((int) $trip->user_id)
                    || ! CohortPolicy::hostApproved((int) $trip->user_id, (int) $trip->car_id)) {
                    return $this->error('HOST_APPROVAL_REQUIRED', 403);
                }

                if (! \STS\Mobility\Segments\SegmentBookingService::managed((int) $trip->id) && ! app(\STS\Mobility\Journeys\PrivateScheduledAdmissionService::class)->managed((int) $trip->id)) return $this->error('TRUSTED_ROUTE_QUOTE_REQUIRED', 409);
                return $next($request);
            }
            if (preg_match('~^requests/(\d+)/accept$~', $suffix, $accept) && $request->isMethod('POST')) {
                if ((int) $trip->user_id !== (int) $user->id) {
                    return $this->error('TRIP_OWNER_REQUIRED', 403);
                }
                if (! CohortPolicy::hostApproved((int) $user->id, (int) $trip->car_id)) {
                    return $this->error('HOST_APPROVAL_REQUIRED', 403);
                }
                if (! CohortPolicy::approved((int) $accept[1])) {
                    return $this->error('RIDER_MEMBERSHIP_REQUIRED', 403);
                }

                if (! \STS\Mobility\Segments\SegmentBookingService::managed((int) $trip->id) && ! app(\STS\Mobility\Journeys\PrivateScheduledAdmissionService::class)->managed((int) $trip->id)) return $this->error('TRUSTED_ROUTE_QUOTE_REQUIRED', 409);
                return $next($request);
            }
            if ((in_array($request->method(), ['PUT', 'PATCH'], true) && $suffix === '')
                || ($request->isMethod('POST') && in_array($suffix, ['changeSeats', 'change-visibility', 'invite-friends'], true))) {
                if ((int) $trip->user_id !== (int) $user->id) {
                    return $this->error('TRIP_OWNER_REQUIRED', 403);
                }
                $car = $request->exists('car_id') ? $this->positiveInteger($request->input('car_id')) : (int) $trip->car_id;
                if (! $car) {
                    return $this->error('VEHICLE_REQUIRED', 422);
                }
                $error = $this->validateHost($request, (int) $user->id, $car, (int) $trip->total_seats);

                return $error ?: $next($request);
            }
            if (preg_match('~^requests/\d+/pay$~', $suffix)) {
                return $this->error('LEGACY_PAYMENT_PROMOTION_DISABLED', 409);
            }

            return $this->error('COHORT_MUTATION_NOT_SUPPORTED', 403);
        }

        // Public route price/autocomplete and unknown trip paths are not an
        // alternate trusted publishing or admission interface in cohort mode.
        return $pilot && $request->isMethod('GET') ? $next($request) : $this->error('COHORT_MUTATION_NOT_SUPPORTED', 403);
    }

    private function validateHost(Request $request, int $userId, int $carId, ?int $currentSeats = null)
    {
        $approval = DB::table('mobility_host_applications')->where('user_id', $userId)->where('car_id', $carId)->where('status', 'approved')->first();
        if (! $approval || ! DriverOnboardingPolicy::approvedApplication($approval) || ! CohortPolicy::hostApproved($userId, $carId, $approval->vehicle_type)) {
            return $this->error('HOST_APPROVAL_REQUIRED', 403);
        }
        if ($request->exists('vehicle_type') && $request->input('vehicle_type') !== $approval->vehicle_type) {
            return $this->error('VEHICLE_TYPE_INVALID', 422);
        }
        $seats = $request->exists('total_seats') ? $this->positiveInteger($request->input('total_seats')) : $currentSeats;
        if ($request->exists('total_seats') && $seats === null) {
            return $this->error('SEAT_COUNT_INVALID', 422);
        }
        // The legacy changeSeats endpoint uses a delta, so check its resulting
        // canonical capacity as well as the publish/update total_seats input.
        if (str_ends_with($request->path(), '/changeSeats')) {
            $input = $request->input('increment');
            if (! is_int($input) && ! is_string($input)) {
                return $this->error('SEAT_COUNT_INVALID', 422);
            }
            $delta = filter_var($input, FILTER_VALIDATE_INT);
            if ($delta === false) {
                return $this->error('SEAT_COUNT_INVALID', 422);
            }
            $seats = ($currentSeats ?? 0) + $delta;
        }
        if ($approval->vehicle_type === 'bike' && $seats !== null && $seats > 1) {
            return $this->error('BIKE_CAPACITY_EXCEEDED', 422);
        }

        return null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT);

        return $int !== false && $int > 0 ? $int : null;
    }

    private function error(string $code, int $status)
    {
        return response()->json(['error' => ['code' => $code]], $status);
    }
}
