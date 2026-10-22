<?php

namespace STS\Mobility\Dispatch;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Bounded durable DB work. A process restart never owns a deadline or assignment. */
final class DispatchWorker
{
    public function process(int $limit = 20): int
    {
        $ids = DB::table('mobility_dispatch_requests')->whereIn('state', ['searching', 'offered', 'assigned'])
            ->where('next_attempt_at', '<=', CarbonImmutable::now('UTC'))->orderBy('next_attempt_at')->orderBy('id')->limit(max(1, min(100, $limit)))->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id): void {
                $request = DB::table('mobility_dispatch_requests')->where('id', $id)->lock('for update skip locked')->first();
                $now = CarbonImmutable::now('UTC');
                if (! $request || ! in_array($request->state, ['searching', 'offered', 'assigned'], true)
                    || CarbonImmutable::parse($request->next_attempt_at, 'UTC')->gt($now)) {
                    return;
                }
                // Deterministic offer locks precede driver availability; all request writers use this order.
                $offers = DB::table('mobility_dispatch_offers')->where('request_id', $id)->orderBy('id')->lockForUpdate()->get();
                if ($request->state === 'assigned') {
                    $assignment = DB::table('mobility_dispatch_assignments')->where('id', $request->current_assignment_id)->first();
                    if (! $assignment) {
                        throw new DispatchException('ASSIGNMENT_REQUIRED');
                    }
                    DB::table('mobility_dispatch_availability')->where('driver_id', $assignment->driver_id)->lockForUpdate()->first();
                    $trip = DB::table('trips')->where('id', $assignment->trip_id)->lockForUpdate()->first();
                    $lifecycle = DB::table('mobility_trip_lifecycles')->where('trip_id', $assignment->trip_id)->lockForUpdate()->first();
                    $terminal = in_array($lifecycle?->state, ['cancelled', 'completed'], true) || $trip?->state === \STS\Models\Trip::STATE_CANCELED;
                    if ($terminal) {
                        DB::table('mobility_dispatch_assignments')->where('id', $assignment->id)->update(['active_request_id' => null, 'active_driver_id' => null, 'updated_at' => $now]);
                        DB::table('mobility_dispatch_availability')->where('driver_id', $assignment->driver_id)->where('active_request_id', $id)->update(['active_request_id' => null, 'online' => false, 'updated_at' => $now]);
                        DB::table('mobility_dispatch_requests')->where('id', $id)->update(['state' => 'closed', 'active_rider_id' => null, 'updated_at' => $now]);
                        $this->event($id, 'request.closed', ['assignment_id' => $assignment->id, 'canonical_state' => $lifecycle?->state ?? 'cancelled']);
                    } else {
                        DB::table('mobility_dispatch_requests')->where('id', $id)->update(['next_attempt_at' => $now->addSeconds(5), 'updated_at' => $now]);
                    }

                    return;
                }
                foreach ($offers as $offer) {
                    if ($offer->state === 'pending' && CarbonImmutable::parse($offer->expires_at, 'UTC')->lte($now)) {
                        DB::table('mobility_dispatch_offers')->where('id', $offer->id)->update(['state' => 'expired', 'updated_at' => $now]);
                        $this->event($id, 'offer.expired', ['offer_id' => $offer->id], $offer->id);
                    }
                }
                if (CarbonImmutable::parse($request->expires_at, 'UTC')->lte($now)) {
                    $this->finish($id, 'expired', $now);

                    return;
                }
                $pending = DB::table('mobility_dispatch_offers')->where('request_id', $id)->where('state', 'pending')->lockForUpdate()->get();
                if ($pending->isNotEmpty()) {
                    DB::table('mobility_dispatch_requests')->where('id', $id)->update(['next_attempt_at' => $pending->min('expires_at'), 'updated_at' => $now]);

                    return;
                }
                if ((int) $request->attempt >= 3) {
                    $this->finish($id, 'no_driver', $now);

                    return;
                }
                $quote = DB::table('mobility_route_quotes')->where('id', $request->quote_id)->first();
                if (! $quote || $quote->dispatch_request_id !== $id || ! hash_equals($request->quote_hash, hash('sha256', $quote->snapshot))) {
                    $this->finish($id, 'cancelled', $now);

                    return;
                }
                $terms = DispatchTerms::forQuote($quote);
                if (($request->dispatch_scope ?? 'catalog') === 'private') {
                    $target = (int) ($request->target_driver_id ?? 0);
                    if ($target < 1 || (int) ($terms['driver_id'] ?? 0) !== $target || ($terms['private'] ?? false) !== true) {
                        $this->finish($id, 'cancelled', $now);
                        return;
                    }
                    $already = DB::table('mobility_dispatch_offers')->where('request_id', $id)->whereIn('state', ['accepted', 'declined', 'expired'])->exists();
                    $candidate = DB::table('mobility_dispatch_availability')->where('driver_id', $target)->where('dispatch_scope', 'private')->where('online', true)->where('vehicle_type', $request->vehicle_type)->where('fresh_until', '>', $now)->whereNull('active_request_id')->lock('for update skip locked')->first();
                    $created = 0;
                    if (! $already && $candidate) {
                        try { $fingerprint = DispatchEligibility::privateCurrent($terms, $target, (int) $candidate->car_id); }
                        catch (DispatchException $error) {
                            if ($error->machineCode !== 'DISPATCH_ELIGIBILITY_BUSY') {
                                $this->finish($id, 'cancelled', $now);
                                $this->event($id, 'private.source_invalidated', ['code'=>$error->machineCode]);
                                return;
                            }
                            $fingerprint = null;
                        }
                        if ($fingerprint !== null && hash_equals((string) $candidate->vehicle_fingerprint, $fingerprint)) {
                            $offerId = (string) Str::uuid();
                            $expiry = CarbonImmutable::createFromTimestampUTC(min($now->addSeconds(30)->timestamp, CarbonImmutable::parse($request->expires_at, 'UTC')->timestamp, CarbonImmutable::parse($quote->expires_at, 'UTC')->timestamp));
                            DB::table('mobility_dispatch_offers')->insert(['id' => $offerId, 'request_id' => $id, 'driver_id' => $target, 'car_id' => $candidate->car_id, 'vehicle_fingerprint' => $fingerprint, 'attempt' => (int) $request->attempt + 1, 'state' => 'pending', 'expires_at' => $expiry, 'created_at' => $now, 'updated_at' => $now]);
                            DB::table('mobility_dispatch_events')->insert(['request_id' => $id, 'driver_id' => $target, 'offer_id' => $offerId, 'action' => 'offer.created', 'event_key' => 'offer.created:'.$offerId, 'payload' => json_encode(['attempt' => (int) $request->attempt + 1, 'scope' => 'private'], JSON_THROW_ON_ERROR), 'created_at' => $now]);
                            DB::table('mobility_dispatch_availability')->where('driver_id', $target)->update(['last_offered_at' => $now, 'updated_at' => $now]);
                            $created = 1;
                        }
                    }
                    $next = $created ? DB::table('mobility_dispatch_offers')->where('request_id', $id)->where('state', 'pending')->value('expires_at') : $now->addSeconds(5);
                    DB::table('mobility_dispatch_requests')->where('id', $id)->update(['state' => $created ? 'offered' : 'searching', 'attempt' => (int) $request->attempt + ($created ? 1 : 0), 'next_attempt_at' => $next, 'updated_at' => $now]);
                    return;
                }
                $already = DB::table('mobility_dispatch_offers')->where('request_id', $id)->whereIn('state', ['accepted', 'declined', 'expired'])->lockForUpdate()->pluck('driver_id')->unique()->all();
                $candidates = DB::table('mobility_dispatch_availability')->where('online', true)->where('driver_id', '<>', $request->rider_id)
                    ->where('vehicle_type', $request->vehicle_type)->where('pickup_point_id', $request->pickup_point_id)->where('fresh_until', '>', $now)->whereNull('active_request_id')
                    ->when($already !== [], fn ($q) => $q->whereNotIn('driver_id', $already))->orderBy('driver_id')->limit(100)->lock('for update skip locked')->get()
                    ->sortBy([['last_offered_at', 'asc'], ['driver_id', 'asc']]);
                $created = 0;
                $expiry = CarbonImmutable::createFromTimestampUTC(min($now->addSeconds(30)->timestamp, CarbonImmutable::parse($request->expires_at, 'UTC')->timestamp, CarbonImmutable::parse($quote->expires_at, 'UTC')->timestamp));
                foreach ($candidates as $candidate) {
                    try {
                        $fingerprint = DispatchEligibility::current($terms, (int) $candidate->driver_id, (int) $candidate->car_id);
                    } catch (DispatchException $error) {
                        if (! in_array($error->machineCode, ['DISPATCH_ELIGIBILITY_BUSY', 'MEMBERSHIP_REQUIRED', 'DRIVER_VEHICLE_CHANGED', 'MATCH_ROUTE_CHANGED', 'PICKUP_POINT_UNAVAILABLE'], true)) {
                            throw $error;
                        }

                        continue;
                    }
                    if (! hash_equals($candidate->vehicle_fingerprint, $fingerprint)) {
                        continue;
                    }
                    $offerId = (string) Str::uuid();
                    DB::table('mobility_dispatch_offers')->insert(['id' => $offerId, 'request_id' => $id, 'driver_id' => $candidate->driver_id, 'car_id' => $candidate->car_id,
                        'vehicle_fingerprint' => $fingerprint, 'attempt' => (int) $request->attempt + 1, 'state' => 'pending', 'expires_at' => $expiry, 'created_at' => $now, 'updated_at' => $now]);
                    DB::table('mobility_dispatch_events')->insert(['request_id' => $id, 'driver_id' => $candidate->driver_id, 'offer_id' => $offerId, 'action' => 'offer.created', 'event_key' => 'offer.created:'.$offerId,
                        'payload' => json_encode(['attempt' => (int) $request->attempt + 1], JSON_THROW_ON_ERROR), 'created_at' => $now]);
                    DB::table('mobility_dispatch_availability')->where('driver_id', $candidate->driver_id)->update(['last_offered_at' => $now, 'updated_at' => $now]);
                    if (++$created === 2) {
                        break;
                    }
                }
                DB::table('mobility_dispatch_requests')->where('id', $id)->update(['state' => $created ? 'offered' : 'searching', 'attempt' => (int) $request->attempt + ($created ? 1 : 0), 'next_attempt_at' => $created ? $expiry : $now->addSeconds(5), 'updated_at' => $now]);
            });
        }

        return $ids->count();
    }

    private function finish(string $id, string $state, CarbonImmutable $now): void
    {
        DB::table('mobility_dispatch_offers')->where('request_id', $id)->where('state', 'pending')->update(['state' => 'revoked', 'updated_at' => $now]);
        DB::table('mobility_dispatch_requests')->where('id', $id)->update(['state' => $state, 'active_rider_id' => null, 'updated_at' => $now]);
        $this->event($id, 'request.'.$state, ['state' => $state]);
    }

    private function event(string $id, string $action, array $payload, string $suffix = ''): void
    {
        DB::table('mobility_dispatch_events')->insertOrIgnore(['request_id' => $id, 'action' => $action, 'event_key' => $action.':'.$id.':'.$suffix, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => CarbonImmutable::now('UTC')]);
    }
}
