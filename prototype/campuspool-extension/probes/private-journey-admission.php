<?php

/** Guarded disposable software proof. Generated actors, real road routing and TCP/JWT; no phones or money. */
use Illuminate\Support\Facades\DB;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\User;

require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$report = ['all_checks_passed'=>false,'boundary'=>'Disposable generated actors; actual local Bengaluru car/bike/foot graphs, TCP/JWT/MySQL. No Google route storage, physical GPS, payment, or production capacity claim.','checks'=>[]];
$peer = null;
function proof(string $name, bool $ok, array $facts=[]): void {
    global $report;
    $report['checks'][]=['name'=>$name,'passed'=>$ok,'observed'=>$facts];
    if (!$ok) throw new RuntimeException($name);
}
function handle(string $method,string $path,?string $token,?array $body=null,?string $key=null,int $port=8000): CurlHandle {
    $ch=curl_init('http://127.0.0.1:'.$port.'/api/'.$path);
    $headers=['Accept: application/json','Content-Type: application/json'];
    if($token)$headers[]='Authorization: Bearer '.$token;
    if($key)$headers[]='Idempotency-Key: '.$key;
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>80,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_FOLLOWLOCATION=>false]);
    if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));
    return $ch;
}
function readResponse(CurlHandle $ch,string|false $raw): array {
    if($raw===false)throw new RuntimeException('Local HTTP transport failed');
    $size=curl_getinfo($ch,CURLINFO_HEADER_SIZE);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $headers=strtolower(substr($raw,0,$size));
    $data=json_decode(substr($raw,$size),true);curl_close($ch);
    return [$status,is_array($data)?$data:[],$headers];
}
function http(string $method,string $path,?string $token,?array $body=null,?string $key=null): array {
    $ch=handle($method,$path,$token,$body,$key);return readResponse($ch,curl_exec($ch));
}
function expectHttp(string $name,string $method,string $path,?string $token,?array $body=null,?string $key=null,array $expected=[200]): array {
    [$status,$data,$headers]=http($method,$path,$token,$body,$key);
    proof($name,in_array($status,$expected,true),['status'=>$status,'code'=>$data['error']['code']??null]);return $data;
}
function member(): User {
    $u=User::factory()->create(['active'=>true,'banned'=>false]);
    DB::table('mobility_memberships')->insert(['user_id'=>$u->id,'college_code'=>'CAMPUS-BLR','affiliation'=>'student','status'=>'approved','version'=>1,'created_at'=>now(),'updated_at'=>now()]);return $u;
}
function hostFixture(User $host,string $mode): Car {
    // Test-only profile, never real government identifiers or external verification.
    $host->forceFill(['image'=>'synthetic-profile.png','description'=>'Synthetic local HTTP proof','nro_doc'=>'DEMO-ONLY','mobile_phone'=>'+910000000000'])->save();
    $car=Car::factory()->withCatalog()->create(['user_id'=>$host->id]);
    DB::table('mobility_host_applications')->insert(['user_id'=>$host->id,'car_id'=>$car->id,'vehicle_type'=>$mode,'vehicle_fingerprint'=>CohortPolicy::vehicleFingerprint($car),'status'=>'approved','version'=>1,'created_at'=>now(),'updated_at'=>now()]);return $car;
}
/** Prove simultaneous idempotency under a deliberately held canonical request lock. */
function duplicateAccept(string $path,string $token,string $requestId,string $key): array {
    $pdo=new PDO('mysql:host=mysql;dbname=carpoolear','root','smoke-root-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $observer=new PDO('mysql:host=mysql;dbname=carpoolear','root','smoke-root-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->beginTransaction();$q=$pdo->prepare('SELECT id FROM mobility_dispatch_requests WHERE id=? FOR UPDATE');$q->execute([$requestId]);$q->fetchAll();
    $multi=curl_multi_init();$handles=[];
    foreach([8000,18082] as $port){$ch=handle('POST',$path,$token,[],$key,$port);$handles[]=$ch;curl_multi_add_handle($multi,$ch);}
    $waits=0;$running=0;
    try{
        $until=microtime(true)+8;
        do{curl_multi_exec($multi,$running);$waits=max($waits,(int)$observer->query("SELECT COUNT(DISTINCT w.REQUESTING_ENGINE_TRANSACTION_ID) FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID AND l.ENGINE=w.ENGINE WHERE l.OBJECT_SCHEMA='carpoolear' AND l.OBJECT_NAME='mobility_dispatch_requests'")->fetchColumn());if($waits>=2)break;if($running)curl_multi_select($multi,.05);}while($running&&microtime(true)<$until);
        $pdo->commit();do{curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,.05);}while($running);
        $results=[];foreach($handles as $ch){$raw=curl_multi_getcontent($ch);curl_multi_remove_handle($multi,$ch);$results[]=readResponse($ch,$raw);}
        proof('two TCP acceptance workers observed waiting on canonical request', $waits>=2,['blocked_transactions'=>$waits]);
        proof('simultaneous duplicate acceptance returns one canonical assignment',$results[0][0]===200&&$results[1][0]===200&&$results[0][1]===$results[1][1],['statuses'=>array_column($results,0)]);
        return $results[0][1];
    }finally{if($pdo->inTransaction())$pdo->rollBack();curl_multi_close($multi);}
}
/** Two independent HTTP workers submit the same chat mutation behind a held Trip lock. */
function duplicateChat(int $trip, string $token, string $key): array {
    $pdo=new PDO('mysql:host=mysql;dbname=carpoolear','root','smoke-root-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $observer=new PDO('mysql:host=mysql;dbname=carpoolear','root','smoke-root-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->beginTransaction();$q=$pdo->prepare('SELECT id FROM trips WHERE id=? FOR UPDATE');$q->execute([$trip]);$q->fetchAll();
    $multi=curl_multi_init();$handles=[];$waits=0;
    try {
        foreach([8000,18082] as $port){$ch=handle('POST','mobility/trips/'.$trip.'/messages',$token,['text'=>'Synthetic duplicate chat'],$key,$port);$handles[]=$ch;curl_multi_add_handle($multi,$ch);}
        $until=microtime(true)+8;
        do{curl_multi_exec($multi,$running);$waits=max($waits,(int)$observer->query("SELECT COUNT(DISTINCT w.REQUESTING_ENGINE_TRANSACTION_ID) FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID AND l.ENGINE=w.ENGINE WHERE l.OBJECT_SCHEMA='carpoolear' AND l.OBJECT_NAME='trips'")->fetchColumn());if($waits>=2)break;if($running)curl_multi_select($multi,.05);}while($running&&microtime(true)<$until);
        $pdo->commit();do{curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,.05);}while($running);
        $results=[];foreach($handles as $ch){$raw=curl_multi_getcontent($ch);curl_multi_remove_handle($multi,$ch);$results[]=readResponse($ch,$raw);}
        proof('two chat TCP workers observed waiting on canonical Trip',$waits>=2,['blocked_transactions'=>$waits]);
        proof('simultaneous chat retry returns identical message values',$results[0][0]===200&&$results[1][0]===200&&$results[0][1]==$results[1][1]&&($results[0][1]['data']['id']??null)===($results[1][1]['data']['id']??null),['statuses'=>array_column($results,0),'message_ids'=>array_map(fn($r)=>$r[1]['data']['id']??null,$results)]);
        return $results[0][1];
    } finally {if($pdo->inTransaction())$pdo->rollBack();curl_multi_close($multi);}
}
/** A bounded observed workload, not a claimed supported-user count. */
function readLoad(string $path,string $participantToken,string $outsiderToken): void {
    global $report;
    $multi=curl_multi_init();$handles=[];$start=microtime(true);
    for($i=0;$i<24;$i++){$ch=handle('GET',$path,$i%2===0?$participantToken:$outsiderToken);$handles[]=$ch;curl_multi_add_handle($multi,$ch);}
    do{curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,.05);}while($running);
    $times=[];$correct=0;
    foreach($handles as $i=>$ch){$times[]=(float)curl_getinfo($ch,CURLINFO_TOTAL_TIME);$raw=curl_multi_getcontent($ch);curl_multi_remove_handle($multi,$ch);[$status,$data]=readResponse($ch,$raw);if($status===($i%2===0?200:403)&&($i%2===0||!isset($data['data']['location'])))$correct++;}
    curl_multi_close($multi);sort($times);$elapsed=microtime(true)-$start;
    proof('24 concurrent TCP tracking reads preserve participant privacy',$correct===24,['requests'=>24,'participant_reads'=>12,'denied_outsider_reads'=>12,'elapsed_seconds'=>round($elapsed,3),'p50_seconds'=>round($times[11],3),'p95_seconds'=>round($times[22],3),'max_seconds'=>round($times[23],3)]);
}
try {
    if(getenv('MOBILITY_PRIVATE_JOURNEY_PROOF')!=='local-only'||!app()->environment('testing')||config('database.connections.mysql.host')!=='mysql'||config('database.connections.mysql.database')!=='carpoolear'||CohortPolicy::college()!=='CAMPUS-BLR'||!is_file('/opt/mobility/lifecycle-probe.php'))throw new RuntimeException('Disposable local guard required');
    foreach(['car','bike','foot'] as $mode)if(config('mobility_routing.'.$mode.'_base_url')!=='http://mobility-road-router-'.$mode.':5000')throw new RuntimeException('Actual local road graph required');
    $peerEnv=getenv();unset($peerEnv['PHP_CLI_SERVER_WORKERS']);
    $peer=proc_open(['php','-S','127.0.0.1:18082','/var/www/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$pipes,'/var/www/public',$peerEnv);
    if(!is_resource($peer))throw new RuntimeException('Independent proof listener failed');
    $ready=false;for($i=0;$i<30;$i++){$c=handle('GET','health',null,null,null,18082);$raw=curl_exec($c);if($raw!==false){[$code]=readResponse($c,$raw);if($code===200){$ready=true;break;}}else curl_close($c);usleep(100000);}proof('independent TCP proof listener ready',$ready);
    foreach(['car','bike'] as $mode) foreach(['DIRECT_RIDER_PICKUP','ON_ROUTE_MEETING_POINT'] as $choice){
        $prefix=$mode.'-'.($choice==='DIRECT_RIDER_PICKUP'?'direct':'meeting');
        $host=member();$rider=member();$outsider=member();$car=hostFixture($host,$mode);
        $ht=auth('api')->fromUser($host);$rt=auth('api')->fromUser($rider);$ot=auth('api')->fromUser($outsider);
        $beforeNodes=DB::table('nodes_geo')->count();$beforeRoutes=DB::table('routes')->count();
        $planResponse=expectHttp($prefix.' real-road plan','POST','mobility/pickup-plans',$ht,['car_id'=>$car->id,'vehicle_type'=>$mode,'ride_mode'=>'on_demand','points'=>[['lat'=>12.9716,'lng'=>77.5946],['lat'=>12.9352,'lng'=>77.6200]]],$prefix.'-plan',[201]);$plan=$planResponse['data']['plan'];
        $origin=['lat'=>12.9706,'lng'=>77.598];$destination=['lat'=>12.9352,'lng'=>77.6200];
        $intent=expectHttp($prefix.' private origin','POST','mobility/pickup-intents',$rt,['plan_id'=>$plan['id'],'invite_token'=>$planResponse['data']['invite_token'],'origin'=>$origin,'max_walk_meters'=>999],$prefix.'-intent',[201])['data']['intent'];
        $preview=expectHttp($prefix.' real-road preview','POST','mobility/pickup-intents/'.$intent['id'].'/previews',$ht,['expected_plan_version'=>1,'expected_intent_version'=>1,'max_detour_meters'=>10000,'max_detour_seconds'=>3600],$prefix.'-preview',[201])['data']['preview'];
        $selection=expectHttp($prefix.' driver pickup choice','POST','mobility/pickup-previews/'.$preview['id'].'/selections',$ht,['choice'=>$choice,'expected_plan_version'=>1,'expected_intent_version'=>1],$prefix.'-select',[201])['data']['selection'];
        $selection=expectHttp($prefix.' rider pickup acknowledgment','POST','mobility/pickup-selections/'.$selection['id'].'/confirm',$rt,['expected_version'=>$selection['version'],'terms_hash'=>$selection['terms_hash'],'access_acknowledged'=>true],$prefix.'-confirm')['data']['selection'];
        $a=expectHttp($prefix.' private origin identity','POST','mobility/private-endpoints',$rt,$origin+['source'=>'map_pin'],$prefix.'-origin',[201])['data']['id'];
        $b=expectHttp($prefix.' private destination identity','POST','mobility/private-endpoints',$rt,$destination+['source'=>'map_pin'],$prefix.'-destination',[201])['data']['id'];
        $quote=expectHttp($prefix.' complete road journey quote','POST','mobility/private-quotes',$rt,['selection_id'=>$selection['id'],'origin_endpoint_id'=>$a,'destination_endpoint_id'=>$b,'expected_terms_hash'=>$selection['terms_hash'],'expected_selection_version'=>$selection['version']],$prefix.'-quote',[201])['data']['quote'];
        $t=$quote['terms'];$p=$t['pricing'];proof($prefix.' complete walking and explicit demo fare',$t['total_walking_meters']<1000&&$t['total_walking_meters']===$t['access']['total_meters']+$t['egress']['total_meters']&&$p['base_fare_minor']+$p['direct_pickup_surcharge_minor']===$p['total_fare_minor']&&$p['demo_only']===true&&($choice==='DIRECT_RIDER_PICKUP'?$p['driver_additional_compensation_minor']>0:$p['direct_pickup_surcharge_minor']===0));
        expectHttp($prefix.' outsider quote denied','GET','mobility/private-quotes/'.$quote['id'],$ot,null,null,[403]);
        $quote=expectHttp($prefix.' exact driver fare approval','POST','mobility/private-quotes/'.$quote['id'].'/driver-approve',$ht,['expected_version'=>$quote['version'],'quote_hash'=>$quote['quote_hash']],$prefix.'-approve')['data']['quote'];
        $quote=expectHttp($prefix.' exact rider fare consent','POST','mobility/private-quotes/'.$quote['id'].'/rider-consent',$rt,['expected_version'=>$quote['version'],'quote_hash'=>$quote['quote_hash'],'access_acknowledged'=>true],$prefix.'-consent')['data']['quote'];
        expectHttp($prefix.' private availability','PUT','mobility/dispatch/availability',$ht,['online'=>true,'scope'=>'private','car_id'=>$car->id]);
        $request=expectHttp($prefix.' canonical request admission','POST','mobility/dispatch/requests',$rt,['quote_id'=>$quote['route_quote_id']],$prefix.'-request',[201]);
        $retry=expectHttp($prefix.' request retry','POST','mobility/dispatch/requests',$rt,['quote_id'=>$quote['route_quote_id']],$prefix.'-request');proof($prefix.' stable request identity',$request['id']===$retry['id']);
        app(STS\Mobility\Dispatch\DispatchWorker::class)->process();
        $offers=expectHttp($prefix.' targeted offer','GET','mobility/dispatch/offers',$ht)['data'];$offer=array_values(array_filter($offers,fn($o)=>$o['request_id']===$request['id']))[0]??null;proof($prefix.' selected driver offered only',$offer!==null&&($offer['fare_minor']??null)===$p['total_fare_minor']&&($offer['pricing']['base_fare_minor']??null)===$p['base_fare_minor']&&count($offer['points']??[])===2&&DB::table('mobility_dispatch_offers')->where('request_id',$request['id'])->where('driver_id','<>',$host->id)->count()===0);
        $assigned=duplicateAccept('mobility/dispatch/offers/'.$offer['id'].'/accept',$ht,$request['id'],$prefix.'-accept');$trip=(int)$assigned['trip_id'];
        proof($prefix.' exactly one canonical Passenger and assignment',DB::table('mobility_dispatch_assignments')->where('request_id',$request['id'])->count()===1&&DB::table('trip_passengers')->where('trip_id',$trip)->where('user_id',$rider->id)->count()===1);
        proof($prefix.' no global private route contamination',DB::table('nodes_geo')->count()===$beforeNodes&&DB::table('routes')->count()===$beforeRoutes&&DB::table('trip_routes')->where('trip_id',$trip)->count()===0);
        expectHttp($prefix.' outsider trip denied','GET','trips/'.$trip,$ot,null,null,[403]);
        expectHttp($prefix.' outsider assignment denied','GET','mobility/dispatch/requests/'.$request['id'],$ot,null,null,[403,404]);
        [$code,$body,$headers]=http('GET','mobility/dispatch/requests/'.$request['id'],$rt);proof($prefix.' private responses cannot cache',$code===200&&str_contains($headers,'no-store'));
        $search=expectHttp($prefix.' outsider trip search','GET','trips?history=true',$ot);$json=json_encode($search);proof($prefix.' private trip absent from pool search',!str_contains($json,'"private_quote_id"')&&!str_contains($json,'Private pickup'));
        $session=expectHttp($prefix.' tracking explicit consent','POST','mobility/trips/'.$trip.'/tracking/session',$ht,['consent'=>true],$prefix.'-tracking')['data']['session_id'];
        expectHttp($prefix.' location sample','POST','mobility/trips/'.$trip.'/tracking/locations',$ht,['session_id'=>$session,'sequence'=>1,'lat'=>12.9706,'lng'=>77.598,'accuracy_meters'=>10,'recorded_at'=>now('UTC')->format('Y-m-d\TH:i:s.v\Z')],$prefix.'-sample');
        expectHttp($prefix.' outsider tracking denied','GET','mobility/trips/'.$trip.'/tracking',$ot,null,null,[403]);
        $loc=expectHttp($prefix.' accepted rider tracking','GET','mobility/trips/'.$trip.'/tracking',$rt);proof($prefix.' fresh driver location visible only privately',isset($loc['data']['location']['lat'])&&$loc['data']['stale']===false);
        if ($prefix === 'car-direct') readLoad('mobility/trips/'.$trip.'/tracking',$rt,$ot);
        if ($prefix === 'car-direct') {
            DB::beginTransaction();
            try {
                DB::table('mobility_user_blocks')->count(); // Deliberately establish an older repeatable-read snapshot.
                expectHttp('safety block commits after older read snapshot','POST','mobility/trips/'.$trip.'/blocks',$rt,['target_user_id'=>$host->id,'blocked'=>true],$prefix.'-snapshot-block');
                proof('admission block policy uses current read despite older transaction snapshot',\STS\Mobility\Communications\PairBlockPolicy::blocked((int)$host->id,(int)$rider->id));
            } finally { DB::rollBack(); }
            expectHttp('snapshot proof restores own safety control','POST','mobility/trips/'.$trip.'/blocks',$rt,['target_user_id'=>$host->id,'blocked'=>false],$prefix.'-snapshot-unblock');
        }
        $chatPath='mobility/trips/'.$trip.'/messages';
        expectHttp($prefix.' outsider chat denied','GET',$chatPath,$ot,null,null,[403]);
        $sent=$prefix==='car-direct'?duplicateChat($trip,$ht,$prefix.'-chat'):expectHttp($prefix.' canonical in-app message','POST',$chatPath,$ht,['text'=>'Synthetic duplicate chat'],$prefix.'-chat');
        $messageId=(int)$sent['data']['id'];
        $conversationId=DB::table('mobility_trip_conversations')->where('trip_id',$trip)->value('conversation_id');
        proof($prefix.' canonical message and single receipt',DB::table('messages')->where('conversation_id',$conversationId)->count()===1&&DB::table('mobility_communication_receipts')->where('trip_id',$trip)->where('kind','send')->count()===1);
        $notifications=expectHttp($prefix.' in-app unread notification','GET','mobility/notifications',$rt);
        proof($prefix.' scoped unread count',$notifications['data']['unread_messages']===1&&count($notifications['data']['trips'])===1);
        expectHttp($prefix.' exact chat replay','POST',$chatPath,$ht,['text'=>'Synthetic duplicate chat'],$prefix.'-chat');
        expectHttp($prefix.' changed chat retry conflicts','POST',$chatPath,$ht,['text'=>'Changed synthetic text'],$prefix.'-chat',[409]);
        expectHttp($prefix.' participant message read','POST',$chatPath.'/read',$rt,['through_id'=>$messageId]);
        [$chatCode,$chatBody,$chatHeaders]=http('GET',$chatPath,$rt);
        proof($prefix.' private chat no-store and no automatic location content',$chatCode===200&&str_contains($chatHeaders,'no-store')&&$chatBody['data']['messages'][0]['text']==='Synthetic duplicate chat'&&!isset($chatBody['data']['location']));
        expectHttp($prefix.' rider safety block','POST','mobility/trips/'.$trip.'/blocks',$rt,['target_user_id'=>$host->id,'blocked'=>true],$prefix.'-block');
        expectHttp($prefix.' blocked driver message denied','POST',$chatPath,$ht,['text'=>'Blocked synthetic message'],$prefix.'-blocked-chat',[403]);
        expectHttp($prefix.' legacy chat bypass denied','POST','conversations/'.$conversationId.'/send',$ht,['message'=>'Legacy bypass'],null,[409]);
        foreach(['arrive','start','board','alight','complete'] as $action)expectHttp($prefix.' lifecycle '.$action,'POST','mobility/trips/'.$trip.'/transitions',$ht,['action'=>$action]+(in_array($action,['board','alight'],true)?['passenger_id'=>$rider->id]:[]),$prefix.'-'.$action);
        expectHttp($prefix.' terminal chat remains read-only','POST',$chatPath,$ht,['text'=>'After completion'],$prefix.'-terminal-chat',[409]);
        expectHttp($prefix.' safety unblock after trip','POST','mobility/trips/'.$trip.'/blocks',$rt,['target_user_id'=>$host->id,'blocked'=>false],$prefix.'-unblock');
        expectHttp($prefix.' terminal tracking stopped','GET','mobility/trips/'.$trip.'/tracking',$rt,null,null,[410]);
    }
    $report['all_checks_passed']=true;
}catch(Throwable $error){$report['failure']=get_class($error).': '.$error->getMessage();}
finally{if(is_resource($peer)){proc_terminate($peer);proc_close($peer);}echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";}
exit($report['all_checks_passed']?0:1);
