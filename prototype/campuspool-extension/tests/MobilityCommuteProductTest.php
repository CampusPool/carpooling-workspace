<?php
namespace Tests\Feature\Mobility;
use Tests\TestCase;
use STS\Mobility\Pickup\PathProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Models\User;
final class MobilityCommuteProductTest extends TestCase {
 // PHPUnit XML can differ from Laravel's inherited Docker environment.
 // Check Laravel's actual connection before RefreshDatabase can migrate:fresh.
 protected function beforeRefreshingDatabase():void {
  $name=DB::connection()->getDatabaseName();
  if(!preg_match('/^testing[a-zA-Z0-9_-]*$/',$name))throw new \RuntimeException('Refusing to refresh non-test database: '.$name);
 }

 protected function setUp():void{parent::setUp();putenv('MOBILITY_PILOT_UI_ENABLED=true');putenv('MOBILITY_COLLEGE_CODE=CAMPUS-BLR');foreach(['car','bike','foot']as$m)config(['mobility_routing.'.$m.'_expected_data_version'=>$m.'|crop_sha256='.str_repeat('a',64).'|profile_sha256='.str_repeat('b',64)]);
 app()->bind(PathProvider::class,fn()=>new class implements PathProvider{public function path(array$p,string$m,float$timeoutSeconds=5):array{$legs=[];for($i=1;$i<count($p);$i++){$d=(int)round(hypot(($p[$i]['lat']-$p[$i-1]['lat'])*111000,($p[$i]['lng']-$p[$i-1]['lng'])*100000));$legs[]=['distance_meters'=>$d,'duration_seconds'=>$d?(int)ceil($d/($m==='foot'?1.3:8)):0];}return['provider'=>'osrm','profile'=>'fixture','data_version'=>$m.'|crop_sha256='.str_repeat('a',64).'|profile_sha256='.str_repeat('b',64),'geometry'=>array_map(fn($x)=>[$x['lng'],$x['lat']],$p),'waypoints'=>array_map(fn($x)=>['location'=>[$x['lng'],$x['lat']],'distance_meters'=>0],$p),'legs'=>$legs,'distance_meters'=>array_sum(array_column($legs,'distance_meters')),'duration_seconds'=>array_sum(array_column($legs,'duration_seconds'))];}});
 }
 private function demo(string$r):User{$j=$this->postJson('/api/mobility/commute/demo-session',['role'=>$r])->assertOk()->json();return User::findOrFail($j['user']['id']);}
 private function prefs(string$r='rider'):array{return['role'=>$r,'vehicle_preference'=>'both','schedule_mode'=>'fixed','home'=>['lat'=>12.9716,'lng'=>77.5946,'label'=>'Demo home'],'campus'=>['lat'=>12.9352,'lng'=>77.62,'label'=>'Demo campus'],'outbound_time'=>'08:30','return_time'=>'17:30','weekdays'=>[1,2,3,4,5]];}
 public function test_maps_page_uses_response_nonce_and_serves_map_module():void{
  $page=$this->get('/mobility/')->assertOk();$csp=$page->headers->get('Content-Security-Policy');
  preg_match("/'nonce-([^']+)'/",$csp,$match);$this->assertNotEmpty($match[1]??null);
  $this->assertStringContainsString('nonce="'.$match[1].'"',$page->getContent());
  $this->assertStringNotContainsString("'unsafe-inline'",$csp);$this->assertStringNotContainsString("'unsafe-eval'",$csp);
  $this->get('/mobility/commute-map.mjs')->assertOk()->assertHeader('X-Content-Type-Options','nosniff');
 }
 public function test_visible_demo_request_accept_wallet_trip_and_delayed_release_use_canonical_rows():void{
  $r=$this->demo('rider');$this->actingAsApiUser($r)->putJson('/api/mobility/commute/profile',$this->prefs())->assertOk()->assertJsonPath('profile.role','rider');
  $d=$this->demo('driver');$this->actingAsApiUser($d)->putJson('/api/mobility/commute/profile',$this->prefs('driver'))->assertOk();
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/offers',['date'=>now('Asia/Kolkata')->addDay()->toDateString(),'direction'=>'outbound','vehicle_type'=>'car','choice'=>'DIRECT_RIDER_PICKUP'],['Idempotency-Key'=>'publish-first'])->assertOk();
  $cards=$this->actingAsApiUser($r)->getJson('/api/mobility/commute/rides?direction=outbound&date='.now('Asia/Kolkata')->addDay()->toDateString().'&vehicle_type=car')->assertOk()->json('data');$this->assertCount(1,$cards);$c=$cards[0];
  $this->assertSame('Demo home',$c['route_map']['points']['origin']['label']);$this->assertNotEmpty($c['route_map']['driver_geometry']);$this->assertCount(4,$c['route_map']['driver_waypoints']);
  $id=$this->actingAsApiUser($r)->postJson('/api/mobility/commute/rides/'.$c['trip_id'].'/request',['quote_token'=>$c['quote_token']],['Idempotency-Key'=>'commute-request'])->assertOk()->json('data.booking_id');
  $this->assertDatabaseHas('trip_passengers',['id'=>$id,'request_state'=>0]);$d=$this->demo('driver');
  $this->actingAsApiUser($d)->getJson('/api/mobility/commute/bookings')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.pickup_label','Demo home')->assertJsonPath('data.0.dropoff_label','Demo campus')->assertJsonPath('data.0.vehicle_type','car')->assertJsonPath('data.0.route_map.driver_geometry',$c['route_map']['driver_geometry'])->assertJsonPath('data.0.route_map.points.pickup.lat',$c['route_map']['points']['pickup']['lat'])->assertJsonPath('data.0.route_map.points.pickup.lng',$c['route_map']['points']['pickup']['lng']);
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/bookings/'.$id.'/accept',[],['Idempotency-Key'=>'commute-accept'])->assertOk()->assertJsonPath('data.request_state',1);
  $path='/api/mobility/commute/trips/'.$c['trip_id'].'/transition';$this->actingAsApiUser($d)->postJson($path,['action'=>'start'],['Idempotency-Key'=>'commute-start'])->assertStatus(409);
  $top=$this->actingAsApiUser($r)->postJson('/api/mobility/commute/wallet/top-up',['amount_minor'=>50000],['Idempotency-Key'=>'commute-topup'])->assertOk()->json();
  $this->actingAsApiUser($r)->postJson('/api/mobility/commute/wallet/top-up',['amount_minor'=>50000],['Idempotency-Key'=>'commute-topup'])->assertOk()->assertJsonPath('available_minor',50000);
  $this->actingAsApiUser($r)->postJson('/api/mobility/commute/bookings/'.$id.'/pay',[],['Idempotency-Key'=>'commute-pay'])->assertOk()->assertJsonPath('data.wallet_state','held');
  foreach(['start','board','alight','complete']as$a)$this->actingAsApiUser($d)->postJson($path,['action'=>$a]+(in_array($a,['board','alight'])?['passenger_id'=>$r->id]:[]),['Idempotency-Key'=>'commute-'.$a])->assertOk();
  $this->actingAsApiUser($r)->postJson('/api/mobility/commute/bookings/'.$id.'/confirm-arrival',[],['Idempotency-Key'=>'commute-arrival'])->assertOk();
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/wallet/release-due',[],['Idempotency-Key'=>'release-early'])->assertOk()->assertJsonPath('available_minor',0);
  $this->travel(181)->minutes();$this->actingAsApiUser($d)->postJson('/api/mobility/commute/wallet/release-due',[],['Idempotency-Key'=>'release-due'])->assertOk()->assertJsonPath('available_minor',$c['total_fare_minor']);$this->travelBack();
  $this->assertDatabaseHas('mobility_trip_lifecycles',['trip_id'=>$c['trip_id'],'state'=>'completed']);
 }
 public function test_demo_cannot_be_entered_outside_testing_and_role_change_does_not_approve_driver():void{
  $u=User::factory()->create();$this->actingAsApiUser($u)->putJson('/api/mobility/commute/profile',$this->prefs('driver'))->assertOk()->assertJsonPath('driver_approved',false);
  $this->actingAsApiUser($u)->getJson('/api/mobility/commute/wallet')->assertForbidden();putenv('MOBILITY_PILOT_UI_ENABLED=false');$this->postJson('/api/mobility/commute/demo-session',['role'=>'driver'])->assertNotFound();putenv('MOBILITY_PILOT_UI_ENABLED=true');
 }
 public function test_college_otp_wrong_attempts_persist_and_correct_code_is_single_use():void{
  $id=(string)Str::uuid();$code='123456';DB::table('mobility_college_email_challenges')->insert(['challenge_id'=>$id,'email'=>'synthetic@stu.pes.edu','code_hash'=>hash('sha256',$id.':'.$code.':'.config('app.key')),'expires_at'=>now()->addMinutes(5),'resend_after'=>now()->addSeconds(30),'created_at'=>now(),'updated_at'=>now()]);
  $this->postJson('/api/mobility/college-auth/verify',['challenge_id'=>$id,'code'=>'000000'])->assertStatus(422);$this->assertDatabaseHas('mobility_college_email_challenges',['challenge_id'=>$id,'attempts'=>1]);
  $this->postJson('/api/mobility/college-auth/verify',['challenge_id'=>$id,'code'=>$code])->assertOk()->assertJsonPath('verified_college_email',true);
  $this->postJson('/api/mobility/college-auth/verify',['challenge_id'=>$id,'code'=>$code])->assertStatus(422);
  config(['mail.default'=>'log']);$this->postJson('/api/mobility/college-auth/request',['email'=>'test@stu.pes.edu'])->assertStatus(503)->assertJsonPath('error.code','COLLEGE_MAIL_UNAVAILABLE');
  foreach(['test@stu.pes.edu.evil.com','test@pes.edu','test@gmail.com']as$email)$this->postJson('/api/mobility/college-auth/request',['email'=>$email])->assertStatus(422);
 }
 public function test_otp_attempt_ceiling_and_database_ip_window_are_enforced():void{
  $id=(string)Str::uuid();$code='123456';DB::table('mobility_college_email_challenges')->insert(['challenge_id'=>$id,'email'=>'locked@stu.pes.edu','code_hash'=>hash('sha256',$id.':'.$code.':'.config('app.key')),'expires_at'=>now()->addMinutes(5),'resend_after'=>now()->addSeconds(30),'created_at'=>now(),'updated_at'=>now()]);
  for($i=0;$i<5;$i++)$this->postJson('/api/mobility/college-auth/verify',['challenge_id'=>$id,'code'=>'000000'])->assertStatus(422);
  $this->postJson('/api/mobility/college-auth/verify',['challenge_id'=>$id,'code'=>$code])->assertStatus(422)->assertJsonPath('error.code','COLLEGE_OTP_ATTEMPTS_EXCEEDED');
  $this->assertDatabaseHas('mobility_college_email_challenges',['challenge_id'=>$id,'attempts'=>5]);
  for($i=6;$i<30;$i++)$this->postJson('/api/mobility/college-auth/verify',['challenge_id'=>(string)Str::uuid(),'code'=>'000000'])->assertStatus(422);
  $this->postJson('/api/mobility/college-auth/verify',['challenge_id'=>(string)Str::uuid(),'code'=>'000000'])->assertStatus(429)->assertJsonPath('error.code','COLLEGE_OTP_THROTTLED');
 }

