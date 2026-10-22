<?php
namespace STS\Mobility\Commute;
use Illuminate\Http\Request;
use STS\Models\{Trip,Passenger};
use STS\Mobility\Segments\SegmentException;
use Illuminate\Support\Facades\DB;
final class CommuteController {
 private function response(array$v){return response()->json($v)->header('Cache-Control','private, no-store, max-age=0');}
 public function demo(Request$r,CommuteService$s){$v=$r->validate(['role'=>'required|in:rider,driver','scenario'=>'sometimes|nullable|in:campus-route']);return$this->response($s->demoSession($v['role'],$v['scenario']??null));}
 public function me(Request$r,CommuteService$s){return$this->response($s->me($r->user()));}
 public function profile(Request$r,CommuteService$s){$v=$r->validate(['max_walk_meters'=>'sometimes|integer|between:0,999','role'=>'required|in:rider,driver','vehicle_preference'=>'required|in:car,bike,both','schedule_mode'=>'required|in:fixed,custom,timetable','home'=>'required|array:lat,lng,label','home.lat'=>'required|numeric|between:12.65,13.35','home.lng'=>'required|numeric|between:77.2,77.95','home.label'=>'required|string|max:160','campus'=>'required|array:lat,lng,label','campus.lat'=>'required|numeric|between:12.65,13.35','campus.lng'=>'required|numeric|between:77.2,77.95','campus.label'=>'required|string|max:160','outbound_time'=>'required|date_format:H:i','return_time'=>'required|date_format:H:i','weekdays'=>'required|array|min:1|max:7','weekdays.*'=>'integer|between:1,7|distinct','name'=>'sometimes|string|min:1|max:100']);return$this->response($s->saveProfile($r->user(),$v));}
 public function rides(Request$r,CommuteService$s){$v=$r->validate(['direction'=>'required|in:outbound,return','date'=>'required|date_format:Y-m-d','vehicle_type'=>'required|in:car,bike,both','pickup_choice'=>'sometimes|in:both,DIRECT_RIDER_PICKUP,ON_ROUTE_MEETING_POINT']);return$this->response($s->rides($r->user(),$v['direction'],$v['date'],$v['vehicle_type'],$v['pickup_choice']??'both'));}
 public function offers(Request$r,CommuteMatchingService$s){return$this->response($s->offers($r->user()));}
 public function publish(Request$r,CommuteMatchingService$s,CommuteService$c){$v=$r->validate(['direction'=>'required|in:outbound,return','date'=>'required|date_format:Y-m-d','vehicle_type'=>'required|in:car,bike','choice'=>'required|in:DIRECT_RIDER_PICKUP,ON_ROUTE_MEETING_POINT','max_detour_meters'=>'sometimes|integer|between:0,10000','max_detour_seconds'=>'sometimes|integer|between:0,1800']);$v=array_replace(['max_detour_meters'=>3000,'max_detour_seconds'=>600],$v);return$this->response($c->receipt($r->user(),$r->header('Idempotency-Key',''),['publish',$v,$c->profile($r->user())],fn()=>$s->publish($r->user(),$v)));}
 public function request(Request$r,int$trip,CommuteService$s){$v=$r->validate(['quote_token'=>'required|string|size:64']);return$this->response($s->receipt($r->user(),$r->header('Idempotency-Key',''),['request',$trip,$v],fn()=>$s->request($r->user(),$trip,$v['quote_token'])));}
 public function bookings(Request$r,CommuteService$s){return$this->response($s->bookings($r->user()));}
 public function action(Request$r,int$booking,string$action,CommuteService$s){return$this->response($s->receipt($r->user(),$r->header('Idempotency-Key',''),[$action,$booking],fn()=>$s->action($r->user(),$booking,$action)));}
 public function wallet(Request$r,CommuteService$s){return$this->response($s->wallet($r->user()));}
 public function topup(Request$r,CommuteService$s){$v=$r->validate(['amount_minor'=>'required|integer|min:100|max:100000']);return$this->response($s->receipt($r->user(),$r->header('Idempotency-Key',''),['topup',$v],fn()=>$s->topup($r->user(),$v['amount_minor'])));}
 public function pay(Request$r,int$booking,CommuteService$s){return$this->response($s->receipt($r->user(),$r->header('Idempotency-Key',''),['pay',$booking],fn()=>$s->pay($r->user(),$booking)));}
 public function arrive(Request$r,int$booking,CommuteService$s){return$this->response($s->receipt($r->user(),$r->header('Idempotency-Key',''),['arrive',$booking],fn()=>$s->arrive($r->user(),$booking)));}
 public function release(Request$r,CommuteService$s){return$this->response($s->receipt($r->user(),$r->header('Idempotency-Key',''),['release'],fn()=>$s->release($r->user())));}
 public function transition(Request$r,int$trip,CommuteService$s){
  if(!CommuteService::demoActor($r->user()->id))throw new SegmentException('DEMO_SESSION_REQUIRED',403);
  if(!DB::table('mobility_commute_demo_trips')->where('trip_id',$trip)->exists())throw new SegmentException('DEMO_TRIP_REQUIRED',403);
  // Delegate state/event/idempotency ownership to the existing canonical controller.
  return DB::transaction(function()use($r,$trip){$t=Trip::whereKey($trip)->lockForUpdate()->firstOrFail();if((int)$t->user_id!==$r->user()->id)throw new SegmentException('TRIP_OWNER_REQUIRED',403);
   if(in_array($r->input('action'),['start','board'],true)&&Passenger::where('trip_id',$trip)->where('request_state',1)->whereNotExists(fn($q)=>$q->selectRaw('1')->from('mobility_commute_demo_holds')->whereColumn('booking_id','trip_passengers.id')->where('state','held'))->exists())throw new SegmentException('RIDER_PAYMENT_CONFIRMATION_REQUIRED',409);
   if($r->input('action')==='cancel')throw new SegmentException('CANCEL_BOOKINGS_FIRST',409);
   $r->attributes->set('commute.canonical_transition',true);return app(\STS\Mobility\MobilityController::class)->transition($r,$t);
  });
 }
}
