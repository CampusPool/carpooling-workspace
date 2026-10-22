<?php

namespace STS\Mobility\Journeys;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Pickup\PickupException;
use STS\Mobility\Pickup\PickupPolicy;
use STS\Mobility\Pickup\PickupSelectionService;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\User;

/** Immutable private full-journey quote. It deliberately has no booking authority. */
final class PrivateJourneyQuoteService
{
    public function __construct(
        private readonly JourneyPlanner $planner,
        private readonly PrivateEndpointResolver $endpoints,
        private readonly PickupSelectionService $selections,
    ) {}

    public function prepare(User $rider, array $input, string $key): array
    {
        self::fields($input, ['selection_id', 'origin_endpoint_id', 'destination_endpoint_id', 'expected_terms_hash', 'expected_selection_version']);
        $selectionId = self::uuid($input['selection_id']);
        $originId = self::uuid($input['origin_endpoint_id']);
        $destinationId = self::uuid($input['destination_endpoint_id']);
        $version = self::positive($input['expected_selection_version']);
        $termsHash = self::hash64($input['expected_terms_hash']);
        $keyHash = self::key($key);
        $requestHash = self::hash([$selectionId, $originId, $destinationId, $termsHash, $version]);
        $existing = DB::table('mobility_private_journey_quotes')->where('rider_id', $rider->id)->where('create_key_hash', $keyHash)->first();
        if ($existing) {
            self::same($existing->request_hash, $requestHash, 'IDEMPOTENCY_CONFLICT');
            $this->integrity($existing);
            if (CarbonImmutable::parse($existing->expires_at, 'UTC')->lte(CarbonImmutable::now('UTC'))) throw new PickupException('QUOTE_EXPIRED', 410);
            $this->selection($rider, $selectionId, $version, $termsHash);
            return $this->result($existing, true, 200);
        }

        // This is the only routing call. It occurs before the mutation transaction.
        $selection = $this->selection($rider, $selectionId, $version, $termsHash);
        $row = DB::table('mobility_pickup_selections')->where('id', $selectionId)->first();
        if ((int) $row->rider_id !== (int) $rider->id) throw new PickupException('WRONG_ACTOR', 403);
        $origin = $this->endpoints->resolve($rider, $originId);
        $destination = $this->endpoints->resolve($rider, $destinationId);
        $intent = DB::table('mobility_pickup_intents')->where('id', $row->intent_id)->first();
        $intentOrigin = self::decode($intent->origin);
        if ($origin['point'] !== ['lat' => (float) $intentOrigin['lat'], 'lng' => (float) $intentOrigin['lng']]) throw new PickupException('ORIGIN_ENDPOINT_MISMATCH', 409);
        $plan = DB::table('mobility_pickup_plans')->where('id', $row->plan_id)->first();
        $baseline = self::decode($plan->path_snapshot);
        $baseline['points'] = self::decode($plan->points);
        $terms = self::decode($row->terms);
        $preview = DB::table('mobility_pickup_previews')->where('id', $row->preview_id)->first();
        $preferences = self::decode($preview->snapshot)['preferences'] ?? [];
        $choice = (string) $row->choice;
        $full = ($plan->source_publication_id ?? null)
            ? $this->planner->planPublished($baseline, $origin['point'], $destination['point'], (string) $plan->vehicle_type, $choice, (int) $intent->max_walk_meters, (string) $plan->source_publication_route_hash)
            : $this->planner->plan($baseline, $origin['point'], $destination['point'], (string) $plan->vehicle_type, $choice, (int) $intent->max_walk_meters, (int) ($preferences['max_detour_meters'] ?? 0), (int) ($preferences['max_detour_seconds'] ?? 0));
        if (($full['available'] ?? false) !== true) throw new PickupException((string) ($full['code'] ?? 'NO_COMPATIBLE_JOURNEY_WITHIN_LIMITS'), 409);
        $snapshot = $this->snapshot($full, $selection, $row, $plan, $intent, $origin, $destination, $terms);
        $json = self::encode($snapshot);
        $expires = $this->expiry(
            $row->expires_at,
            $intent->expires_at,
            $plan->expires_at,
            $preview->expires_at,
            $origin['expires_at'],
            $destination['expires_at'],
        );

        return DB::transaction(function () use ($rider, $selectionId, $keyHash, $requestHash, $origin, $destination, $snapshot, $json, $expires, $row, $termsHash, $version): array {
            $trustIds = [(int) $row->driver_id, (int) $row->rider_id]; sort($trustIds, SORT_NUMERIC);
            DB::table('users')->whereIn('id', $trustIds)->orderBy('id')->lockForUpdate()->get();
            DB::table('mobility_memberships')->whereIn('user_id', $trustIds)->orderBy('user_id')->lockForUpdate()->get();
            $carId = DB::table('mobility_pickup_plans')->where('id', $row->plan_id)->value('car_id');
            DB::table('cars')->where('id', $carId)->lockForUpdate()->first();
            DB::table('mobility_host_applications')->where('car_id', $carId)->orderBy('id')->lockForUpdate()->get();
            DB::table('mobility_pickup_plans')->where('id', $row->plan_id)->lockForUpdate()->first();
            DB::table('mobility_pickup_intents')->where('id', $row->intent_id)->lockForUpdate()->first();
            DB::table('mobility_pickup_previews')->where('id', $row->preview_id)->lockForUpdate()->first();
            DB::table('mobility_pickup_selections')->where('id', $selectionId)->lockForUpdate()->first();
            DB::table('mobility_private_endpoints')->whereIn('id', [$origin['id'], $destination['id']])->orderBy('id')->lockForUpdate()->get();
            $source = DB::table('mobility_pickup_selections')->where('id', $selectionId)->first();
            if (!$source || (int) $source->version !== $version || !hash_equals($source->terms_hash, $termsHash) || $source->state !== 'confirmed') throw new PickupException('SOURCE_CHANGED', 409);
            // Re-run the existing source validator after the lock sequence. This
            // catches plan/intent/preview/graph/eligibility changes made while
            // the route was being computed, without routing under a lock.
            $this->selections->show($rider, $selectionId);
            $endpointRows = DB::table('mobility_private_endpoints')->whereIn('id', [$origin['id'], $destination['id']])->get()->keyBy('id');
            foreach ([$origin, $destination] as $endpoint) {
                $endpointRow = $endpointRows->get($endpoint['id']);
                if (!$endpointRow || !hash_equals((string) $endpointRow->coordinate_hash, (string) $endpoint['hash']) || $endpointRow->redacted_at || CarbonImmutable::parse($endpointRow->expires_at, 'UTC')->lte(CarbonImmutable::now('UTC'))) throw new PickupException('ENDPOINT_EXPIRED', 410);
            }
            $retry = DB::table('mobility_private_journey_quotes')->where('rider_id', $rider->id)->where('create_key_hash', $keyHash)->lockForUpdate()->first();
            if ($retry) { self::same($retry->request_hash, $requestHash, 'IDEMPOTENCY_CONFLICT'); return $this->result($retry, true, 200); }
            $active = DB::table('mobility_private_journey_quotes')->where('active_selection_id', $selectionId)->lockForUpdate()->first();
            if ($active && $active->state === 'accepted') {
                throw new PickupException('CANONICAL_ACTION_REQUIRED', 409);
            }
            if ($active && in_array($active->state, ['pending_driver', 'pending_rider'], true)) {
                DB::table('mobility_private_journey_quotes')->where('id', $active->id)->update([
                    'state' => 'superseded',
                    'active_selection_id' => null,
                    'closed_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $id = (string) Str::uuid(); $routeId = (string) Str::uuid(); $now = CarbonImmutable::now('UTC');
            DB::table('mobility_route_quotes')->insert(['id' => $routeId, 'user_id' => $rider->id, 'college_code' => CohortPolicy::college(), 'snapshot' => $json, 'expires_at' => $expires, 'created_at' => $now, 'updated_at' => $now]);
            $persisted = (string) DB::table('mobility_route_quotes')->where('id', $routeId)->value('snapshot');
            $quoteHash = hash('sha256', $persisted);
            DB::table('mobility_private_journey_quotes')->insert(['id' => $id, 'route_quote_id' => $routeId, 'selection_id' => $selectionId, 'active_selection_id' => $selectionId, 'driver_id' => $row->driver_id, 'rider_id' => $row->rider_id, 'origin_endpoint_id' => $origin['id'], 'destination_endpoint_id' => $destination['id'], 'origin_endpoint_hash' => $origin['hash'], 'destination_endpoint_hash' => $destination['hash'], 'source_terms_hash' => $termsHash, 'source_selection_version' => $version, 'version' => 1, 'state' => 'pending_driver', 'quote_hash' => $quoteHash, 'create_key_hash' => $keyHash, 'request_hash' => $requestHash, 'expires_at' => $expires, 'created_at' => $now, 'updated_at' => $now]);
            return $this->result(DB::table('mobility_private_journey_quotes')->where('id', $id)->first(), false, 201);
        });
    }

    public function show(User $actor, string $id): array
    {
        $row = $this->row($id); $this->participant($actor, $row); $this->integrity($row); $this->selection(User::find($row->rider_id), $row->selection_id, (int) $row->source_selection_version, (string) $row->source_terms_hash); if (CarbonImmutable::parse($row->expires_at, 'UTC')->lte(CarbonImmutable::now('UTC'))) throw new PickupException('QUOTE_EXPIRED', 410); return $this->result($row, false, 200);
    }

    public function action(User $actor, string $id, string $kind, array $input, string $key): array
    {
        if (!in_array($kind, ['driver-approve', 'rider-consent', 'decline', 'revoke'], true)) throw new PickupException('INPUT_INVALID');
        $required = ['expected_version', 'quote_hash']; if ($kind === 'rider-consent') $required[] = 'access_acknowledged';
        self::fields($input, $required); $version = self::positive($input['expected_version']); $quoteHash = self::hash64($input['quote_hash']);
        if ($kind === 'rider-consent' && $input['access_acknowledged'] !== true) throw new PickupException('INPUT_INVALID');
        $keyHash = self::key($key); $requestHash = self::hash([$id, $kind, $version, $quoteHash, $kind === 'rider-consent']);
        $preflight = $this->row($id); $this->participant($actor, $preflight);
        return DB::transaction(function () use ($actor, $id, $kind, $version, $quoteHash, $keyHash, $requestHash): array {
            $pre = DB::table('mobility_private_journey_quotes')->where('id', $id)->first(); if (!$pre) throw new PickupException('QUOTE_NOT_FOUND', 404);
            $this->lockTrust($pre);
            $row = DB::table('mobility_private_journey_quotes')->where('id', $id)->lockForUpdate()->first();
            $this->participant($actor, $row); self::same((string) $row->quote_hash, $quoteHash, 'HASH_MISMATCH');
            if (in_array($kind, ['driver-approve', 'rider-consent'], true)) {
                $this->integrity($row);
                $this->selection(User::find($row->rider_id), $row->selection_id, (int) $row->source_selection_version, (string) $row->source_terms_hash);
                if (CarbonImmutable::parse($row->expires_at, 'UTC')->lte(CarbonImmutable::now('UTC'))) throw new PickupException('QUOTE_EXPIRED', 410);
            }
            $action = DB::table('mobility_private_journey_quote_actions')
                ->where('actor_id', $actor->id)
                ->where('key_hash', $keyHash)
                ->lockForUpdate()
                ->first();
            if ($action) {
                self::same($action->request_hash, $requestHash, 'IDEMPOTENCY_CONFLICT');
                if ((string) $action->quote_id !== (string) $id) {
                    throw new PickupException('IDEMPOTENCY_CONFLICT', 409);
                }
                return $this->result($row, true, 200);
            }
            if ((int) $row->version !== $version) throw new PickupException('VERSION_MISMATCH', 409);
            if ($kind === 'driver-approve') { if ((int) $actor->id !== (int) $row->driver_id) throw new PickupException('WRONG_ACTOR', 403); if ($row->state !== 'pending_driver') throw new PickupException('QUOTE_CLOSED', 409); $update = ['state' => 'pending_rider', 'version' => ((int) $row->version) + 1, 'driver_approved_at' => now(), 'driver_approval_hash' => $quoteHash]; }
            elseif ($kind === 'rider-consent') { if ((int) $actor->id !== (int) $row->rider_id) throw new PickupException('WRONG_ACTOR', 403); if ($row->state !== 'pending_rider') throw new PickupException('DRIVER_APPROVAL_REQUIRED', 409); if ((string)$row->driver_approval_hash !== $quoteHash) throw new PickupException('DRIVER_APPROVAL_REQUIRED',409); $update = ['state' => 'accepted', 'version' => ((int) $row->version) + 1, 'rider_consented_at' => now()]; }
            else { if ($kind === 'revoke' && !in_array((int) $actor->id, [(int) $row->driver_id, (int) $row->rider_id], true)) throw new PickupException('WRONG_ACTOR', 403); if ($kind === 'decline' && (int) $actor->id !== (int) $row->rider_id) throw new PickupException('WRONG_ACTOR', 403); $consumed = DB::table('mobility_route_quotes')->where('id', $row->route_quote_id)->where(function ($q): void { $q->whereNotNull('consumed_trip_id')->orWhereNotNull('dispatch_request_id'); })->exists(); if ($consumed) throw new PickupException('CANONICAL_ACTION_REQUIRED', 409); if ($row->state === 'accepted' && $kind === 'decline') throw new PickupException('CANONICAL_ACTION_REQUIRED', 409); if (!in_array($row->state, ['pending_driver', 'pending_rider', 'accepted'], true)) throw new PickupException('QUOTE_CLOSED', 409); $update = ['state' => $kind === 'decline' ? 'declined' : 'revoked', 'version' => ((int) $row->version) + 1, 'active_selection_id' => null, 'closed_at' => now()]; }
            DB::table('mobility_private_journey_quotes')->where('id', $id)->update($update + ['updated_at' => now()]); DB::table('mobility_private_journey_quote_actions')->insert(['quote_id' => $id, 'actor_id' => $actor->id, 'action' => $kind, 'key_hash' => $keyHash, 'request_hash' => $requestHash, 'created_at' => now(), 'updated_at' => now()]); return $this->result(DB::table('mobility_private_journey_quotes')->where('id', $id)->first(), false, 200);
        });
    }

    private function selection(User $rider, string $id, int $version, string $hash): array { $result = $this->selections->show($rider, $id); $selection = $result['data']['selection']; if ((int)$selection['version'] !== $version || !hash_equals($selection['terms_hash'], $hash) || $selection['state'] !== 'confirmed') throw new PickupException('SOURCE_CHANGED', 409); return $selection; }
    private function snapshot(array $full, array $selection, object $row, object $plan, object $intent, array $origin, array $destination, array $terms): array { return ['schema' => 'private-full-journey-quote-v1', 'scope' => 'private_participants_only', 'booking_enabled' => false, 'selection_id' => $row->id, 'selection_version' => (int)$row->version, 'selection_terms_hash' => $selection['terms_hash'], 'driver_id' => (int)$row->driver_id, 'rider_id' => (int)$row->rider_id, 'choice' => $row->choice, 'vehicle_type' => $plan->vehicle_type, 'ride_mode' => $plan->ride_mode, 'origin_endpoint' => ['id'=>$origin['id'],'hash'=>$origin['hash'],'source'=>$origin['source']], 'destination_endpoint' => ['id'=>$destination['id'],'hash'=>$destination['hash'],'source'=>$destination['source']], 'driver_path' => $full['driver_path'], 'access' => $full['access'], 'riding' => $full['riding'], 'egress' => $full['egress'], 'total_walking_meters' => $full['total_walking_meters'], 'max_walk_meters' => $full['max_walk_meters'], 'detour' => $full['detour'], 'pricing' => $full['pricing'], 'graph' => ['vehicle_data_version' => $plan->path_data_version, 'foot_data_version' => $terms['graph']['foot_data_version'] ?? null]]; }
    private function row(string $id): object { if (!Str::isUuid($id)) throw new PickupException('QUOTE_NOT_FOUND', 404); $r=DB::table('mobility_private_journey_quotes')->where('id',$id)->first(); if(!$r) throw new PickupException('QUOTE_NOT_FOUND',404); return $r; }
    private function participant(User $actor, object $row): void { if (!in_array((int)$actor->id,[(int)$row->driver_id,(int)$row->rider_id],true)) throw new PickupException('QUOTE_FORBIDDEN',403); }
    private function lockTrust(object $row): void { $ids = [(int)$row->driver_id, (int)$row->rider_id]; sort($ids, SORT_NUMERIC); DB::table('users')->whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get(); DB::table('mobility_memberships')->whereIn('user_id',$ids)->orderBy('user_id')->lockForUpdate()->get(); $selection=DB::table('mobility_pickup_selections')->where('id',$row->selection_id)->first(); $plan=DB::table('mobility_pickup_plans')->where('id',$selection->plan_id)->first(); DB::table('cars')->where('id',$plan->car_id)->lockForUpdate()->first(); DB::table('mobility_host_applications')->where('car_id',$plan->car_id)->orderBy('id')->lockForUpdate()->get(); DB::table('mobility_pickup_plans')->where('id',$selection->plan_id)->lockForUpdate()->first(); DB::table('mobility_pickup_intents')->where('id',$selection->intent_id)->lockForUpdate()->first(); DB::table('mobility_pickup_previews')->where('id',$selection->preview_id)->lockForUpdate()->first(); DB::table('mobility_pickup_selections')->where('id',$row->selection_id)->lockForUpdate()->first(); DB::table('mobility_private_endpoints')->whereIn('id',[$row->origin_endpoint_id,$row->destination_endpoint_id])->orderBy('id')->lockForUpdate()->get(); }
    private function integrity(object $row): void
    {
        $route = DB::table('mobility_route_quotes')->where('id', $row->route_quote_id)->first();
        $snapshot = (string) ($route->snapshot ?? '');
        if (! $route
            || (int) $route->user_id !== (int) $row->rider_id
            || $route->college_code !== CohortPolicy::college()
            || $snapshot === ''
            || ! hash_equals((string) $row->quote_hash, hash('sha256', $snapshot))) {
            throw new PickupException('QUOTE_INTEGRITY_FAILED', 409);
        }

        if ($row->origin_endpoint_id === $row->destination_endpoint_id) {
            throw new PickupException('QUOTE_INTEGRITY_FAILED', 409);
        }
        $rider = User::find($row->rider_id);
        if (! $rider) {
            throw new PickupException('QUOTE_INTEGRITY_FAILED', 409);
        }
        $origin = $this->endpoints->resolve($rider, $row->origin_endpoint_id);
        $destination = $this->endpoints->resolve($rider, $row->destination_endpoint_id);
        if (! hash_equals((string) $row->origin_endpoint_hash, $origin['hash'])
            || ! hash_equals((string) $row->destination_endpoint_hash, $destination['hash'])) {
            throw new PickupException('QUOTE_INTEGRITY_FAILED', 409);
        }

        $terms = self::decode($snapshot);
        if (($terms['schema'] ?? null) !== 'private-full-journey-quote-v1'
            || ($terms['booking_enabled'] ?? null) !== false
            || ($terms['selection_id'] ?? null) !== $row->selection_id
            || ($terms['origin_endpoint']['id'] ?? null) !== $row->origin_endpoint_id
            || ($terms['destination_endpoint']['id'] ?? null) !== $row->destination_endpoint_id
            || ($terms['origin_endpoint']['hash'] ?? null) !== $row->origin_endpoint_hash
            || ($terms['destination_endpoint']['hash'] ?? null) !== $row->destination_endpoint_hash
            || ($terms['selection_terms_hash'] ?? null) !== $row->source_terms_hash) {
            throw new PickupException('QUOTE_INTEGRITY_FAILED', 409);
        }

        $vehicle = (string) ($terms['vehicle_type'] ?? '');
        $graph = $terms['graph'] ?? [];
        PickupPolicy::graph($vehicle, (string) ($graph['vehicle_data_version'] ?? ''));
        $pricing = $terms['pricing'] ?? [];
        $fresh = DemoFarePolicy::quote(
            $vehicle,
            (string) ($terms['choice'] ?? ''),
            (int) ($terms['riding']['distance_meters'] ?? 0),
        );
        if (self::canonical($fresh) !== self::canonical($pricing)) {
            throw new PickupException('QUOTE_PRICING_STALE', 409);
        }

        $selection = DB::table('mobility_pickup_selections')->where('id', $row->selection_id)->first();
        if (! $selection) {
            throw new PickupException('QUOTE_INTEGRITY_FAILED', 409);
        }
        $preview = DB::table('mobility_pickup_previews')->where('id', $selection->preview_id)->first();
        if (! $preview) {
            throw new PickupException('QUOTE_INTEGRITY_FAILED', 409);
        }
        $preferences = self::decode((string) $preview->snapshot)['preferences'] ?? [];
        $detour = $terms['detour'] ?? [];
        if (! is_int($detour['added_distance_meters'] ?? null)
            || ! is_int($detour['added_duration_seconds'] ?? null)
            || $detour['added_distance_meters'] < 0
            || $detour['added_duration_seconds'] < 0
            || $detour['added_distance_meters'] > (int) ($preferences['max_detour_meters'] ?? -1)
            || $detour['added_duration_seconds'] > (int) ($preferences['max_detour_seconds'] ?? -1)) {
            throw new PickupException('QUOTE_DETOUR_LIMIT_EXCEEDED', 409);
        }
        foreach (['access', 'egress'] as $leg) {
            $path = $terms[$leg]['path'] ?? [];
            PickupPolicy::graph('foot', (string) ($path['data_version'] ?? ''));
        }
    }
    private function result(object $r,bool $existing,int $status): array { $snapshot=json_decode((string)DB::table('mobility_route_quotes')->where('id',$r->route_quote_id)->value('snapshot'),true,512,JSON_THROW_ON_ERROR); return ['status'=>$status,'data'=>['quote'=>['id'=>$r->id,'route_quote_id'=>$r->route_quote_id,'selection_id'=>$r->selection_id,'version'=>(int)$r->version,'state'=>$r->state,'quote_hash'=>$r->quote_hash,'expires_at'=>$r->expires_at,'driver_approved_at'=>$r->driver_approved_at,'rider_consented_at'=>$r->rider_consented_at,'terms'=>$snapshot,'scope'=>'private_participants_only','booking_enabled'=>false],'existing'=>$existing]]; }
    private function expiry(string ...$values): CarbonImmutable { $e=CarbonImmutable::now('UTC')->addMinutes(5); foreach($values as $v) $e=$e->min(CarbonImmutable::parse($v,'UTC')); return $e; }
    private static function fields(array $in,array $fields):void{if(array_diff(array_keys($in),$fields)||array_diff($fields,array_keys($in)))throw new PickupException('INPUT_INVALID');}
    private static function uuid(mixed $v):string{if(!is_string($v)||!Str::isUuid($v))throw new PickupException('INPUT_INVALID');return $v;}
    private static function positive(mixed $v):int{if(!is_int($v)||$v<1)throw new PickupException('INPUT_INVALID');return $v;}
    private static function hash64(mixed $v):string{if(!is_string($v)||!preg_match('/^[a-f0-9]{64}$/D',$v))throw new PickupException('INPUT_INVALID');return $v;}
    private static function key(string $v):string{if(!preg_match('/^[\x21-\x7E]{1,100}$/D',$v))throw new PickupException('INVALID_IDEMPOTENCY_KEY');return hash('sha256',$v);}
    private static function encode(array $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
    private static function decode(string $v):array{return json_decode($v,true,512,JSON_THROW_ON_ERROR);}
    private static function hash(array $v):string{return hash('sha256',self::encode($v));}
    private static function same(string $a,string $b,string $c):void{if(!hash_equals($a,$b))throw new PickupException($c,409);}
    private static function canonical(array $value): string { $sort=function(array $v)use(&$sort):array{if(!array_is_list($v))ksort($v);foreach($v as &$item)if(is_array($item))$item=$sort($item);return $v;}; return self::encode($sort($value)); }
};