 public function test_search_needs_driver_publication_and_matches_vehicle_direction_and_time():void{
  $r=$this->demo('rider');$this->actingAsApiUser($r)->putJson('/api/mobility/commute/profile',$this->prefs())->assertOk();$date=now('Asia/Kolkata')->addDay()->toDateString();
  $base='/api/mobility/commute/rides?date='.$date;
  $this->actingAsApiUser($r)->getJson($base.'&direction=outbound&vehicle_type=car')->assertOk()->assertJsonCount(0,'data');$this->assertDatabaseCount('trips',0);
  $body=['date'=>$date,'direction'=>'outbound','vehicle_type'=>'car','choice'=>'DIRECT_RIDER_PICKUP'];
  $this->actingAsApiUser($r)->postJson('/api/mobility/commute/offers',$body,['Idempotency-Key'=>'rider-cannot-publish'])->assertForbidden();
  $d=$this->demo('driver');$this->actingAsApiUser($d)->putJson('/api/mobility/commute/profile',$this->prefs('driver'))->assertOk();
  $first=$this->actingAsApiUser($d)->postJson('/api/mobility/commute/offers',$body,['Idempotency-Key'=>'publish-repeat'])->assertOk()->json('data.trip_id');
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/offers',$body,['Idempotency-Key'=>'publish-repeat'])->assertOk()->assertJsonPath('data.trip_id',$first);$this->assertDatabaseCount('trips',1);
  $this->actingAsApiUser($r)->getJson($base.'&direction=outbound&vehicle_type=car')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.trip_id',$first);
  $this->actingAsApiUser($r)->getJson($base.'&direction=return&vehicle_type=car')->assertOk()->assertJsonCount(0,'data');
  $this->actingAsApiUser($r)->getJson($base.'&direction=outbound&vehicle_type=bike')->assertOk()->assertJsonCount(0,'data');
  $this->actingAsApiUser($r)->putJson('/api/mobility/commute/profile',array_replace($this->prefs(),['outbound_time'=>'15:30']))->assertOk();
  $this->actingAsApiUser($r)->getJson($base.'&direction=outbound&vehicle_type=car')->assertOk()->assertJsonCount(0,'data');
 }
 public function test_route_consent_is_bound_to_rider_profile_and_accepted_fare():void{
  $r=$this->demo('rider');$d=$this->demo('driver');$date=now('Asia/Kolkata')->addDay()->toDateString();
  foreach([[$r,'rider'],[$d,'driver']]as[$u,$role])$this->actingAsApiUser($u)->putJson('/api/mobility/commute/profile',$this->prefs($role))->assertOk();
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/offers',['date'=>$date,'direction'=>'outbound','vehicle_type'=>'car','choice'=>'DIRECT_RIDER_PICKUP'],['Idempotency-Key'=>'publish'])->assertOk();
  $card=$this->actingAsApiUser($r)->getJson('/api/mobility/commute/rides?date='.$date.'&direction=outbound&vehicle_type=car')->assertOk()->json('data.0');
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/rides/'.$card['trip_id'].'/request',['quote_token'=>$card['quote_token']],['Idempotency-Key'=>'not-own-quote'])->assertStatus(409);
  $p=$this->prefs();$p['home']['label']='Changed pickup';$this->actingAsApiUser($r)->putJson('/api/mobility/commute/profile',$p)->assertOk();
  $this->actingAsApiUser($r)->postJson('/api/mobility/commute/rides/'.$card['trip_id'].'/request',['quote_token'=>$card['quote_token']],['Idempotency-Key'=>'stale'])->assertStatus(409);
  $this->actingAsApiUser($r)->putJson('/api/mobility/commute/profile',$this->prefs())->assertOk();
  $id=$this->actingAsApiUser($r)->postJson('/api/mobility/commute/rides/'.$card['trip_id'].'/request',['quote_token'=>$card['quote_token']],['Idempotency-Key'=>'request'])->assertOk()->json('data.booking_id');
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/bookings/'.$id.'/accept',[],['Idempotency-Key'=>'accept'])->assertOk();
  $this->actingAsApiUser($r)->postJson('/api/mobility/commute/wallet/top-up',['amount_minor'=>50000],['Idempotency-Key'=>'topup'])->assertOk();
  foreach(['pay-a','pay-b']as$key)$this->actingAsApiUser($r)->postJson('/api/mobility/commute/bookings/'.$id.'/pay',[],['Idempotency-Key'=>$key])->assertOk()->assertJsonPath('data.fare_minor',$card['total_fare_minor']);
  $this->assertDatabaseHas('mobility_commute_demo_holds',['booking_id'=>$id,'amount_minor'=>$card['total_fare_minor']]);
  $this->assertSame(1,DB::table('mobility_commute_demo_entries')->where('booking_id',$id)->where('kind','ride_hold')->count());
 }

