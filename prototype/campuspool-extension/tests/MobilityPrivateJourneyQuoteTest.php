<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Journeys\PrivateJourneyQuoteService;
use STS\Mobility\Pickup\PathProvider;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\User;
use Tests\TestCase;

/** Boundary tests use the repository HTTP harness and database fixtures. */
final class MobilityPrivateJourneyQuoteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('MOBILITY_COHORT_REQUIRED=true'); putenv('MOBILITY_COLLEGE_CODE=CAMPUS-BLR');
        foreach (['car','bike','foot'] as $mode) config(['mobility_routing.'.$mode.'_expected_data_version' => $this->version($mode)]);
        app()->bind(PathProvider::class, fn () => new class implements PathProvider { public function path(array $points, string $mode, float $timeoutSeconds = 5): array { $v=$mode.'|crop_sha256='.str_repeat($mode==='foot'?'c':'a',64).'|profile_sha256='.str_repeat($mode==='foot'?'d':'b',64); $loc=array_map(fn($p)=>[$p['lng'],$p['lat']],$points); $legs=array_fill(0,count($loc)-1,['distance_meters'=>100,'duration_seconds'=>120]); return ['provider'=>'osrm','profile'=>'fixture','data_version'=>$v,'distance_meters'=>100*count($legs),'duration_seconds'=>120*count($legs),'geometry'=>$loc,'waypoints'=>array_map(fn($p)=>['location'=>$p,'distance_meters'=>0],$loc),'legs'=>$legs]; } });
    }

    public function test_successful_prepare_driver_approval_rider_consent_and_exact_replays(): void
    {
        [$host,$rider,$selection,$origin,$destination,$payload,$prepared] = $this->preparedFixture('quote-once');
        $this->actingAsApiUser($rider)->postJson('/api/mobility/private-quotes',$payload,['Idempotency-Key'=>'quote-once'])->assertOk()->assertJsonPath('data.quote.id',$prepared['id']);
        $approve=['expected_version'=>1,'quote_hash'=>$prepared['quote_hash']];
        $approved=$this->actingAsApiUser($host)->postJson('/api/mobility/private-quotes/'.$prepared['id'].'/driver-approve',$approve,['Idempotency-Key'=>'approve-once'])->assertOk()->json('data.quote');
        $this->actingAsApiUser($host)->postJson('/api/mobility/private-quotes/'.$prepared['id'].'/driver-approve',$approve,['Idempotency-Key'=>'approve-once'])->assertOk()->assertJsonPath('data.quote.state','pending_rider');
        $this->actingAsApiUser($rider)->postJson('/api/mobility/private-quotes/'.$prepared['id'].'/rider-consent',['expected_version'=>2,'quote_hash'=>$prepared['quote_hash'],'access_acknowledged'=>true],['Idempotency-Key'=>'consent-once'])->assertOk()->assertJsonPath('data.quote.state','accepted');
        $this->assertSame(1,DB::table('mobility_private_journey_quotes')->where('selection_id',$selection)->count());
        $this->actingAsApiUser($host)->postJson('/api/mobility/private-quotes/'.$prepared['id'].'/revoke',['expected_version'=>3,'quote_hash'=>$prepared['quote_hash']],['Idempotency-Key'=>'revoke-once'])->assertOk()->assertJsonPath('data.quote.state','revoked');
    }

    public function test_pending_reprepare_supersedes_and_accepted_reprepare_requires_canonical_action(): void
    {
        [$host, $rider, $selection, $origin, $destination, $payload, $prepared] = $this->preparedFixture('quote-supersede-one');
        $second = $this->actingAsApiUser($rider)
            ->postJson('/api/mobility/private-quotes', $payload, ['Idempotency-Key' => 'quote-supersede-two'])
            ->assertCreated()
            ->json('data.quote');
        $this->assertSame('pending_driver', $second['state']);
        $this->assertSame('superseded', DB::table('mobility_private_journey_quotes')->where('id', $prepared['id'])->value('state'));

        $this->actingAsApiUser($host)->postJson('/api/mobility/private-quotes/'.$second['id'].'/driver-approve', [
            'expected_version' => 1, 'quote_hash' => $second['quote_hash'],
        ], ['Idempotency-Key' => 'quote-supersede-approve'])->assertOk();
        $this->actingAsApiUser($rider)->postJson('/api/mobility/private-quotes/'.$second['id'].'/rider-consent', [
            'expected_version' => 2, 'quote_hash' => $second['quote_hash'], 'access_acknowledged' => true,
        ], ['Idempotency-Key' => 'quote-supersede-consent'])->assertOk();
        $this->actingAsApiUser($rider)
            ->postJson('/api/mobility/private-quotes', $payload, ['Idempotency-Key' => 'quote-supersede-three'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CANONICAL_ACTION_REQUIRED');
    }

    public function test_tampered_snapshot_and_expired_quote_are_rejected_without_consent(): void
    {
        [$host,$rider,$selection,$origin,$destination,$payload,$prepared] = $this->preparedFixture('quote-tamper');
        $snapshot = json_decode(DB::table('mobility_route_quotes')->where('id',$prepared['route_quote_id'])->value('snapshot'), true);
        $snapshot['pricing']['total_fare_minor']++;
        DB::table('mobility_route_quotes')->where('id',$prepared['route_quote_id'])->update(['snapshot'=>json_encode($snapshot)]);
        $this->actingAsApiUser($rider)->getJson('/api/mobility/private-quotes/'.$prepared['id'])->assertStatus(409);
        DB::table('mobility_route_quotes')->where('id',$prepared['route_quote_id'])->update(['snapshot'=>json_encode($snapshot),'expires_at'=>now()->subSecond()]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/private-quotes/'.$prepared['id'].'/driver-approve',['expected_version'=>1,'quote_hash'=>$prepared['quote_hash']],['Idempotency-Key'=>'tamper-approve'])->assertStatus(409);
    }

    public function test_membership_change_blocks_driver_approval_and_pending_revoke_recovers(): void
    {
        [$host,$rider,$selection,$origin,$destination,$payload,$prepared] = $this->preparedFixture('quote-membership');
        DB::table('mobility_memberships')->where('user_id',$rider->id)->update(['status'=>'suspended']);
        $this->actingAsApiUser($host)->postJson('/api/mobility/private-quotes/'.$prepared['id'].'/driver-approve',['expected_version'=>1,'quote_hash'=>$prepared['quote_hash']],['Idempotency-Key'=>'membership-approve'])->assertStatus(403);
        $this->actingAsApiUser($host)->postJson('/api/mobility/private-quotes/'.$prepared['id'].'/revoke',['expected_version'=>1,'quote_hash'=>$prepared['quote_hash']],['Idempotency-Key'=>'membership-revoke'])->assertOk()->assertJsonPath('data.quote.state','revoked');
    }

    private function preparedFixture(string $key): array
    {
        [$host,$rider,$selection,$origin,$destination] = $this->fixture();
        $payload=['selection_id'=>$selection,'origin_endpoint_id'=>$origin,'destination_endpoint_id'=>$destination,'expected_terms_hash'=>(string)DB::table('mobility_pickup_selections')->where('id',$selection)->value('terms_hash'),'expected_selection_version'=>1];
        $prepared=$this->actingAsApiUser($rider)->postJson('/api/mobility/private-quotes',$payload,['Idempotency-Key'=>$key])->assertCreated()->json('data.quote');
        return [$host,$rider,$selection,$origin,$destination,$payload,$prepared];
    }

    private function fixture(): array
    {
        $member=fn()=>tap(User::factory()->create(['active'=>true,'banned'=>false]),fn($u)=>DB::table('mobility_memberships')->insert(['user_id'=>$u->id,'college_code'=>'CAMPUS-BLR','affiliation'=>'student','status'=>'approved','version'=>1,'created_at'=>now(),'updated_at'=>now()]));
        $host=$member();$host->forceFill(['image'=>'synthetic-profile.png','description'=>'Synthetic private dispatch fixture','nro_doc'=>'DEMO-ONLY','mobile_phone'=>'+910000000000'])->save();$rider=$member();$car=Car::factory()->withCatalog()->create(['user_id'=>$host->id]);DB::table('mobility_host_applications')->insert(['user_id'=>$host->id,'car_id'=>$car->id,'vehicle_type'=>'car','vehicle_fingerprint'=>CohortPolicy::vehicleFingerprint($car),'status'=>'approved','version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $plan=(string)Str::uuid();$intent=(string)Str::uuid();$preview=(string)Str::uuid();$points=[['lat'=>12.90,'lng'=>77.50],['lat'=>13.00,'lng'=>77.60]];$originPoint=['lat'=>12.95,'lng'=>77.55];$route=app(PathProvider::class)->path($points,'car');$route['points']=$points;$vehicleOption=app(PathProvider::class)->path([$points[0],$originPoint,$points[1]],'car');$foot=app(PathProvider::class)->path([$originPoint,['lat'=>12.96,'lng'=>77.57]],'foot');
        DB::table('mobility_pickup_plans')->insert(['id'=>$plan,'user_id'=>$host->id,'college_code'=>'CAMPUS-BLR','car_id'=>$car->id,'vehicle_type'=>'car','ride_mode'=>'on_demand','points'=>json_encode($points),'path_snapshot'=>json_encode($route),'path_data_version'=>$this->version('car'),'version'=>1,'create_key_hash'=>hash('sha256',$plan),'request_hash'=>hash('sha256',$plan),'invite_hash'=>hash('sha256',$plan),'expires_at'=>now()->addMinutes(30),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('mobility_pickup_intents')->insert(['id'=>$intent,'plan_id'=>$plan,'rider_id'=>$rider->id,'driver_id'=>$host->id,'plan_version'=>1,'origin'=>json_encode($originPoint),'max_walk_meters'=>999,'version'=>1,'create_key_hash'=>hash('sha256',$intent),'request_hash'=>hash('sha256',$intent),'expires_at'=>now()->addMinutes(30),'created_at'=>now(),'updated_at'=>now()]);
        $snap=['direct'=>['available'=>true,'choice'=>'DIRECT_RIDER_PICKUP','vehicle_pickup_location'=>[77.55,12.95],'vehicle_snap_meters'=>0,'path'=>$vehicleOption,'detour'=>['added_distance_meters'=>0,'added_duration_seconds'=>0],'access_verified'=>false,'requires_pickup_confirmation'=>true],'meeting'=>['available'=>false],'vehicle_data_version'=>$this->version('car'),'foot_data_version'=>$this->version('foot'),'preferences'=>['max_walk_meters'=>999,'max_detour_meters'=>100000,'max_detour_seconds'=>86400]];
        DB::table('mobility_pickup_previews')->insert(['id'=>$preview,'plan_id'=>$plan,'intent_id'=>$intent,'plan_version'=>1,'intent_version'=>1,'snapshot'=>json_encode($snap),'create_key_hash'=>hash('sha256',$preview),'request_hash'=>hash('sha256',$preview),'expires_at'=>now()->addMinutes(5),'created_at'=>now(),'updated_at'=>now()]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-previews/'.$preview.'/selections',['choice'=>'DIRECT_RIDER_PICKUP','expected_plan_version'=>1,'expected_intent_version'=>1],['Idempotency-Key'=>'select'])->assertCreated();$selection=DB::table('mobility_pickup_selections')->value('id');$terms=DB::table('mobility_pickup_selections')->where('id',$selection)->value('terms_hash');$this->actingAsApiUser($rider)->postJson('/api/mobility/pickup-selections/'.$selection.'/confirm',['expected_version'=>1,'terms_hash'=>$terms,'access_acknowledged'=>true],['Idempotency-Key'=>'confirm'])->assertOk();
        $originId=$this->endpoint($rider,$originPoint,'origin');$destinationId=$this->endpoint($rider,['lat'=>12.96,'lng'=>77.57],'destination');return [$host,$rider,$selection,$originId,$destinationId];
    }
    private function endpoint(User $u,array $p,string $k):string{return $this->actingAsApiUser($u)->postJson('/api/mobility/private-endpoints',$p+['source'=>'map_pin'],['Idempotency-Key'=>$k])->assertCreated()->json('data.id');}
    private function version(string $mode):string{return $mode.'|crop_sha256='.str_repeat($mode==='foot'?'c':'a',64).'|profile_sha256='.str_repeat($mode==='foot'?'d':'b',64);}
    public function test_quote_prepare_requires_authentication_and_never_creates_catalog_or_booking_rows(): void
    {
        $quotes = DB::table('mobility_private_journey_quotes')->count();
        $routes = DB::table('mobility_route_quotes')->count();
        $this->postJson('/api/mobility/private-quotes', [
            'selection_id' => '00000000-0000-0000-0000-000000000001',
            'origin_endpoint_id' => '00000000-0000-0000-0000-000000000002',
            'destination_endpoint_id' => '00000000-0000-0000-0000-000000000003',
            'expected_terms_hash' => str_repeat('0', 64),
            'expected_selection_version' => 1,
        ], ['Idempotency-Key' => 'quote-auth'])->assertUnauthorized();
        $this->assertSame($quotes, DB::table('mobility_private_journey_quotes')->count());
        $this->assertSame($routes, DB::table('mobility_route_quotes')->count());
    }

    public function test_quote_prepare_rejects_unknown_fields_before_database_work(): void
    {
        $user = \STS\Models\User::factory()->create(['active' => true, 'banned' => false]);
        $this->actingAsApiUser($user)->postJson('/api/mobility/private-quotes', ['extra' => true], ['Idempotency-Key' => 'quote-fields'])->assertStatus(422);
        $this->assertSame(0, DB::table('mobility_private_journey_quotes')->count());
    }

    public function test_quote_action_requires_exact_hash_version_and_acknowledgement_shape(): void
    {
        $this->assertSame(0, DB::table('mobility_private_journey_quote_actions')->count());
        $this->postJson('/api/mobility/private-quotes/00000000-0000-0000-0000-000000000001/rider-consent', [
            'expected_version' => 1, 'quote_hash' => str_repeat('0', 64), 'access_acknowledged' => false,
        ], ['Idempotency-Key' => 'quote-ack'])->assertUnauthorized();
        $this->assertSame(0, DB::table('mobility_private_journey_quote_actions')->count());
    }

    public function test_quote_schema_keeps_route_quote_and_sidecar_separate(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('mobility_private_journey_quotes'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('mobility_private_journey_quote_actions'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('mobility_private_journey_quotes', 'active_selection_id'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('mobility_private_journey_quotes', 'quote_hash'));
    }

    public function test_accepted_on_demand_quote_uses_only_selected_private_driver_and_canonical_passenger(): void
    {
        [$host, $rider, $selection, $origin, $destination] = $this->fixture();
        $payload = ['selection_id' => $selection, 'origin_endpoint_id' => $origin, 'destination_endpoint_id' => $destination,
            'expected_terms_hash' => (string) DB::table('mobility_pickup_selections')->where('id', $selection)->value('terms_hash'), 'expected_selection_version' => (int) DB::table('mobility_pickup_selections')->where('id', $selection)->value('version')];
        $quote = $this->actingAsApiUser($rider)->postJson('/api/mobility/private-quotes', $payload, ['Idempotency-Key' => 'dispatch-private-quote'])->assertCreated()->json('data.quote');
        $this->actingAsApiUser($host)->postJson('/api/mobility/private-quotes/'.$quote['id'].'/driver-approve', ['expected_version' => 1, 'quote_hash' => $quote['quote_hash']], ['Idempotency-Key' => 'dispatch-private-approve'])->assertOk();
        $accepted = $this->actingAsApiUser($rider)->postJson('/api/mobility/private-quotes/'.$quote['id'].'/rider-consent', ['expected_version' => 2, 'quote_hash' => $quote['quote_hash'], 'access_acknowledged' => true], ['Idempotency-Key' => 'dispatch-private-consent'])->assertOk();
        $car = DB::table('mobility_pickup_plans')->where('id', DB::table('mobility_pickup_selections')->where('id', $selection)->value('plan_id'))->value('car_id');
        $this->actingAsApiUser($host)->putJson('/api/mobility/dispatch/availability', ['online' => true, 'scope' => 'private', 'car_id' => $car])->assertOk();
        $request = $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $quote['route_quote_id']], ['Idempotency-Key' => 'dispatch-private-request'])->assertCreated()->json('id');
        app(\STS\Mobility\Dispatch\DispatchWorker::class)->process();
        $offer = DB::table('mobility_dispatch_offers')->where('request_id', $request)->first();
        $this->assertNotNull($offer); $this->assertSame($host->id, (int) $offer->driver_id);
        $shownOffer = $this->actingAsApiUser($host)->getJson('/api/mobility/dispatch/offers')->assertOk()->json('data.0');
        $this->assertCount(2,$shownOffer['points']);
        $this->assertSame($quote['terms']['pricing']['total_fare_minor'],$shownOffer['fare_minor']);
        $this->assertSame($quote['terms']['pricing'],$shownOffer['pricing']);
        $this->assertSame($quote['terms']['total_walking_meters'],$shownOffer['walking']['total_meters']);
        $result = $this->actingAsApiUser($host)->postJson('/api/mobility/dispatch/offers/'.$offer->id.'/accept', [], ['Idempotency-Key' => 'dispatch-private-accept'])->assertOk()->json();
        $this->assertDatabaseHas('mobility_dispatch_requests', ['id' => $request, 'dispatch_scope' => 'private', 'pickup_point_id' => null, 'target_driver_id' => $host->id]);
        $this->assertDatabaseHas('trip_passengers', ['id' => $result['booking_id'], 'trip_id' => $result['trip_id'], 'user_id' => $rider->id]);
        $this->assertSame(0, DB::table('mobility_dispatch_offers')->where('request_id', $request)->where('driver_id', '<>', $host->id)->count());
        $shown = $this->actingAsApiUser($rider)->getJson('/api/mobility/dispatch/requests/'.$request)->assertOk()->json();
        $this->assertSame($quote['terms']['pricing']['total_fare_minor'],$shown['fare_minor']);
        $this->assertCount(2,$shown['points']);
        $this->actingAsApiUser($host)->getJson('/api/mobility/dispatch/requests')->assertOk()->assertJsonPath('data.0.id',$request);
        $outsider = User::factory()->create(['active'=>true,'banned'=>false]);
        $this->actingAsApiUser($outsider)->getJson('/api/mobility/dispatch/requests')->assertOk()->assertJsonCount(0,'data');
        $this->actingAsApiUser($outsider)->getJson('/api/trips/'.$result['trip_id'])->assertForbidden();
        $this->travel(7)->minutes();
        $this->actingAsApiUser($host)->postJson('/api/mobility/trips/'.$result['trip_id'].'/transitions',['action'=>'start'],['Idempotency-Key'=>'private-expired-source-start'])->assertOk();
        $this->travelBack();
    }
}
