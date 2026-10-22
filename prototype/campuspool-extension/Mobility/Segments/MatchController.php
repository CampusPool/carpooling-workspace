<?php

namespace STS\Mobility\Segments;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Matching\RouteSegmentMatcher;
use STS\Mobility\Recurrence\ProgramInputs;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Trip;
use STS\Models\User;
use STS\Services\Logic\PassengersManager;

final class MatchController
{
    public function search(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $rider = $request->user();
            $this->actor($rider);
            $input = $request->all();
            ProgramInputs::fields($input, ['service_date', 'pickup_point_id', 'dropoff_point_id'], ['from_time', 'to_time', 'preferred_time', 'vehicle_type', 'page_size']);
            $date = ProgramInputs::date($input['service_date']);
            if ($date->lt(CarbonImmutable::now('Asia/Kolkata')->startOfDay()) || $date->gt(CarbonImmutable::now('Asia/Kolkata')->startOfDay()->addDays(30))) {
                throw new SegmentException('MATCH_QUERY_INVALID', 422);
            }
            $pickup = ProgramInputs::integer($input['pickup_point_id'], 1, PHP_INT_MAX);
            $dropoff = ProgramInputs::integer($input['dropoff_point_id'], 1, PHP_INT_MAX);
            if ($pickup === $dropoff) {
                throw new SegmentException('MATCH_QUERY_INVALID', 422);
            }
            $from = ProgramInputs::time($input['from_time'] ?? '00:00');
            $to = ProgramInputs::time($input['to_time'] ?? '23:59');
            $preferred = ProgramInputs::time($input['preferred_time'] ?? $from);
            if ($from > $to || $preferred < $from || $preferred > $to) {
                throw new SegmentException('MATCH_QUERY_INVALID', 422);
            }
            $mode = $input['vehicle_type'] ?? null;
            if ($mode !== null && ! in_array($mode, ['car', 'bike'], true)) {
                throw new SegmentException('MATCH_QUERY_INVALID', 422);
            }
            $size = ProgramInputs::integer($input['page_size'] ?? 10, 1, 20);
            $query = Trip::query()->join('mobility_pool_publications as publication', 'publication.trip_id', '=', 'trips.id')->where('trips.user_id', '<>', $rider->id)
                ->where('trips.state', Trip::STATE_READY)->where('trips.friendship_type_id', Trip::PRIVACY_PUBLIC)->where('trips.is_passenger', false)
                ->whereBetween('trips.trip_date', [$date->format('Y-m-d').' 00:00:00', $date->format('Y-m-d').' 23:59:59'])->where('trips.trip_date', '>', CarbonImmutable::now('Asia/Kolkata')->format('Y-m-d H:i:s'))
                ->whereNotExists(function ($q): void {
                    $q->from('mobility_trip_lifecycles')->selectRaw('1')->whereColumn('mobility_trip_lifecycles.trip_id', 'trips.id')->where('state', '!=', 'planned');
                })
                ->select('trips.*')->orderBy('trips.id')->limit(300);
            CohortPolicy::scopeOffers($query);
            $offers = [];
            $trusted = [];
            foreach ($query->get() as $trip) {
                try {
                    $terms = RouteTerms::forTrip((int) $trip->id);
                    if (! $terms || ($mode !== null && $terms['snapshot']['vehicle_type'] !== $mode)) {
                        continue;
                    }
                    $fingerprint = RouteTerms::current($trip, $terms);
                    $arrival = [CarbonImmutable::parse($trip->trip_date, 'Asia/Kolkata')->timestamp];
                    foreach ($terms['legs'] as $leg) {
                        $arrival[] = $arrival[count($arrival) - 1] + $leg['duration_seconds'];
                    }
                    $offers[] = ['trip_id' => (int) $trip->id, 'host_id' => (int) $trip->user_id, 'service_date' => $date->format('Y-m-d'), 'stops' => array_column($terms['stops'], 'id'), 'arrival_epochs' => $arrival, 'segment_free_seats' => SegmentInventory::free($trip, $terms)];
                    $trusted[(int) $trip->id] = ['terms' => $terms, 'fingerprint' => $fingerprint];
                } catch (SegmentException) {
                    continue;
                }
            }
            $ranked = RouteSegmentMatcher::rank(['rider_id' => (int) $rider->id, 'origin_stop_id' => $pickup, 'destination_stop_id' => $dropoff, 'seats' => 1, 'earliest_pickup_epoch' => $date->setTimeFromTimeString($from)->timestamp, 'latest_pickup_epoch' => $date->setTimeFromTimeString($to)->timestamp, 'preferred_pickup_epoch' => $date->setTimeFromTimeString($preferred)->timestamp, 'service_date' => $date->format('Y-m-d'), 'max_results' => $size], $offers);

            return DB::transaction(function () use ($rider, $ranked, $trusted): array {
                $current = User::query()->whereKey($rider->id)->lockForUpdate()->first();
                $this->actor($current);
                if (DB::table('mobility_match_references')->where('user_id', $rider->id)->where('created_at', '>=', now()->subMinutes(5))->count() + count($ranked) > 200) {
                    throw new SegmentException('MATCH_RATE_LIMIT', 429);
                }
                $out = [];
                $expires = CarbonImmutable::now('UTC')->addMinutes(5);
                foreach ($ranked as $match) {
                    $bound = $trusted[$match['trip_id']];
                    $terms = $bound['terms'];
                    $from = array_search($match['pickup_stop_id'], array_column($terms['stops'], 'id'), true);
                    $till = array_search($match['dropoff_stop_id'], array_column($terms['stops'], 'id'), true);
                    $id = (string) Str::uuid();
                    $fare = RouteTerms::fare($terms, $from, $till);
                    DB::table('mobility_match_references')->insert(['id' => $id, 'user_id' => $rider->id, 'trip_id' => $match['trip_id'], 'quote_id' => $terms['quote_id'], 'from_index' => $from, 'till_index' => $till, 'fare_minor' => $fare, 'route_hash' => $terms['hash'], 'vehicle_fingerprint' => $bound['fingerprint'], 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);
                    $out[] = ['match_id' => $id, 'trip_id' => $match['trip_id'], 'pickup_point_id' => $match['pickup_stop_id'], 'dropoff_point_id' => $match['dropoff_stop_id'], 'pickup_local' => CarbonImmutable::createFromTimestamp($match['pickup_epoch'], 'Asia/Kolkata')->toIso8601String(), 'dropoff_local' => CarbonImmutable::createFromTimestamp($match['dropoff_epoch'], 'Asia/Kolkata')->toIso8601String(), 'seats_available' => $match['segment_available_seats'], 'seat_price_minor' => $fare, 'currency' => 'INR', 'synthetic_only' => $terms['snapshot']['synthetic_only'], 'expires_at' => $expires->toISOString(), 'ranking' => ['pickup_difference_seconds' => $match['pickup_difference_seconds'], 'ride_duration_seconds' => $match['ride_duration_seconds']]];
                }

                return ['data' => $out, 'scan_limit' => 300];
            });
        });
    }

    public function request(Request $request, string $match): JsonResponse
    {
        return $this->respond(function () use ($request, $match): array {
            $rider = $request->user();
            if (! CohortPolicy::activeUser($rider)) {
                throw new SegmentException('ACCOUNT_RESTRICTED', 403);
            }
            ProgramInputs::fields($request->all(), []);
            ProgramInputs::uuid($match);
            $key = ProgramInputs::key((string) $request->header('Idempotency-Key', ''));
            $keyHash = hash('sha256', $key);
            $candidate = DB::table('mobility_match_references')->where('id', $match)->where('user_id', $rider->id)->first();
            if (! $candidate) {
                throw new SegmentException('MATCH_NOT_FOUND', 404);
            }
            $result = DB::transaction(function () use ($rider, $candidate, $match, $keyHash): array {
                $trip = Trip::query()->whereKey($candidate->trip_id)->lockForUpdate()->first();
                if (! $trip) {
                    throw new SegmentException('MATCH_TRIP_NOT_PLANNED');
                }
                // The exact key's committed request wins over later expiry, pause or host changes.
                $old = DB::table('mobility_match_references')->where('user_id', $rider->id)->where('request_key_hash', $keyHash)->first();
                if ($old) {
                    if ($old->id !== $match) {
                        throw new SegmentException('IDEMPOTENCY_CONFLICT');
                    }
                    $body = json_decode($old->response_body, true, 512, JSON_THROW_ON_ERROR);
                    $body['existing'] = true;

                    return ['status' => 200, 'data' => $body];
                }
                $terms = RouteTerms::forTrip((int) $trip->id);
                if (! $terms) {
                    throw new SegmentException('MATCH_REFERENCE_STALE');
                }
                $fingerprint = app(SegmentBookingService::class)->activity($trip, (int) $rider->id, $terms);
                $ref = DB::table('mobility_match_references')->where('id', $match)->where('user_id', $rider->id)->lockForUpdate()->first();
                if ($ref->booking_id !== null) {
                    throw new SegmentException('MATCH_ALREADY_USED');
                }
                if (CarbonImmutable::parse($ref->expires_at, 'UTC')->lte(CarbonImmutable::now('UTC'))) {
                    throw new SegmentException('MATCH_REFERENCE_EXPIRED');
                }
                if ($ref->quote_id !== $terms['quote_id'] || ! hash_equals($ref->route_hash, $terms['hash']) || ! hash_equals($ref->vehicle_fingerprint, $fingerprint) || (int) $ref->fare_minor !== RouteTerms::fare($terms, (int) $ref->from_index, (int) $ref->till_index)) {
                    throw new SegmentException('MATCH_REFERENCE_STALE');
                }
                $binding = ['trip_id' => (int) $trip->id, 'user_id' => (int) $rider->id, 'from_index' => (int) $ref->from_index, 'till_index' => (int) $ref->till_index, 'fare_minor' => (int) $ref->fare_minor, 'quote_id' => $ref->quote_id, 'route_hash' => $ref->route_hash, 'match_reference_id' => $ref->id];
                $booking = BookingIntervalContext::run($binding, fn () => app(PassengersManager::class)->newRequest((int) $trip->id, $rider, []));
                if (! $booking) {
                    throw new SegmentException('BOOKING_ALREADY_ACTIVE');
                }
                $body = ['trip_id' => (int) $trip->id, 'booking_id' => (int) $booking->id, 'request_state' => (int) $booking->request_state, 'existing' => false];
                DB::table('mobility_match_references')->where('id', $ref->id)->update(['booking_id' => $booking->id, 'request_key_hash' => $keyHash, 'response_body' => json_encode($body, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

                return ['status' => 201, 'data' => $body];
            });

            return ['data' => $result['data'], '_status' => $result['status']];
        });
    }

    private function actor(?User $rider): void
    {
        if (! CohortPolicy::activeUser($rider)) {
            throw new SegmentException('ACCOUNT_RESTRICTED', 403);
        }
        if (! CohortPolicy::college()) {
            throw new SegmentException('COHORT_NOT_CONFIGURED', 503);
        }
        if (! CohortPolicy::approved((int) $rider->id)) {
            throw new SegmentException('MEMBERSHIP_REQUIRED', 403);
        }
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            $body = $operation();
            $status = $body['_status'] ?? 200;
            unset($body['_status']);

            return response()->json($body, $status);
        } catch (SegmentException $error) {
            return response()->json(['error' => ['code' => $error->machineCode]], $error->httpStatus);
        } catch (\STS\Mobility\Recurrence\ProgramException $error) {
            return response()->json(['error' => ['code' => $error->machineCode]], $error->httpStatus);
        } catch (\Illuminate\Database\QueryException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                return response()->json(['error' => ['code' => 'IDEMPOTENCY_CONFLICT']], 409);
            } report($error);

            return response()->json(['error' => ['code' => 'MATCH_UNAVAILABLE']], 500);
        } catch (\Throwable $error) {
            report($error);

            return response()->json(['error' => ['code' => 'MATCH_UNAVAILABLE']], 500);
        }
    }
}