 public function test_current_approval_blocks_and_single_seat_capacity_are_rechecked_at_acceptance():void{
  $r=$this->demo('rider');$d=$this->demo('driver');
  $other=User::findOrFail($this->postJson('/api/mobility/commute/demo-session',['role'=>'rider','scenario'=>'campus-route'])->assertOk()->json('user.id'));
  foreach([[$r,'rider'],[$other,'rider'],[$d,'driver']]as[$u,$role])$this->actingAsApiUser($u)->putJson('/api/mobility/commute/profile',$this->prefs($role))->assertOk();
  $date=now('Asia/Kolkata')->addDay()->toDateString();
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/offers',['date'=>$date,'direction'=>'outbound','vehicle_type'=>'car','choice'=>'DIRECT_RIDER_PICKUP','max_detour_meters'=>5000,'max_detour_seconds'=>600],['Idempotency-Key'=>'publish'])->assertOk()->assertJsonPath('data.max_detour_meters',5000);
  $ids=[];foreach([$r,$other]as$u){$card=$this->actingAsApiUser($u)->getJson('/api/mobility/commute/rides?date='.$date.'&direction=outbound&vehicle_type=car')->assertOk()->json('data.0');$ids[]=$this->actingAsApiUser($u)->postJson('/api/mobility/commute/rides/'.$card['trip_id'].'/request',['quote_token'=>$card['quote_token']],['Idempotency-Key'=>'request'])->assertOk()->json('data.booking_id');}
  DB::table('mobility_memberships')->where('user_id',$d->id)->update(['status'=>'pending']);
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/bookings/'.$ids[0].'/accept',[],['Idempotency-Key'=>'approval-revoked'])->assertForbidden();
  DB::table('mobility_memberships')->where('user_id',$d->id)->update(['status'=>'approved']);
  DB::table('mobility_user_blocks')->insert(['actor_id'=>$r->id,'target_id'=>$d->id,'blocked'=>true,'created_at'=>now(),'updated_at'=>now()]);
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/bookings/'.$ids[0].'/accept',[],['Idempotency-Key'=>'blocked'])->assertStatus(409)->assertJsonPath('error.code','PAIR_BLOCKED');
  DB::table('mobility_user_blocks')->where('actor_id',$r->id)->update(['blocked'=>false]);
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/bookings/'.$ids[0].'/accept',[],['Idempotency-Key'=>'accept-first'])->assertOk();
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/bookings/'.$ids[1].'/accept',[],['Idempotency-Key'=>'accept-second'])->assertStatus(422)->assertJsonPath('error.code','NOT_SEAT_AVAILABLE');
  $this->assertDatabaseHas('trip_passengers',['id'=>$ids[1],'request_state'=>0]);
 }

