<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use STS\Models\Car;
use STS\Models\User;
use STS\Mobility\Journeys\PrivateScheduledAdmissionService;
use STS\Mobility\Pickup\PathProvider;
use STS\Mobility\Trust\CohortPolicy;
use Tests\TestCase;

/** Synthetic same-road regression for scheduled interval admission. */
final class MobilityPrivateScheduledIntervalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('MOBILITY_COHORT_REQUIRED=true'); putenv('MOBILITY_COLLEGE_CODE=CAMPUS-BLR');
        foreach (['car', 'bike', 'foot'] as $mode) config(['mobility_routing.'.$mode.'_expected_data_version' => $this->version($mode)]);
        app()->bind(PathProvider::class, fn () => new class implements PathProvider {
            public function path(array $points, string $mode, float $timeoutSeconds = 5): array
            {
                $version = $mode.'|crop_sha256='.str_repeat($mode === 'foot' ? 'c' : 'a', 64).'|profile_sha256='.str_repeat($mode === 'foot' ? 'd' : 'b', 64);
                $coords = array_map(fn ($p) => [$p['lng'], $p['lat']], $points); $legs = [];
                foreach (array_slice($coords, 1) as $i => $to) { $from = $coords[$i]; $d = (int) round(hypot(($to[0] - $from[0]) * 100000, ($to[1] - $from[1]) * 111000)); $legs[] = ['distance_meters' => max(1, $d), 'duration_seconds' => max(1, (int) round($d / 10))]; }
                return ['provider' => 'osrm', 'profile' => 'fixture', 'data_version' => $version, 'distance_meters' => array_sum(array_column($legs, 'distance_meters')), 'duration_seconds' => array_sum(array_column($legs, 'duration_seconds')), 'geometry' => $coords, 'waypoints' => array_map(fn ($p) => ['location' => [$p['lng'], $p['lat']], 'distance_meters' => 0], $points), 'legs' => $legs];
            }
        });
    }

    public function test_disjoint_intervals_with_one_seat_coexist_and_fares_remain_per_rider(): void
    {
        [$driver, $first, $car, $firstQuote] = $this->quote('interval-first', 77.52, 77.55);
        [, $second,, $secondQuote] = $this->quote('interval-second', 77.56, 77.58, $driver, $car);
        $service = app(PrivateScheduledAdmissionService::class); $trip = $service->publish($driver, $firstQuote, 'interval-publish', now()->addHour(), 1);
        $a = $this->request($service, $trip['trip_id'], $first, $firstQuote, 'interval-a'); $b = $this->request($service, $trip['trip_id'], $second, $secondQuote, 'interval-b');
        $this->assertNotSame($a['interval_hash'], $b['interval_hash']); $this->assertNotSame($a['fare_minor'], $b['fare_minor']);
        $service->accept($trip['trip_id'], $first->id); $service->accept($trip['trip_id'], $second->id);
        $this->assertSame(2, DB::table('trip_passengers')->where('trip_id', $trip['trip_id'])->where('request_state', 1)->count());
    }

    public function test_overlap_full_interval_is_rejected_but_cancel_releases_capacity(): void
    {
        [$driver, $first, $car, $quote] = $this->quote('interval-overlap-a', 77.52, 77.57); [, $second,, $quote2] = $this->quote('interval-overlap-b', 77.53, 77.56, $driver, $car);
        $service = app(PrivateScheduledAdmissionService::class); $trip = $service->publish($driver, $quote, 'interval-overlap-publish', now()->addHour(), 1);
        $this->request($service, $trip['trip_id'], $first, $quote, 'overlap-a'); $this->request($service, $trip['trip_id'], $second, $quote2, 'overlap-b'); $service->accept($trip['trip_id'], $first->id);
        try { $service->accept($trip['trip_id'], $second->id); self::fail('overlap accepted'); } catch (\Throwable $e) { self::assertSame('NOT_SEAT_AVAILABLE', $e->getMessage()); }
        $this->actingAsApiUser($first)->postJson('/api/mobility/trips/'.$trip['trip_id'].'/private-scheduled-bookings/'.$first->id.'/cancel', [], ['Idempotency-Key' => 'overlap-cancel'])->assertOk();
        $service->accept($trip['trip_id'], $second->id); self::assertSame(1, DB::table('trip_passengers')->where('trip_id', $trip['trip_id'])->where('request_state', 1)->count());
    }

    public function test_request_requires_interval_hash_and_changed_consent_is_idempotency_conflict(): void
    {
        [$driver, $rider, $car, $quote] = $this->quote('interval-hash', 77.52, 77.56); $service = app(PrivateScheduledAdmissionService::class); $trip = $service->publish($driver, $quote, 'interval-hash-publish', now()->addHour(), 1); $card = $service->discover($rider, $quote)[0];
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$trip['trip_id'].'/requests', ['quote_id'=>$quote], ['Idempotency-Key'=>'legacy-request'])->assertStatus(409)->assertJsonPath('error.code','PRIVATE_SCHEDULE_CARD_CONSENT_REQUIRED');
        $this->actingAsApiUser($rider)->postJson('/api/mobility/trips/'.$trip['trip_id'].'/private-scheduled-request', ['quote_id' => $quote, 'expected_quote_hash' => $card['quote_hash'], 'expected_departure_at' => $card['departure_at']], ['Idempotency-Key' => 'missing-interval'])->assertStatus(422);
        $payload = ['quote_id' => $quote, 'expected_quote_hash' => $card['quote_hash'], 'expected_departure_at' => $card['departure_at'], 'expected_interval_hash' => $card['interval_hash']]; $path = '/api/mobility/trips/'.$trip['trip_id'].'/private-scheduled-request';
        $this->actingAsApiUser($rider)->postJson($path, $payload, ['Idempotency-Key' => 'changed-interval'])->assertCreated(); $payload['expected_interval_hash'] = str_repeat('0', 64);
        $this->actingAsApiUser($rider)->postJson($path, $payload, ['Idempotency-Key' => 'changed-interval'])->assertStatus(409);
    }

    public function test_binding_tamper_blocks_acceptance_and_binding_stores_interval_hash(): void
    {
        [$driver, $rider, $car, $quote] = $this->quote('interval-tamper', 77.52, 77.56); $service = app(PrivateScheduledAdmissionService::class); $trip = $service->publish($driver, $quote, 'interval-tamper-publish', now()->addHour(), 1); $this->request($service, $trip['trip_id'], $rider, $quote, 'tamper-request');
        $binding = DB::table('mobility_private_scheduled_bindings')->where('trip_id', $trip['trip_id'])->first(); self::assertNotNull($binding); self::assertNotNull($binding->interval_hash); self::assertNotNull($binding->interval_snapshot);
        DB::table('mobility_private_scheduled_bindings')->where('booking_id', $binding->booking_id)->update(['interval_hash' => str_repeat('0', 64)]);
        try { $service->accept($trip['trip_id'], $rider->id); self::fail('tampered binding accepted'); } catch (\Throwable $e) { self::assertSame('PRIVATE_CONSENT_CHANGED', $e->getMessage()); }
    }

    public function test_accepted_intervals_validate_at_start_and_physical_boarding_never_exceeds_one_seat(): void
    {
        [$driver,$first,$car,$quote] = $this->quote('start-interval-a',77.52,77.54);
        [, $second,, $quote2] = $this->quote('start-interval-b',77.56,77.59,$driver,$car);
        $service = app(PrivateScheduledAdmissionService::class);
        $trip = $service->publish($driver,$quote,'start-interval-publish',now()->addHour(),1);
        $a=$this->request($service,$trip['trip_id'],$first,$quote,'start-a');
        $b=$this->request($service,$trip['trip_id'],$second,$quote2,'start-b');
        $service->accept($trip['trip_id'],$first->id);$service->accept($trip['trip_id'],$second->id);
        DB::table('mobility_route_quotes')->whereIn('id',[$quote,$quote2])->update(['expires_at'=>now('UTC')->subMinute()]);
        $path='/api/mobility/trips/'.$trip['trip_id'].'/transitions';
        $original = DB::table('mobility_private_scheduled_bindings')->where('booking_id',$a['booking_id'])->value('interval_hash');
        DB::table('mobility_private_scheduled_bindings')->where('booking_id',$a['booking_id'])->update(['interval_hash'=>str_repeat('0',64)]);
        $this->actingAsApiUser($driver)->postJson($path,['action'=>'start'],['Idempotency-Key'=>'start-invalid-interval'])->assertStatus(409)->assertJsonPath('error.code','PRIVATE_CONSENT_CHANGED');
        DB::table('mobility_private_scheduled_bindings')->where('booking_id',$a['booking_id'])->update(['interval_hash'=>$original]);
        $this->actingAsApiUser($driver)->postJson($path,['action'=>'start'],['Idempotency-Key'=>'start-invalid-interval'])->assertOk();
        $this->actingAsApiUser($driver)->postJson($path,['action'=>'board','passenger_id'=>$first->id],['Idempotency-Key'=>'board-first'])->assertOk();
        $this->actingAsApiUser($driver)->postJson($path,['action'=>'board','passenger_id'=>$second->id],['Idempotency-Key'=>'board-second'])->assertStatus(409)->assertJsonPath('error.code','BOARDING_CAPACITY_EXCEEDED');
        $this->actingAsApiUser($driver)->postJson($path,['action'=>'alight','passenger_id'=>$first->id],['Idempotency-Key'=>'alight-first'])->assertOk();
        $this->actingAsApiUser($driver)->postJson($path,['action'=>'board','passenger_id'=>$second->id],['Idempotency-Key'=>'board-second'])->assertOk();
        $this->actingAsApiUser($driver)->postJson($path,['action'=>'alight','passenger_id'=>$second->id],['Idempotency-Key'=>'alight-second'])->assertOk();
        $this->actingAsApiUser($driver)->postJson($path,['action'=>'complete'],['Idempotency-Key'=>'interval-complete'])->assertOk();
    }

    public function test_pending_legacy_requires_fresh_consent_and_accepted_legacy_preserves_full_route_obligation(): void
    {
        [$driver,$rider,$car,$quote]=$this->quote('legacy-interval',77.52,77.56);
        $service=app(PrivateScheduledAdmissionService::class);$trip=$service->publish($driver,$quote,'legacy-interval-publish',now()->addHour(),1);
        $request=$this->request($service,$trip['trip_id'],$rider,$quote,'legacy-interval-request');
        $binding=DB::table('mobility_private_scheduled_bindings')->where('booking_id',$request['booking_id'])->first();
        DB::table('mobility_private_scheduled_bindings')->where('booking_id',$binding->booking_id)->update(['interval_snapshot'=>null,'interval_hash'=>null]);
        $this->actingAsApiUser($driver)->postJson('/api/mobility/trips/'.$trip['trip_id'].'/private-scheduled-bookings/'.$rider->id.'/accept',[],['Idempotency-Key'=>'legacy-accept'])->assertStatus(409)->assertJsonPath('error.code','PRIVATE_SCHEDULE_CONSENT_REFRESH_REQUIRED');
        $this->request($service,$trip['trip_id'],$rider,$quote,'legacy-fresh-card-consent');
        $this->assertDatabaseHas('mobility_private_scheduled_bindings',['booking_id'=>$binding->booking_id,'interval_hash'=>$binding->interval_hash]);
        $service->accept($trip['trip_id'],$rider->id);
        DB::table('mobility_private_scheduled_bindings')->where('booking_id',$binding->booking_id)->update(['interval_snapshot'=>null,'interval_hash'=>null]);
        DB::table('mobility_route_quotes')->where('id',$quote)->update(['expires_at'=>now('UTC')->subMinute()]);
        $this->actingAsApiUser($driver)->postJson('/api/mobility/trips/'.$trip['trip_id'].'/transitions',['action'=>'start'],['Idempotency-Key'=>'legacy-start'])->assertOk();
    }

    private function request(PrivateScheduledAdmissionService $service, int $trip, User $rider, string $quote, string $key): array { $card = collect($service->discover($rider, $quote))->firstWhere('trip_id', $trip); $response = $service->request($trip, $rider, $quote, $key, $card['quote_hash'], $card['departure_at'], $card['interval_hash']); $binding = DB::table('mobility_private_scheduled_bindings')->where('booking_id', $response['booking_id'])->first(); return $response + ['interval_hash' => $binding->interval_hash, 'fare_minor' => (int) $binding->fare_minor]; }

    private function quote(string $key, float $pickup, float $dropoff, ?User $driver = null, ?Car $car = null): array
    {
        $driver ??= $this->member(); $rider = $this->member(); $driver->forceFill(['image' => 'synthetic-profile.png', 'description' => 'Synthetic fixture', 'nro_doc' => 'DEMO-ONLY', 'mobile_phone' => '+910000000000'])->save(); $car ??= Car::factory()->withCatalog()->create(['user_id' => $driver->id]);
        DB::table('mobility_host_applications')->updateOrInsert(['user_id' => $driver->id, 'car_id' => $car->id], ['user_id' => $driver->id, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $plan = (string) Str::uuid(); $intent = (string) Str::uuid(); $preview = (string) Str::uuid(); $points = [['lat' => 12.90, 'lng' => 77.50], ['lat' => 12.90, 'lng' => 77.60]]; $origin = ['lat' => 12.90, 'lng' => $pickup]; $destination = ['lat' => 12.90, 'lng' => $dropoff]; $route = app(PathProvider::class)->path($points, 'car') + ['points' => $points]; $option = app(PathProvider::class)->path([$points[0], $origin, $points[1]], 'car');
        DB::table('mobility_pickup_plans')->insert(['id'=>$plan,'user_id'=>$driver->id,'college_code'=>'CAMPUS-BLR','car_id'=>$car->id,'vehicle_type'=>'car','ride_mode'=>'scheduled','points'=>json_encode($points),'path_snapshot'=>json_encode($route),'path_data_version'=>$this->version('car'),'version'=>1,'create_key_hash'=>hash('sha256',$plan),'request_hash'=>hash('sha256',$plan),'invite_hash'=>hash('sha256',$plan),'expires_at'=>now()->addMinutes(30),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('mobility_pickup_intents')->insert(['id'=>$intent,'plan_id'=>$plan,'rider_id'=>$rider->id,'driver_id'=>$driver->id,'plan_version'=>1,'origin'=>json_encode($origin),'max_walk_meters'=>999,'version'=>1,'create_key_hash'=>hash('sha256',$intent),'request_hash'=>hash('sha256',$intent),'expires_at'=>now()->addMinutes(30),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('mobility_pickup_previews')->insert(['id'=>$preview,'plan_id'=>$plan,'intent_id'=>$intent,'plan_version'=>1,'intent_version'=>1,'snapshot'=>json_encode(['direct'=>['available'=>true,'choice'=>'DIRECT_RIDER_PICKUP','vehicle_pickup_location'=>[$pickup,12.90],'vehicle_snap_meters'=>0,'path'=>$option,'detour'=>['added_distance_meters'=>0,'added_duration_seconds'=>0],'access_verified'=>false,'requires_pickup_confirmation'=>true],'meeting'=>['available'=>false],'vehicle_data_version'=>$this->version('car'),'foot_data_version'=>$this->version('foot'),'preferences'=>['max_walk_meters'=>999,'max_detour_meters'=>100000,'max_detour_seconds'=>86400]]),'create_key_hash'=>hash('sha256',$preview),'request_hash'=>hash('sha256',$preview),'expires_at'=>now()->addMinutes(5),'created_at'=>now(),'updated_at'=>now()]);
        $this->actingAsApiUser($driver)->postJson('/api/mobility/pickup-previews/'.$preview.'/selections',['choice'=>'DIRECT_RIDER_PICKUP','expected_plan_version'=>1,'expected_intent_version'=>1],['Idempotency-Key'=>$key.'-select'])->assertCreated(); $selection = DB::table('mobility_pickup_selections')->where('preview_id',$preview)->value('id'); $terms = DB::table('mobility_pickup_selections')->where('id',$selection)->value('terms_hash'); $this->actingAsApiUser($rider)->postJson('/api/mobility/pickup-selections/'.$selection.'/confirm',['expected_version'=>1,'terms_hash'=>$terms,'access_acknowledged'=>true],['Idempotency-Key'=>$key.'-confirm'])->assertOk();
        $originId = $this->endpoint($rider,$origin,$key.'-origin'); $destinationId = $this->endpoint($rider,$destination,$key.'-destination'); $quote = $this->actingAsApiUser($rider)->postJson('/api/mobility/private-quotes',['selection_id'=>$selection,'origin_endpoint_id'=>$originId,'destination_endpoint_id'=>$destinationId,'expected_terms_hash'=>$terms,'expected_selection_version'=>1],['Idempotency-Key'=>$key.'-quote'])->assertCreated()->json('data.quote'); $this->actingAsApiUser($driver)->postJson('/api/mobility/private-quotes/'.$quote['id'].'/driver-approve',['expected_version'=>1,'quote_hash'=>$quote['quote_hash']],['Idempotency-Key'=>$key.'-approve'])->assertOk(); $this->actingAsApiUser($rider)->postJson('/api/mobility/private-quotes/'.$quote['id'].'/rider-consent',['expected_version'=>2,'quote_hash'=>$quote['quote_hash'],'access_acknowledged'=>true],['Idempotency-Key'=>$key.'-consent'])->assertOk();
        return [$driver,$rider,$car,$quote['route_quote_id']];
    }

    private function member(): User { $user = User::factory()->create(['active'=>true,'banned'=>false]); DB::table('mobility_memberships')->insert(['user_id'=>$user->id,'college_code'=>'CAMPUS-BLR','affiliation'=>'student','status'=>'approved','version'=>1,'created_at'=>now(),'updated_at'=>now()]); return $user; }
    private function endpoint(User $user, array $point, string $key): string { return $this->actingAsApiUser($user)->postJson('/api/mobility/private-endpoints',$point+['source'=>'map_pin'],['Idempotency-Key'=>$key])->assertCreated()->json('data.id'); }
    private function version(string $mode): string { return $mode.'|crop_sha256='.str_repeat($mode==='foot'?'c':'a',64).'|profile_sha256='.str_repeat($mode==='foot'?'d':'b',64); }
}
