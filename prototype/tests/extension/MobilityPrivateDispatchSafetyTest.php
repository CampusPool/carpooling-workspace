<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Models\Car;
use STS\Models\User;
use STS\Mobility\Dispatch\DispatchWorker;
use STS\Mobility\Pickup\PathProvider;
use STS\Mobility\Trust\CohortPolicy;
use Tests\TestCase;

/** Focused private dispatch safety packet. External provider/device proof is separate. */
final class MobilityPrivateDispatchSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('MOBILITY_COHORT_REQUIRED=true'); putenv('MOBILITY_COLLEGE_CODE=CAMPUS-BLR');
        foreach (['car', 'bike', 'foot'] as $mode) config(['mobility_routing.'.$mode.'_expected_data_version' => $mode.'|crop_sha256='.str_repeat($mode === 'foot' ? 'c' : 'a', 64).'|profile_sha256='.str_repeat($mode === 'foot' ? 'd' : 'b', 64)]);
        app()->bind(PathProvider::class, fn () => new class implements PathProvider {
            public function path(array $points, string $mode, float $timeoutSeconds = 5): array
            { $v = $mode.'|crop_sha256='.str_repeat($mode === 'foot' ? 'c' : 'a', 64).'|profile_sha256='.str_repeat($mode === 'foot' ? 'd' : 'b', 64); $legs = array_fill(0, count($points) - 1, ['distance_meters' => 100, 'duration_seconds' => 120]); return ['provider' => 'osrm', 'profile' => 'fixture', 'data_version' => $v, 'distance_meters' => 100 * count($legs), 'duration_seconds' => 120 * count($legs), 'geometry' => array_map(fn ($p) => [$p['lng'], $p['lat']], $points), 'waypoints' => array_map(fn ($p) => ['location' => [$p['lng'], $p['lat']], 'distance_meters' => 0], $points), 'legs' => $legs]; }
        });
    }

    public function test_private_request_targets_only_quote_driver_and_creates_canonical_passenger(): void
    {
        [$host, $rider, $car, $routeQuote] = $this->acceptedQuote('dispatch-safety-accept');
        $other = $this->member(); $otherCar = Car::factory()->withCatalog()->create(['user_id' => $other->id]);
        DB::table('mobility_host_applications')->insert(['user_id' => $other->id, 'car_id' => $otherCar->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($otherCar), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsApiUser($host)->putJson('/api/mobility/dispatch/availability', ['online' => true, 'scope' => 'private', 'car_id' => $car->id])->assertOk();
        $this->actingAsApiUser($other)->putJson('/api/mobility/dispatch/availability', ['online' => true, 'scope' => 'private', 'car_id' => $otherCar->id])->assertOk();
        $request = $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $routeQuote], ['Idempotency-Key' => 'dispatch-safety-request'])->assertCreated()->json('id');
        app(DispatchWorker::class)->process();
        $offers = DB::table('mobility_dispatch_offers')->where('request_id', $request)->get();
        $this->assertCount(1, $offers); $this->assertSame($host->id, (int) $offers[0]->driver_id);
        $result = $this->actingAsApiUser($host)->postJson('/api/mobility/dispatch/offers/'.$offers[0]->id.'/accept', [], ['Idempotency-Key' => 'dispatch-safety-accept'])->assertOk()->json();
        $this->assertDatabaseHas('trip_passengers', ['id' => $result['booking_id'], 'trip_id' => $result['trip_id'], 'user_id' => $rider->id]);
    }

    public function test_stale_private_source_fails_before_request_claim(): void
    {
        [$host, $rider, $car, $routeQuote, $selection] = $this->acceptedQuote('dispatch-safety-stale');
        DB::table('mobility_pickup_plans')->where('id', DB::table('mobility_pickup_selections')->where('id', $selection)->value('plan_id'))->update(['version' => 2]);
        $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $routeQuote], ['Idempotency-Key' => 'dispatch-safety-stale-request'])->assertStatus(409);
        $this->assertDatabaseCount('mobility_dispatch_requests', 0);
    }

    public function test_endpoint_hash_tamper_fails_before_private_request_claim(): void
    {
        [$host, $rider, $car, $routeQuote] = $this->acceptedQuote('dispatch-safety-endpoint');
        $endpoint = DB::table('mobility_private_journey_quotes')->where('route_quote_id', $routeQuote)->value('origin_endpoint_id');
        DB::table('mobility_private_endpoints')->where('id', $endpoint)->update(['lat' => 12.9512345]);
        $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $routeQuote], ['Idempotency-Key' => 'dispatch-safety-endpoint-request'])->assertStatus(409);
        $this->assertDatabaseCount('mobility_dispatch_requests', 0);
    }

    public function test_accepted_quote_pricing_tamper_fails_before_private_request_claim(): void
    {
        [$host, $rider, $car, $routeQuote] = $this->acceptedQuote('dispatch-safety-pricing');
        $snapshot = json_decode(DB::table('mobility_route_quotes')->where('id', $routeQuote)->value('snapshot'), true, 512, JSON_THROW_ON_ERROR);
        $snapshot['pricing']['total_fare_minor'] = (int) $snapshot['pricing']['total_fare_minor'] + 1;
        DB::table('mobility_route_quotes')->where('id', $routeQuote)->update(['snapshot' => json_encode($snapshot)]);
        $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $routeQuote], ['Idempotency-Key' => 'dispatch-safety-pricing-request'])->assertStatus(409);
        $this->assertDatabaseCount('mobility_dispatch_requests', 0);
    }

    public function test_worker_cancels_stale_private_source_without_poisoning_its_watch_loop(): void
    {
        [$host,$rider,$car,$routeQuote,$selection] = $this->acceptedQuote('worker-stale');
        $this->actingAsApiUser($host)->putJson('/api/mobility/dispatch/availability',['online'=>true,'scope'=>'private','car_id'=>$car->id])->assertOk();
        $request=$this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests',['quote_id'=>$routeQuote],['Idempotency-Key'=>'worker-stale-request'])->assertCreated()->json('id');
        $plan=DB::table('mobility_pickup_selections')->where('id',$selection)->value('plan_id');
        DB::table('mobility_pickup_plans')->where('id',$plan)->update(['version'=>2]);
        app(DispatchWorker::class)->process();
        $this->assertDatabaseHas('mobility_dispatch_requests',['id'=>$request,'state'=>'cancelled','active_rider_id'=>null]);
        $this->assertDatabaseCount('mobility_dispatch_offers',0);
        $this->assertDatabaseCount('mobility_dispatch_assignments',0);
        app(DispatchWorker::class)->process();
    }

    public function test_pair_block_fences_new_private_admission_without_creating_a_canonical_trip(): void
    {
        [$host, $rider, $car, $routeQuote] = $this->acceptedQuote('blocked-admission');
        DB::table('mobility_user_blocks')->insert(['actor_id' => $host->id, 'target_id' => $rider->id, 'blocked' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsApiUser($rider)->postJson('/api/mobility/dispatch/requests', ['quote_id' => $routeQuote], ['Idempotency-Key' => 'blocked-admission'])->assertStatus(403)->assertJsonPath('error.code', 'PARTICIPANT_BLOCKED');
        $this->assertDatabaseCount('mobility_dispatch_requests', 0);
        $this->assertDatabaseCount('mobility_dispatch_assignments', 0);
    }

    private function acceptedQuote(string $key, ?User $host = null, ?Car $car = null): array
    {
        $host ??= $this->member(); $host->forceFill(['image' => 'synthetic-profile.png', 'description' => 'Synthetic fixture', 'nro_doc' => 'DEMO-ONLY', 'mobile_phone' => '+910000000000'])->save();
        $rider = $this->member(); $car ??= Car::factory()->withCatalog()->create(['user_id' => $host->id]);
        DB::table('mobility_host_applications')->updateOrInsert(['user_id'=>$host->id,'car_id'=>$car->id], ['user_id' => $host->id, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $plan = (string) Str::uuid(); $rideMode = str_starts_with($key, 'scheduled') ? 'scheduled' : 'on_demand'; $intent = (string) Str::uuid(); $preview = (string) Str::uuid(); $points = [['lat' => 12.90, 'lng' => 77.50], ['lat' => 13.00, 'lng' => 77.60]]; $originPoint = ['lat' => 12.95, 'lng' => 77.55]; $route = app(PathProvider::class)->path($points, 'car'); $route['points'] = $points; $vehicleOption = app(PathProvider::class)->path([$points[0], $originPoint, $points[1]], 'car');
        DB::table('mobility_pickup_plans')->insert(['id' => $plan, 'user_id' => $host->id, 'college_code' => 'CAMPUS-BLR', 'car_id' => $car->id, 'vehicle_type' => 'car', 'ride_mode' => $rideMode, 'points' => json_encode($points), 'path_snapshot' => json_encode($route), 'path_data_version' => $this->version('car'), 'version' => 1, 'create_key_hash' => hash('sha256', $plan), 'request_hash' => hash('sha256', $plan), 'invite_hash' => hash('sha256', $plan), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mobility_pickup_intents')->insert(['id' => $intent, 'plan_id' => $plan, 'rider_id' => $rider->id, 'driver_id' => $host->id, 'plan_version' => 1, 'origin' => json_encode($originPoint), 'max_walk_meters' => 999, 'version' => 1, 'create_key_hash' => hash('sha256', $intent), 'request_hash' => hash('sha256', $intent), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mobility_pickup_previews')->insert(['id' => $preview, 'plan_id' => $plan, 'intent_id' => $intent, 'plan_version' => 1, 'intent_version' => 1, 'snapshot' => json_encode(['direct' => ['available' => true, 'choice' => 'DIRECT_RIDER_PICKUP', 'vehicle_pickup_location' => [77.55, 12.95], 'vehicle_snap_meters' => 0, 'path' => $vehicleOption, 'detour' => ['added_distance_meters' => 0, 'added_duration_seconds' => 0], 'access_verified' => false, 'requires_pickup_confirmation' => true], 'meeting' => ['available' => false], 'vehicle_data_version' => $this->version('car'), 'foot_data_version' => $this->version('foot'), 'preferences' => ['max_walk_meters' => 999, 'max_detour_meters' => 100000, 'max_detour_seconds' => 86400]]), 'create_key_hash' => hash('sha256', $preview), 'request_hash' => hash('sha256', $preview), 'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pickup-previews/'.$preview.'/selections', ['choice' => 'DIRECT_RIDER_PICKUP', 'expected_plan_version' => 1, 'expected_intent_version' => 1], ['Idempotency-Key' => $key.'-select'])->assertCreated(); $selection = DB::table('mobility_pickup_selections')->where('preview_id',$preview)->value('id'); $terms = DB::table('mobility_pickup_selections')->where('id', $selection)->value('terms_hash'); $this->actingAsApiUser($rider)->postJson('/api/mobility/pickup-selections/'.$selection.'/confirm', ['expected_version' => 1, 'terms_hash' => $terms, 'access_acknowledged' => true], ['Idempotency-Key' => $key.'-confirm'])->assertOk();
        $originId = $this->endpoint($rider, $originPoint, 'dispatch-safety-origin'); $destinationId = $this->endpoint($rider, ['lat' => 12.96, 'lng' => 77.57], 'dispatch-safety-destination'); $payload = ['selection_id' => $selection, 'origin_endpoint_id' => $originId, 'destination_endpoint_id' => $destinationId, 'expected_terms_hash' => $terms, 'expected_selection_version' => 1];
        $quote = $this->actingAsApiUser($rider)->postJson('/api/mobility/private-quotes', $payload, ['Idempotency-Key' => $key])->assertCreated()->json('data.quote'); $this->actingAsApiUser($host)->postJson('/api/mobility/private-quotes/'.$quote['id'].'/driver-approve', ['expected_version' => 1, 'quote_hash' => $quote['quote_hash']], ['Idempotency-Key' => $key.'-approve'])->assertOk(); $this->actingAsApiUser($rider)->postJson('/api/mobility/private-quotes/'.$quote['id'].'/rider-consent', ['expected_version' => 2, 'quote_hash' => $quote['quote_hash'], 'access_acknowledged' => true], ['Idempotency-Key' => $key.'-consent'])->assertOk();
        return [$host, $rider, $car, $quote['route_quote_id'], $selection];
    }

    private function member(): User { $u = User::factory()->create(['active' => true, 'banned' => false]); DB::table('mobility_memberships')->insert(['user_id' => $u->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]); return $u; }
    private function endpoint(User $u, array $point, string $key): string { return $this->actingAsApiUser($u)->postJson('/api/mobility/private-endpoints', $point + ['source' => 'map_pin'], ['Idempotency-Key' => $key])->assertCreated()->json('data.id'); }
    private function version(string $mode): string { return $mode.'|crop_sha256='.str_repeat($mode === 'foot' ? 'c' : 'a', 64).'|profile_sha256='.str_repeat($mode === 'foot' ? 'd' : 'b', 64); }
    public function test_accepted_private_scheduled_quote_publishes_an_immutable_four_waypoint_canonical_trip(): void
    {
        [$host, $rider, $car, $routeQuote] = $this->acceptedQuote('scheduled-publication');
        $beforeNodes = DB::table('nodes_geo')->count();
        $published = app(\STS\Mobility\Journeys\PrivateScheduledAdmissionService::class)->publish($host, $routeQuote, 'scheduled-publication-key', now('Asia/Kolkata')->addHour(), 2);
        $this->assertSame(4, DB::table('trips_points')->where('trip_id', $published['trip_id'])->count());
        $this->assertDatabaseHas('mobility_private_scheduled_publications', ['trip_id' => $published['trip_id'], 'total_seats' => 2]);
        $this->assertSame($beforeNodes, DB::table('nodes_geo')->count());
        $this->assertDatabaseHas('mobility_trip_lifecycles', ['trip_id' => $published['trip_id'], 'state' => 'planned']);
        $request = $this->requestScheduled((int)$published['trip_id'], $rider, $routeQuote, 'scheduled-rider-request');
        $this->assertSame(0, $request['request_state']);
        $this->actingAsApiUser($rider)->getJson('/api/trips/'.$published['trip_id'])->assertForbidden();
        $dto = (new \STS\Transformers\TripTransformer($rider))->transform(\STS\Models\Trip::findOrFail($published['trip_id']));
        $this->assertSame([], $dto['points']);
        $this->assertArrayNotHasKey('car', $dto);
        $accepted = app(\STS\Mobility\Journeys\PrivateScheduledAdmissionService::class)->accept((int)$published['trip_id'], $rider->id);
        $this->assertSame(1, (int)$accepted->request_state);
        $this->actingAsApiUser($rider)->getJson('/api/trips/'.$published['trip_id'])->assertOk();
        $this->actingAsApiUser($host)->putJson('/api/trips/'.$published['trip_id'], ['total_seats'=>5])->assertStatus(409);
        $this->assertDatabaseHas('mobility_private_scheduled_bindings', ['booking_id'=>$accepted->id, 'trip_id'=>$published['trip_id'], 'quote_id'=>$routeQuote]);
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$published['trip_id'].'/requests/'.$rider->id.'/cancel')->assertOk();
        $this->assertDatabaseHas('trip_passengers', ['id' => $accepted->id, 'request_state' => \STS\Models\Passenger::STATE_CANCELED]);
    }

    public function test_private_scheduled_publication_replay_preserves_fare_and_rejects_changed_capacity(): void
    {
        [$host, $rider, $car, $quote] = $this->acceptedQuote('scheduled-replay');
        $service = app(\STS\Mobility\Journeys\PrivateScheduledAdmissionService::class);
        $date = now('Asia/Kolkata')->addHour()->startOfSecond();
        $published = $service->publish($host, $quote, 'scheduled-replay-key', $date, 2);
        $replay = $service->publish($host, $quote, 'scheduled-replay-key', $date, 2);
        $this->assertSame($published['fare_minor'], $replay['fare_minor']);
        $this->assertSame($published['trip_id'], $replay['trip_id']);
        $this->expectException(\STS\Mobility\Segments\SegmentException::class);
        $service->publish($host, $quote, 'scheduled-replay-key', $date, 3);
    }

    public function test_private_scheduled_request_rejects_another_riders_consent(): void
    {
        [$host, $rider, $car, $quote] = $this->acceptedQuote('scheduled-quote-owner');
        $other = $this->member();
        $service = app(\STS\Mobility\Journeys\PrivateScheduledAdmissionService::class);
        $trip = $service->publish($host, $quote, 'scheduled-owner-publish', now('Asia/Kolkata')->addHour(), 2);
        $this->actingAsApiUser($other)->postJson('/api/mobility/trips/'.$trip['trip_id'].'/private-scheduled-request', ['quote_id'=>$quote,'expected_quote_hash'=>hash('sha256',DB::table('mobility_route_quotes')->where('id',$quote)->value('snapshot')),'expected_departure_at'=>$trip['departure_at'],'expected_interval_hash'=>str_repeat('0',64)], ['Idempotency-Key'=>'scheduled-wrong-rider'])->assertForbidden()->assertJsonPath('error.code','PRIVATE_QUOTE_ACTOR_MISMATCH');
        $this->assertDatabaseMissing('trip_passengers', ['trip_id'=>$trip['trip_id'], 'user_id'=>$other->id]);
    }

    public function test_private_scheduled_acceptance_rechecks_block_and_departure(): void
    {
        [$host, $rider, $car, $quote] = $this->acceptedQuote('scheduled-departure');
        $service = app(\STS\Mobility\Journeys\PrivateScheduledAdmissionService::class);
        $trip = $service->publish($host, $quote, 'scheduled-departure-publish', now('Asia/Kolkata')->addHour(), 2);
        $this->requestScheduled($trip['trip_id'], $rider, $quote, 'scheduled-departure-request');
        DB::table('mobility_user_blocks')->insert(['actor_id'=>$host->id,'target_id'=>$rider->id,'blocked'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $this->actingAsApiUser($host)->postJson('/api/trips/'.$trip['trip_id'].'/requests/'.$rider->id.'/accept')->assertForbidden()->assertJsonPath('error.code','PARTICIPANT_BLOCKED');
        DB::table('mobility_user_blocks')->delete();
        $this->travel(2)->hours();
        $this->actingAsApiUser($host)->postJson('/api/trips/'.$trip['trip_id'].'/requests/'.$rider->id.'/accept')->assertStatus(409)->assertJsonPath('error.code','PRIVATE_SCHEDULE_NOT_PLANNED');
        $this->assertDatabaseMissing('trip_passengers', ['trip_id'=>$trip['trip_id'],'request_state'=>1]);
        $this->travelBack();
    }

    public function test_scheduled_cards_and_actions_share_canonical_seats_and_replay_cancellation(): void
    {
        [$host,$rider,$car,$quote] = $this->acceptedQuote('scheduled-card-first');
        [$sameHost,$second,$sameCar,$secondQuote] = $this->acceptedQuote('scheduled-card-second',$host,$car);
        [$unusedHost,$third,$unusedCar,$thirdQuote] = $this->acceptedQuote('scheduled-card-third',$host,$car);
        $service = app(\STS\Mobility\Journeys\PrivateScheduledAdmissionService::class);
        $trip = $service->publish($host,$quote,'scheduled-card-publish',now('Asia/Kolkata')->addHour(),2);
        $cards = $this->actingAsApiUser($second)->getJson('/api/mobility/private-scheduled-publications?quote_id='.$secondQuote)->assertOk()->assertHeader('Cache-Control','max-age=0, no-store, private')->json('data');
        $this->assertCount(1,$cards);
        $this->assertSame(2,$cards[0]['seats_available']);
        $this->assertSame($trip['trip_id'],$cards[0]['trip_id']);
        $this->assertArrayNotHasKey('points',$cards[0]);
        $this->assertLessThan(1000,$cards[0]['total_walking_meters']);
        $payload = ['quote_id'=>$secondQuote,'expected_quote_hash'=>$cards[0]['quote_hash'],'expected_departure_at'=>$cards[0]['departure_at'],'expected_interval_hash'=>$cards[0]['interval_hash']];
        $this->actingAsApiUser($second)->postJson('/api/mobility/trips/'.$trip['trip_id'].'/private-scheduled-request',$payload,['Idempotency-Key'=>'card-book-second'])->assertCreated();
        $this->requestScheduled($trip['trip_id'],$rider,$quote,'card-book-first');
        $this->requestScheduled($trip['trip_id'],$third,$thirdQuote,'card-book-third');
        foreach ([$rider,$second] as $passenger) {
            $this->actingAsApiUser($host)->postJson('/api/mobility/trips/'.$trip['trip_id'].'/private-scheduled-bookings/'.$passenger->id.'/accept',[],['Idempotency-Key'=>'card-accept-'.$passenger->id])->assertOk()->assertJsonPath('data.request_state',1);
        }
        $this->assertSame(2,DB::table('trip_passengers')->where('trip_id',$trip['trip_id'])->where('request_state',1)->count());
        $this->actingAsApiUser($host)->postJson('/api/mobility/trips/'.$trip['trip_id'].'/private-scheduled-bookings/'.$third->id.'/accept',[],['Idempotency-Key'=>'card-accept-third'])->assertStatus(422)->assertJsonPath('error.code','NOT_SEAT_AVAILABLE');
        $this->actingAsApiUser($third)->getJson('/api/mobility/private-scheduled-publications?quote_id='.$thirdQuote)->assertOk()->assertJsonCount(0,'data');
        $this->actingAsApiUser($third)->postJson('/api/mobility/trips/'.$trip['trip_id'].'/private-scheduled-bookings/'.$second->id.'/cancel',[],['Idempotency-Key'=>'card-outsider-cancel'])->assertForbidden();
        $cancelPath = '/api/mobility/trips/'.$trip['trip_id'].'/private-scheduled-bookings/'.$second->id.'/cancel';
        $result = $this->actingAsApiUser($second)->postJson($cancelPath,[],['Idempotency-Key'=>'card-cancel-second'])->assertOk()->json('data');
        $replay = $this->actingAsApiUser($second)->postJson($cancelPath,[],['Idempotency-Key'=>'card-cancel-second'])->assertOk()->json('data');
        $this->assertEquals($result,$replay);
        $this->assertDatabaseHas('trip_passengers',['trip_id'=>$trip['trip_id'],'user_id'=>$second->id,'request_state'=>3,'canceled_state'=>2]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/trips/'.$trip['trip_id'].'/private-scheduled-bookings/'.$third->id.'/accept',[],['Idempotency-Key'=>'card-accept-third'])->assertOk();
        $this->actingAsApiUser($third)->getJson('/api/mobility/private-scheduled-bookings')->assertOk()->assertJsonCount(1,'data.0.bookings')->assertJsonPath('data.0.bookings.0.rider_id',$third->id);
        $outsider = $this->member();
        $this->actingAsApiUser($outsider)->getJson('/api/mobility/private-scheduled-bookings')->assertOk()->assertJsonCount(0,'data');
    }

    public function test_scheduled_card_consent_replay_and_rejection_are_actor_scoped(): void
    {
        [$host,$rider,$car,$quote] = $this->acceptedQuote('scheduled-rejection');
        $service = app(\STS\Mobility\Journeys\PrivateScheduledAdmissionService::class);
        $trip = $service->publish($host,$quote,'scheduled-rejection-publish',now('Asia/Kolkata')->addHour(),2);
        $card = $service->discover($rider,$quote)[0];
        $payload = ['quote_id'=>$quote,'expected_quote_hash'=>hash('sha256',DB::table('mobility_route_quotes')->where('id',$quote)->value('snapshot')),'expected_departure_at'=>$trip['departure_at'],'expected_interval_hash'=>$card['interval_hash']];
        $path = '/api/mobility/trips/'.$trip['trip_id'].'/private-scheduled-request';
        $this->actingAsApiUser($rider)->postJson($path,$payload,['Idempotency-Key'=>'reject-request'])->assertCreated();
        $changed = $payload; $changed['expected_departure_at'] = now('Asia/Kolkata')->addHours(2)->toISOString();
        $this->actingAsApiUser($rider)->postJson($path,$changed,['Idempotency-Key'=>'reject-request'])->assertStatus(409)->assertJsonPath('error.code','IDEMPOTENCY_CONFLICT');
        $rejectPath = '/api/mobility/trips/'.$trip['trip_id'].'/private-scheduled-bookings/'.$rider->id.'/reject';
        $this->actingAsApiUser($rider)->postJson($rejectPath,[],['Idempotency-Key'=>'rider-reject'])->assertForbidden();
        $result = $this->actingAsApiUser($host)->postJson($rejectPath,[],['Idempotency-Key'=>'driver-reject'])->assertOk()->assertJsonPath('data.request_state',2)->json('data');
        $this->assertEquals($result,$this->actingAsApiUser($host)->postJson($rejectPath,[],['Idempotency-Key'=>'driver-reject'])->assertOk()->json('data'));
        $this->actingAsApiUser($host)->postJson('/api/mobility/trips/'.$trip['trip_id'].'/private-scheduled-bookings/'.$rider->id.'/accept',[],['Idempotency-Key'=>'accept-after-reject'])->assertStatus(409);
        $this->assertDatabaseMissing('trip_passengers',['trip_id'=>$trip['trip_id'],'request_state'=>1]);
    }

    public function test_publication_bound_plan_preserves_prior_detour_for_another_riders_exact_quote_and_booking(): void
    {
        [$driver,$first,$car,$sourceQuote]=$this->acceptedQuote('scheduled-bound-source');
        $service=app(\STS\Mobility\Journeys\PrivateScheduledAdmissionService::class);
        $trip=$service->publish($driver,$sourceQuote,'bound-source-publish',now('Asia/Kolkata')->addHour(),2);
        $this->requestScheduled($trip['trip_id'],$first,$sourceQuote,'bound-first-request');$service->accept($trip['trip_id'],$first->id);
        $publication=DB::table('mobility_private_scheduled_publications')->where('trip_id',$trip['trip_id'])->first();
        $canonical=json_decode($publication->driver_path,true);
        // Any attempted vehicle rerouting would erase the source rider's detour.
        $original=app(PathProvider::class);
        $paths=new class($original) implements PathProvider {
            public array $calls=[];
            public function __construct(private PathProvider $original){}
            public function path(array $points,string $mode,float $timeoutSeconds=5):array {
                $this->calls[]=$mode;if($mode!=='foot')throw new \RuntimeException('Published route must never be rerouted');
                return $this->original->path($points,$mode,$timeoutSeconds);
            }
        };
        app()->instance(PathProvider::class,$paths);
        $input=['source_publication_id'=>$publication->publication_id,'expected_route_hash'=>$publication->route_hash];
        $result=$this->actingAsApiUser($driver)->postJson('/api/mobility/pickup-plans',$input,['Idempotency-Key'=>'bound-plan'])->assertCreated()->json('data');
        $plan=$result['plan'];$this->assertCount(2,$plan['points']);$this->assertCount(4,$plan['path']['waypoints']);$this->assertEquals($canonical,$plan['path']);
        $second=$this->member();$origin=['lat'=>12.955,'lng'=>77.56];$destination=['lat'=>13.00,'lng'=>77.60];
        $intent=$this->actingAsApiUser($second)->postJson('/api/mobility/pickup-intents',['plan_id'=>$plan['id'],'invite_token'=>$result['invite_token'],'origin'=>$origin,'max_walk_meters'=>999],['Idempotency-Key'=>'bound-intent'])->assertCreated()->json('data.intent');
        $preview=$this->actingAsApiUser($driver)->postJson('/api/mobility/pickup-intents/'.$intent['id'].'/previews',['expected_plan_version'=>1,'expected_intent_version'=>1,'max_detour_meters'=>0,'max_detour_seconds'=>0],['Idempotency-Key'=>'bound-preview'])->assertCreated()->json('data.preview');
        $this->assertTrue($preview['snapshot']['direct']['available']);
        $selection=$this->actingAsApiUser($driver)->postJson('/api/mobility/pickup-previews/'.$preview['id'].'/selections',['choice'=>'DIRECT_RIDER_PICKUP','expected_plan_version'=>1,'expected_intent_version'=>1],['Idempotency-Key'=>'bound-select'])->assertCreated()->json('data.selection');
        $selection=$this->actingAsApiUser($second)->postJson('/api/mobility/pickup-selections/'.$selection['id'].'/confirm',['expected_version'=>$selection['version'],'terms_hash'=>$selection['terms_hash'],'access_acknowledged'=>true],['Idempotency-Key'=>'bound-confirm'])->assertOk()->json('data.selection');
        $a=$this->endpoint($second,$origin,'bound-origin');$b=$this->endpoint($second,$destination,'bound-destination');
        $quote=$this->actingAsApiUser($second)->postJson('/api/mobility/private-quotes',['selection_id'=>$selection['id'],'origin_endpoint_id'=>$a,'destination_endpoint_id'=>$b,'expected_terms_hash'=>$selection['terms_hash'],'expected_selection_version'=>$selection['version']],['Idempotency-Key'=>'bound-quote'])->assertCreated()->json('data.quote');
        $candidate=$quote['terms']['driver_path'];
        foreach(['geometry','provider','profile','data_version','distance_meters','duration_seconds'] as $field)$this->assertSame($canonical[$field],$candidate[$field]);
        $this->assertNotSame($canonical['waypoints'][1]['location'],$candidate['waypoints'][1]['location']);
        $this->assertSame($candidate['distance_meters'],array_sum(array_column($candidate['legs'],'distance_meters')));
        $this->actingAsApiUser($driver)->postJson('/api/mobility/private-quotes/'.$quote['id'].'/driver-approve',['expected_version'=>1,'quote_hash'=>$quote['quote_hash']],['Idempotency-Key'=>'bound-driver-approve'])->assertOk();
        $this->actingAsApiUser($second)->postJson('/api/mobility/private-quotes/'.$quote['id'].'/rider-consent',['expected_version'=>2,'quote_hash'=>$quote['quote_hash'],'access_acknowledged'=>true],['Idempotency-Key'=>'bound-rider-consent'])->assertOk();
        $this->requestScheduled($trip['trip_id'],$second,$quote['route_quote_id'],'bound-second-request');$service->accept($trip['trip_id'],$second->id);
        $this->assertSame(2,DB::table('trip_passengers')->where('trip_id',$trip['trip_id'])->where('request_state',1)->count());
        $this->assertEquals($publication,DB::table('mobility_private_scheduled_publications')->where('trip_id',$trip['trip_id'])->first());
        $this->assertNotEmpty($paths->calls);$this->assertSame(['foot'],array_values(array_unique($paths->calls)));
    }

    public function test_publication_plan_is_private_immutable_and_fails_closed_after_publication_closes(): void
    {
        [$driver,$rider,$car,$quote]=$this->acceptedQuote('scheduled-bound-policy');
        $service=app(\STS\Mobility\Journeys\PrivateScheduledAdmissionService::class);$trip=$service->publish($driver,$quote,'bound-policy-publish',now('Asia/Kolkata')->addHour(),2);
        $publication=DB::table('mobility_private_scheduled_publications')->where('trip_id',$trip['trip_id'])->first();
        $input=['source_publication_id'=>$publication->publication_id,'expected_route_hash'=>$publication->route_hash];
        $this->actingAsApiUser($rider)->postJson('/api/mobility/pickup-plans',$input,['Idempotency-Key'=>'bound-not-owner'])->assertNotFound();
        $changed=$input;$changed['expected_route_hash']=str_repeat('0',64);
        $this->actingAsApiUser($driver)->postJson('/api/mobility/pickup-plans',$changed,['Idempotency-Key'=>'bound-stale'])->assertStatus(409);
        $plan=$this->actingAsApiUser($driver)->postJson('/api/mobility/pickup-plans',$input,['Idempotency-Key'=>'bound-valid'])->assertCreated()->json('data.plan');
        $this->actingAsApiUser($driver)->putJson('/api/mobility/pickup-plans/'.$plan['id'],['car_id'=>$car->id,'vehicle_type'=>'car','ride_mode'=>'scheduled','points'=>$plan['points'],'expected_version'=>1])->assertStatus(409)->assertJsonPath('error.code','PUBLICATION_PLAN_IMMUTABLE');
        DB::table('mobility_trip_lifecycles')->where('trip_id',$trip['trip_id'])->update(['state'=>'cancelled']);
        $this->actingAsApiUser($driver)->getJson('/api/mobility/pickup-plans/'.$plan['id'])->assertStatus(409)->assertJsonPath('error.code','PRIVATE_PUBLICATION_INVALID');
    }

    private function requestScheduled(int $trip, User $rider, string $quote, string $key): array
    {
        $service = app(\STS\Mobility\Journeys\PrivateScheduledAdmissionService::class);
        $card = collect($service->discover($rider, $quote))->firstWhere('trip_id', $trip);
        return $service->request($trip, $rider, $quote, $key, $card['quote_hash'], $card['departure_at'], $card['interval_hash']);
    }

}