 public function test_meeting_match_preserves_route_and_binds_walk_limit_consent_and_zero_pickup_extra():void{
  $r=$this->demo('rider');$d=$this->demo('driver');$date=now('Asia/Kolkata')->addDay()->toDateString();
  $prefs=$this->prefs();$prefs['home']['lat']+=0.001;$prefs['max_walk_meters']=999;
  $this->actingAsApiUser($r)->putJson('/api/mobility/commute/profile',$prefs)->assertOk();$this->actingAsApiUser($d)->putJson('/api/mobility/commute/profile',$this->prefs('driver'))->assertOk();
  $trip=$this->actingAsApiUser($d)->postJson('/api/mobility/commute/offers',['date'=>$date,'direction'=>'outbound','vehicle_type'=>'car','choice'=>'ON_ROUTE_MEETING_POINT','max_detour_meters'=>0,'max_detour_seconds'=>0],['Idempotency-Key'=>'meeting-publish'])->assertOk()->json('data.trip_id');
  $base=json_decode(DB::table('mobility_commute_demo_trips')->where('trip_id',$trip)->value('journey'),true);
  $url='/api/mobility/commute/rides?date='.$date.'&direction=outbound&vehicle_type=car&pickup_choice=ON_ROUTE_MEETING_POINT';
  $c=$this->actingAsApiUser($r)->getJson($url)->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.surcharge_minor',0)->assertJsonPath('data.0.detour_meters',0)->json('data.0');
  $this->assertGreaterThan(50,$c['access_meters']);$this->assertSame($base['driver_path']['geometry'],$c['route_map']['driver_geometry']);$this->assertNotEmpty($c['meeting_details']['leave_by']);
  $this->actingAsApiUser($r)->getJson(str_replace('pickup_choice=ON_ROUTE_MEETING_POINT','pickup_choice=DIRECT_RIDER_PICKUP',$url))->assertOk()->assertJsonCount(0,'data');
  $prefs['max_walk_meters']=50;$this->actingAsApiUser($r)->putJson('/api/mobility/commute/profile',$prefs)->assertOk();
  $this->actingAsApiUser($r)->postJson('/api/mobility/commute/rides/'.$trip.'/request',['quote_token'=>$c['quote_token']],['Idempotency-Key'=>'stale-walk'])->assertStatus(409);
  $this->actingAsApiUser($r)->getJson($url)->assertOk()->assertJsonCount(0,'data');
  $prefs['max_walk_meters']=999;$this->actingAsApiUser($r)->putJson('/api/mobility/commute/profile',$prefs)->assertOk();
  $c=$this->actingAsApiUser($r)->getJson($url)->assertOk()->json('data.0');
  $id=$this->actingAsApiUser($r)->postJson('/api/mobility/commute/rides/'.$trip.'/request',['quote_token'=>$c['quote_token']],['Idempotency-Key'=>'meeting-request'])->assertOk()->json('data.booking_id');
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/bookings/'.$id.'/accept',[],['Idempotency-Key'=>'meeting-accept'])->assertOk()->assertJsonPath('data.choice','ON_ROUTE_MEETING_POINT')->assertJsonPath('data.route_map.driver_geometry',$c['route_map']['driver_geometry']);
  $this->actingAsApiUser($r)->postJson('/api/mobility/commute/wallet/top-up',['amount_minor'=>50000],['Idempotency-Key'=>'meeting-topup'])->assertOk();
  $this->actingAsApiUser($r)->postJson('/api/mobility/commute/bookings/'.$id.'/pay',[],['Idempotency-Key'=>'meeting-pay'])->assertOk()->assertJsonPath('data.fare_minor',$c['base_fare_minor']);
 }

