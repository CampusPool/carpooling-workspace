<?php
namespace STS\Mobility\Commute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use STS\Mobility\Admission\DriverReservationPolicy;
use STS\Mobility\Communications\PairBlockPolicy;
use STS\Mobility\Journeys\JourneyPlanner;
use STS\Mobility\Pickup\PathProvider;
use STS\Mobility\Routing\RoutingException;
use STS\Mobility\Segments\SegmentException;
use STS\Models\{User,Trip,Passenger};

/** Driver-published local demo supply, real road matching, canonical bookings. */
final class CommuteMatchingService {
 public static function presentation(array$j,string$pickupAt):array {
  $meeting=($j['choice']??'')==='ON_ROUTE_MEETING_POINT';
  $seconds=static fn($walk)=>(int)($walk['duration_seconds']??0)+(int)ceil(($walk['unmapped_gap_meters']??0)/1.2);
  $accessSeconds=$seconds($j['access']??[]);$egressSeconds=$seconds($j['egress']??[]);
  return ['pickup_label'=>$meeting?'Meeting point on driver route':($j['rider_origin']['label']??'Pickup'),
   'dropoff_label'=>$meeting&&($j['egress']['total_meters']??0)>10?'On-route drop-off; walk to destination':($j['rider_destination']['label']??'Destination'),
   'meeting_details'=>['mode'=>$meeting?'meet_on_route':'direct_pickup','rider_origin_label'=>$j['rider_origin']['label']??'',
    'rider_destination_label'=>$j['rider_destination']['label']??'','access_seconds'=>$accessSeconds,'egress_seconds'=>$egressSeconds,
    'walk_meters'=>($j['access']['total_meters']??0)+($j['egress']['total_meters']??0),
    'leave_by'=>$meeting?CarbonImmutable::parse($pickupAt)->subSeconds($accessSeconds+60)->toISOString():null,
    'driver_route_unchanged'=>$j['driver_route_unchanged']??false,'ranking'=>$j['ranking']??null]];
 }
 /** Display only: geometry comes from the immutable server-routed proposal. */
 public static function mapData(array$j):array {
  $path=$j['driver_path']??[];$waypoints=$path['waypoints']??[];
  $point=static fn($w)=>isset($w['location'])?['lng'=>$w['location'][0],'lat'=>$w['location'][1]]:null;
  return ['choice'=>$j['choice']??null,'driver_geometry'=>$path['geometry']??[],'driver_waypoints'=>array_column($waypoints,'location'),'driver_legs'=>$path['legs']??[],
   'access_geometry'=>$j['access']['path']['geometry']??[],'egress_geometry'=>$j['egress']['path']['geometry']??[],
   'points'=>['driver_start'=>$j['driver_origin']??$point($waypoints[0]??null),'origin'=>$j['rider_origin']??null,'pickup'=>isset($j['pickup'])?($j['pickup']+['label'=>($j['choice']??'')==='ON_ROUTE_MEETING_POINT'?'Meeting point on driver route':($j['rider_origin']['label']??'Pickup')]):null,'dropoff'=>isset($j['dropoff'])?($j['dropoff']+['label'=>(($j['choice']??'')==='ON_ROUTE_MEETING_POINT'&&($j['egress']['total_meters']??0)>10)?'On-route drop-off':($j['rider_destination']['label']??'Drop-off')]):null,'destination'=>$j['rider_destination']??null,'driver_end'=>$j['driver_destination']??$point($waypoints[array_key_last($waypoints)]??null)],
   'access_meters'=>$j['access']['total_meters']??0,'egress_meters'=>$j['egress']['total_meters']??0,'walking_meters'=>($j['access']['total_meters']??0)+($j['egress']['total_meters']??0),'unmapped_gap_meters'=>($j['access']['unmapped_gap_meters']??0)+($j['egress']['unmapped_gap_meters']??0)];
 }
 private function service():CommuteService{return app(CommuteService::class);}
 private function departure(string$date,string$time):CarbonImmutable {
  $at=CarbonImmutable::parse($date.' '.$time,'Asia/Kolkata');
  if($at->lte(now('Asia/Kolkata')->addMinutes(15)))throw new SegmentException('COMMUTE_CHOOSE_FUTURE_DEPARTURE',422);
  if($at->gt(now('Asia/Kolkata')->addDays(30)))throw new SegmentException('DEPARTURE_INVALID',422);return$at;
 }
 private function profileHash(array$p):string{if(!isset($p['home'],$p['campus'],$p['outbound_time'],$p['return_time']))return '';return hash('sha256',json_encode([$p['home'],$p['campus'],$p['outbound_time'],$p['return_time'],$p['max_walk_meters']??999],JSON_THROW_ON_ERROR));}
 public function publish(User$driver,array$v):array {
  $this->service()->requireDemo($driver);
  if(!$this->service()->driverApproved($driver))throw new SegmentException('HOST_APPROVAL_REQUIRED',403);
  $p=$this->service()->profile($driver);if(!$p)throw new SegmentException('COMMUTE_PROFILE_REQUIRED',422);
  $back=$v['direction']==='return';$from=$p[$back?'campus':'home'];$to=$p[$back?'home':'campus'];$at=$this->departure($v['date'],$p[$back?'return_time':'outbound_time']);
  $key=hash('sha256',json_encode(['published',$driver->id,$v,$from,$to,$at->toISOString()],JSON_THROW_ON_ERROR));
  $existing=DB::table('mobility_commute_demo_trips')->where('fixture_key',$key)->first();if($existing)return['data'=>$this->offerDto($existing)];
  $path=app(PathProvider::class)->path([$from,$to],$v['vehicle_type']);$path['points']=[$from,$to];
  return DB::transaction(function()use($driver,$v,$from,$to,$at,$key,$path){
   DriverReservationPolicy::fence($driver->id);$current=User::whereKey($driver->id)->lock('for update nowait')->firstOrFail();$member=DB::table('mobility_memberships')->where('user_id',$driver->id)->lock('for share nowait')->first();if(!$member||$member->status!=='approved'||$member->college_code!==\STS\Mobility\Trust\CohortPolicy::college()||!$this->service()->driverApproved($current))throw new SegmentException('HOST_APPROVAL_REQUIRED',403);
   $existing=DB::table('mobility_commute_demo_trips')->where('fixture_key',$key)->lockForUpdate()->first();if($existing)return['data'=>$this->offerDto($existing)];
   // One rider party per publication bounds direct detours; no accepted route is overwritten by another rider.
   $trip=Trip::factory()->create(['user_id'=>$driver->id,'trip_date'=>$at->format('Y-m-d H:i:s'),'total_seats'=>1,'state'=>Trip::STATE_READY,'from_town'=>$from['label'],'to_town'=>$to['label'],'description'=>'Driver-published local demo commute; nonredeemable credits only','distance'=>$path['distance_meters']/1000,'estimated_time'=>gmdate('H:i:s',$path['duration_seconds'])]);
   DB::table('mobility_trip_lifecycles')->insert(['trip_id'=>$trip->id,'state'=>'planned','version'=>0,'created_at'=>now(),'updated_at'=>now()]);
   $snapshot=['driver_origin'=>$from,'driver_destination'=>$to,'driver_path'=>$path,'choice'=>$v['choice'],'max_walk_meters'=>999,'max_detour_meters'=>$v['max_detour_meters']??3000,'max_detour_seconds'=>$v['max_detour_seconds']??600];
   DB::table('mobility_commute_demo_trips')->insert(['trip_id'=>$trip->id,'audience_id'=>0,'fixture_key'=>$key,'vehicle_type'=>$v['vehicle_type'],'is_publication'=>true,'direction'=>$v['direction'],'fare_minor'=>0,'base_fare_minor'=>0,'surcharge_minor'=>0,'journey'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);
   return['data'=>$this->offerDto(DB::table('mobility_commute_demo_trips')->where('trip_id',$trip->id)->first())];
  });
 }
 public function offers(User$driver):array {
  $this->service()->requireDemo($driver);
  $rows=DB::table('mobility_commute_demo_trips as m')->join('trips as t','t.id','=','m.trip_id')->join('mobility_trip_lifecycles as l','l.trip_id','=','t.id')->where('m.is_publication',true)->where('t.user_id',$driver->id)->where('l.state','planned')->where('t.trip_date','>',now('Asia/Kolkata'))->orderBy('t.trip_date')->limit(20)->select('m.*')->get();
  return['data'=>$rows->map(fn($m)=>$this->offerDto($m))->all()];
 }
 public function offerDto(object$m):array {
  $t=Trip::findOrFail($m->trip_id);$j=json_decode($m->journey,true,512,JSON_THROW_ON_ERROR);
  return['route_map'=>self::mapData($j),'trip_id'=>$t->id,'driver_name'=>$t->user->name,'pickup_label'=>$j['driver_origin']['label'],'dropoff_label'=>$j['driver_destination']['label'],'departure_at'=>CarbonImmutable::parse($t->trip_date,'Asia/Kolkata')->toISOString(),'vehicle_type'=>$m->vehicle_type,'choice'=>$j['choice'],'max_detour_meters'=>$j['max_detour_meters'],'max_detour_seconds'=>$j['max_detour_seconds'],'total_seats'=>$t->total_seats,'seats_available'=>max(0,$t->total_seats-Passenger::where('trip_id',$t->id)->where('request_state',1)->count()),'distance_meters'=>$j['driver_path']['distance_meters'],'duration_seconds'=>$j['driver_path']['duration_seconds']];
 }
 public function rides(User$rider,string$direction,string$date,string$vehicle,string$pickupChoice='both',string$startTime='',string$endTime=''):array {
  $this->service()->requireDemo($rider);$p=$this->service()->profile($rider);if(!$p)throw new SegmentException('COMMUTE_PROFILE_REQUIRED',422);
  $back=$direction==='return';$from=$p[$back?'campus':'home'];$to=$p[$back?'home':'campus'];$windowStart=$this->departure($date,$startTime);$windowEnd=$this->departure($date,$endTime);
  if($windowStart->gt($windowEnd))throw new SegmentException('COMMUTE_TIME_WINDOW_INVALID',422);
  $rows=DB::table('mobility_commute_demo_trips as m')->join('trips as t','t.id','=','m.trip_id')->join('mobility_trip_lifecycles as l','l.trip_id','=','t.id')->where('m.is_publication',true)->where('t.user_id','<>',$rider->id)->where('m.direction',$direction)->where('l.state','planned')->where('t.trip_date','>',now('Asia/Kolkata'))->whereNull('t.deleted_at')->where('t.state',Trip::STATE_READY)->whereBetween('t.trip_date',[$windowStart->format('Y-m-d H:i:s'),$windowEnd->format('Y-m-d H:i:s')])->when($vehicle!=='both',fn($q)=>$q->where('m.vehicle_type',$vehicle))->orderBy('t.trip_date')->limit(12)->select('m.*','t.user_id as driver_id','t.trip_date')->get();
  $cards=[];$excluded=[];
  foreach($rows as$m){
   $driver=User::find($m->driver_id);if(!$driver||!$this->service()->driverApproved($driver)||PairBlockPolicy::blocked($rider->id,$driver->id))continue;
   if(Passenger::where('trip_id',$m->trip_id)->where('user_id',$rider->id)->whereIn('request_state',[0,1])->exists())continue;
   $available=1-Passenger::where('trip_id',$m->trip_id)->where('request_state',1)->count();if($available<1)continue;
   $source=json_decode($m->journey,true,512,JSON_THROW_ON_ERROR);
   if($pickupChoice!=='both'&&$source['choice']!==$pickupChoice)continue;
   try{$j=app(JourneyPlanner::class)->plan($source['driver_path'],$from,$to,$m->vehicle_type,$source['choice'],min((int)($p['max_walk_meters']??999),$source['max_walk_meters']),$source['max_detour_meters'],$source['max_detour_seconds']);}catch(RoutingException$e){$excluded[]=['trip_id'=>$m->trip_id,'reason'=>$e->machineCode];continue;}
   if(!($j['available']??false)){$excluded[]=['trip_id'=>$m->trip_id,'reason'=>$j['code']??'NO_COMPATIBLE_JOURNEY_WITHIN_LIMITS'];continue;}
   $start=CarbonImmutable::parse($m->trip_date,'Asia/Kolkata');$pickup=$start->addSeconds($j['driver_path']['legs'][0]['duration_seconds']);
   if($pickup->lt($windowStart)||$pickup->gt($windowEnd)||$pickup->lte(now('Asia/Kolkata')->addMinutes(15))){$excluded[]=['trip_id'=>$m->trip_id,'reason'=>'PICKUP_TIME_MISMATCH'];continue;}
   $j['driver_origin']=$source['driver_origin'];$j['driver_destination']=$source['driver_destination'];$j['driver_departure_at']=$start->toISOString();$profileHash=$this->profileHash($p);$price=$j['pricing'];
   $key=hash_hmac('sha256',json_encode([$m->trip_id,$rider->id,$profileHash,$m->fixture_key,$j,intdiv(now('UTC')->timestamp,300)],JSON_THROW_ON_ERROR),(string)config('app.key'));
   DB::table('mobility_commute_matches')->insertOrIgnore(['match_key'=>$key,'trip_id'=>$m->trip_id,'rider_id'=>$rider->id,'profile_hash'=>$profileHash,'journey'=>json_encode($j,JSON_THROW_ON_ERROR),'fare_minor'=>$price['total_fare_minor'],'base_fare_minor'=>$price['base_fare_minor'],'surcharge_minor'=>$price['direct_pickup_surcharge_minor'],'pickup_at'=>$pickup->format('Y-m-d H:i:s'),'expires_at'=>now('UTC')->addMinutes(5),'created_at'=>now(),'updated_at'=>now()]);
   $cards[]=array_replace(['route_map'=>self::mapData($j),'trip_id'=>$m->trip_id,'driver_name'=>$driver->name,'driver_route_origin_label'=>$source['driver_origin']['label'],'driver_route_destination_label'=>$source['driver_destination']['label'],'driver_departure_at'=>$start->toISOString(),'vehicle_type'=>$m->vehicle_type,'seats_available'=>$available,'departure_at'=>$pickup->toISOString(),'pickup_label'=>$from['label'],'dropoff_label'=>$to['label'],'access_meters'=>$j['access']['total_meters'],'egress_meters'=>$j['egress']['total_meters'],'ride_meters'=>$j['riding']['distance_meters'],'ride_seconds'=>$j['riding']['duration_seconds'],'base_fare_minor'=>$price['base_fare_minor'],'surcharge_minor'=>$price['direct_pickup_surcharge_minor'],'total_fare_minor'=>$price['total_fare_minor'],'detour_meters'=>$j['detour']['added_distance_meters'],'detour_seconds'=>$j['detour']['added_duration_seconds'],'choice'=>$j['choice'],'quote_token'=>$key,'demo_fixture'=>true],self::presentation($j,$pickup->toISOString()));
  }
  return['data'=>$cards,'excluded'=>$excluded,'search_window'=>['start_time'=>$startTime,'end_time'=>$endTime],'published_driver_rides_only'=>true,'demo_only'=>true];
 }
 public function request(User$rider,Trip$trip,string$key):array {
  $m=DB::table('mobility_commute_matches')->where('match_key',$key)->where('trip_id',$trip->id)->where('rider_id',$rider->id)->first();
  if(!$m||CarbonImmutable::parse($m->expires_at,'UTC')->lte(now('UTC'))||!hash_equals($m->profile_hash,$this->profileHash($this->service()->profile($rider)??[])))throw new SegmentException('COMMUTE_QUOTE_CHANGED',409);
  if((int)$trip->user_id===$rider->id)throw new SegmentException('RIDER_CANNOT_BE_DRIVER',403);
  $this->eligibility($trip,$rider);
  if(DB::table('mobility_trip_lifecycles')->where('trip_id',$trip->id)->value('state')!=='planned'||CarbonImmutable::parse($trip->trip_date,'Asia/Kolkata')->lte(now('Asia/Kolkata')))throw new SegmentException('COMMUTE_TRIP_CLOSED',409);
  $old=Passenger::where('trip_id',$trip->id)->where('user_id',$rider->id)->whereIn('request_state',[0,1])->first();
  if($old){$terms=$this->terms($old);if(!$terms||$terms->match_key!==$key)throw new SegmentException('COMMUTE_QUOTE_CHANGED',409);return['data'=>$this->service()->bookingDto($old,$rider)];}
  if(Passenger::where('trip_id',$trip->id)->where('request_state',1)->count()>=$trip->total_seats)throw new SegmentException('NOT_SEAT_AVAILABLE',422);
  $b=Passenger::create(['trip_id'=>$trip->id,'user_id'=>$rider->id,'request_state'=>0,'passenger_type'=>Passenger::TYPE_PASAJERO]);
  DB::table('mobility_commute_booking_terms')->insert(['booking_id'=>$b->id,'match_key'=>$key,'created_at'=>now(),'updated_at'=>now()]);
  return['data'=>$this->service()->bookingDto($b,$rider)];
 }
 private function eligibility(Trip$t,User$r):void {
  $ids=[$t->user_id,$r->id];$users=User::whereIn('id',$ids)->orderBy('id')->lock('for update nowait')->get()->keyBy('id');
  $members=DB::table('mobility_memberships')->whereIn('user_id',$ids)->orderBy('user_id')->lock('for share nowait')->get()->keyBy('user_id');
  foreach($ids as$id){$member=$members->get($id);if(!\STS\Mobility\Trust\CohortPolicy::activeUser($users->get($id))||!$member||$member->status!=='approved'||$member->college_code!==\STS\Mobility\Trust\CohortPolicy::college())throw new SegmentException('MEMBERSHIP_REQUIRED',403);}
  $this->service()->requireDemo($r->fresh());if(!$this->service()->driverApproved(User::findOrFail($t->user_id)))throw new SegmentException('HOST_APPROVAL_REQUIRED',403);
  if(PairBlockPolicy::blocked($t->user_id,$r->id))throw new SegmentException('PAIR_BLOCKED',409);
 }
 public function accept(Trip$t,Passenger$b):void {
  $this->eligibility($t,User::findOrFail($b->user_id));$m=$this->terms($b);if(!$m)throw new SegmentException('COMMUTE_QUOTE_CHANGED',409);
  $j=json_decode($m->journey,true,512,JSON_THROW_ON_ERROR);DriverReservationPolicy::fence($t->user_id);DriverReservationPolicy::scheduled($t,['snapshot'=>['duration_seconds'=>$j['driver_path']['duration_seconds']]]);
  $t->distance=$j['driver_path']['distance_meters']/1000;$t->estimated_time=gmdate('H:i:s',$j['driver_path']['duration_seconds']);$t->save();
 }
 public function terms(Passenger$b):?object {
  return DB::table('mobility_commute_booking_terms as b')->join('mobility_commute_matches as m','m.match_key','=','b.match_key')->where('b.booking_id',$b->id)->where('m.trip_id',$b->trip_id)->where('m.rider_id',$b->user_id)->select('m.*')->first();
 }
}