 public function test_driver_overlap_is_fenced_and_cancel_refunds_matched_hold_once():void{
  $r=$this->demo('rider');$d=$this->demo('driver');$date=now('Asia/Kolkata')->addDay()->toDateString();
  foreach([[$r,'rider'],[$d,'driver']]as[$u,$role])$this->actingAsApiUser($u)->putJson('/api/mobility/commute/profile',$this->prefs($role))->assertOk();
  $publish=['date'=>$date,'direction'=>'outbound','vehicle_type'=>'car','choice'=>'DIRECT_RIDER_PICKUP'];
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/offers',$publish,['Idempotency-Key'=>'first-offer'])->assertOk();
  $url='/api/mobility/commute/rides?date='.$date.'&direction=outbound&vehicle_type=car';$c=$this->actingAsApiUser($r)->getJson($url)->assertOk()->json('data.0');
  $id=$this->actingAsApiUser($r)->postJson('/api/mobility/commute/rides/'.$c['trip_id'].'/request',['quote_token'=>$c['quote_token']],['Idempotency-Key'=>'first-request'])->assertOk()->json('data.booking_id');
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/bookings/'.$id.'/accept',[],['Idempotency-Key'=>'first-accept'])->assertOk();
  foreach([[$r,'rider'],[$d,'driver']]as[$u,$role])$this->actingAsApiUser($u)->putJson('/api/mobility/commute/profile',array_replace($this->prefs($role),['outbound_time'=>'08:35']))->assertOk();
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/offers',$publish,['Idempotency-Key'=>'second-offer'])->assertOk();
  $other=$this->actingAsApiUser($r)->getJson($url)->assertOk()->json('data.0');
  $otherId=$this->actingAsApiUser($r)->postJson('/api/mobility/commute/rides/'.$other['trip_id'].'/request',['quote_token'=>$other['quote_token']],['Idempotency-Key'=>'other-request'])->assertOk()->json('data.booking_id');
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/bookings/'.$otherId.'/accept',[],['Idempotency-Key'=>'overlap'])->assertStatus(409)->assertJsonPath('error.code','DRIVER_ROUTE_OVERLAP');
  $this->actingAsApiUser($r)->postJson('/api/mobility/commute/wallet/top-up',['amount_minor'=>50000],['Idempotency-Key'=>'topup'])->assertOk();
  $this->actingAsApiUser($r)->postJson('/api/mobility/commute/bookings/'.$id.'/pay',[],['Idempotency-Key'=>'pay'])->assertOk();
  foreach(['cancel-a','cancel-b']as$key)$this->actingAsApiUser($r)->postJson('/api/mobility/commute/bookings/'.$id.'/cancel',[],['Idempotency-Key'=>$key])->assertOk()->assertJsonPath('data.wallet_state','refunded');
  $this->assertSame(1,DB::table('mobility_commute_demo_entries')->where('booking_id',$id)->where('kind','refund')->count());
  $this->actingAsApiUser($r)->getJson('/api/mobility/commute/wallet')->assertOk()->assertJsonPath('available_minor',50000);
  $this->actingAsApiUser($d)->postJson('/api/mobility/commute/bookings/'.$otherId.'/accept',[],['Idempotency-Key'=>'accept-after-cancel'])->assertOk();
 }

}
