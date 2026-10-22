<?php
declare(strict_types=1);

namespace MobilityLifecycleProbe;

use RuntimeException;
use Throwable;

require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use STS\Mobility\Payments\PaymentException;
use STS\Mobility\Payments\PaymentService;
use STS\Mobility\Providers\RazorpayCaptureVerifier;

$report = ['started_at'=>gmdate(DATE_ATOM),'probe_sha256'=>hash_file('sha256',__FILE__),
    'method'=>'HTTP/JWT requests and persistent MySQL checks against the same extended Carpoolear authority',
    'fixtures'=>['Synthetic users, locally read activation tokens, seeded vehicle catalog and cached route metrics.',
        'Synthetic operator account created by trusted test fixture; public registration cannot grant admin.',
        'Locally signed synthetic UPI capture and trusted beneficiary fixtures exercise the financial core only.',
        'No provider endpoint, real passenger, money, payout, notifications or native application tested.'],
    'checks'=>[]];

function callApi(string $method,string $path,array $body=[],?string $token=null,?string $key=null):array {
    $headers=['Accept: application/json','Content-Type: application/json'];
    if($token)$headers[]='Authorization: Bearer '.$token;
    if($key)$headers[]='Idempotency-Key: '.$key;
    $options=['method'=>$method,'header'=>implode("\r\n",$headers),'ignore_errors'=>true,'timeout'=>15];
    if($method!=='GET')$options['content']=json_encode($body,JSON_THROW_ON_ERROR);
    $raw=@file_get_contents('http://127.0.0.1:8000/api/'.$path,false,stream_context_create(['http'=>$options]));
    $status=0;foreach($http_response_header??[] as $line)if(preg_match('/^HTTP\/\S+ (\d+)/',$line,$m))$status=(int)$m[1];
    return ['status'=>$status,'body'=>json_decode($raw?:'',true)];
}
function check(string $name,bool $pass,array $observed=[]):void {
    global $report;$report['checks'][]=['name'=>$name,'passed'=>$pass,'observed'=>$observed];
    if(!$pass)throw new RuntimeException($name.' failed: '.json_encode($observed));
}
function expect(string $name,array $response,int $status=200):array {
    check($name,$response['status']===$status,['status'=>$response['status'],'expected'=>$status,
        'error_code'=>is_array($response['body']['error']??null)?($response['body']['error']['code']??null):null]);
    return $response['body']??[];
}
function actor(string $role,string $run):array {
    $email="lifecycle-$role-$run@example.test";$password='synthetic-'.bin2hex(random_bytes(8));
    expect('register '.$role,callApi('POST','users',['name'=>'Synthetic '.$role,'email'=>$email,
        'password'=>$password,'password_confirmation'=>$password,'description'=>'Synthetic local lifecycle fixture',
        'image'=>'synthetic.png','nro_doc'=>'90000'.random_int(100,999),'mobile_phone'=>'0000000000']));
    $user=User::where('email',$email)->firstOrFail();
    expect('activate '.$role,callApi('POST','activate/'.$user->activation_token));
    $login=expect('login '.$role,callApi('POST','login',['email'=>$email,'password'=>$password]));
    return ['id'=>(int)$user->id,'token'=>$login['token']];
}
function transition(int $trip,array $who,string $action,string $key,?int $passenger=null):array {
    return callApi('POST',"mobility/trips/$trip/transitions",array_filter(['action'=>$action,'passenger_id'=>$passenger],fn($v)=>$v!==null),$who['token'],$key);
}
function canonical(array $value):array {
    if (!array_is_list($value)) ksort($value);
    foreach($value as &$item) if(is_array($item))$item=canonical($item);
    return $value;
}
function simultaneousStart(int $trip,array $owner):array {
    DB::disconnect();
    $files=[];$children=[];$start=microtime(true)+0.15;
    for($i=0;$i<2;$i++) {
        $file=tempnam('/tmp','mobility-start-');$files[]=$file;
        $pid=pcntl_fork();
        if($pid===-1)throw new RuntimeException('Cannot fork lifecycle retry clients');
        if($pid===0) {
            while(microtime(true)<$start)usleep(500);
            file_put_contents($file,json_encode(transition($trip,$owner,'start','start-1'),JSON_THROW_ON_ERROR));
            exit(0);
        }
        $children[]=$pid;
    }
    foreach($children as $pid){pcntl_waitpid($pid,$status);if(!pcntl_wifexited($status)||pcntl_wexitstatus($status)!==0)throw new RuntimeException('Lifecycle retry client failed');}
    $responses=[];foreach($files as $file){$responses[]=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);unlink($file);}
    return $responses;
}
function simultaneousDropoff(int $trip,array $rider):array {
    DB::disconnect();
    $files=[];$children=[];$start=microtime(true)+0.15;
    for($i=0;$i<2;$i++) {
        $file=tempnam('/tmp','mobility-dropoff-');$files[]=$file;
        $pid=pcntl_fork();
        if($pid===-1)throw new RuntimeException('Cannot fork drop-off retry clients');
        if($pid===0) {
            while(microtime(true)<$start)usleep(500);
            file_put_contents($file,json_encode(callApi('POST',"mobility/trips/$trip/dropoff-confirmation",[],$rider['token'],'dropoff-1'),JSON_THROW_ON_ERROR));
            exit(0);
        }
        $children[]=$pid;
    }
    foreach($children as $pid){pcntl_waitpid($pid,$status);if(!pcntl_wifexited($status)||pcntl_wexitstatus($status)!==0)throw new RuntimeException('Drop-off retry client failed');}
    $responses=[];foreach($files as $file){$responses[]=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);unlink($file);}
    return $responses;
}
function simultaneousRelease(int $tripId,int $bookingId):array {
    DB::disconnect();$files=[];$children=[];$start=microtime(true)+0.15;
    for($i=0;$i<2;$i++) {
        $file=tempnam('/tmp','mobility-release-');$files[]=$file;$pid=pcntl_fork();
        if($pid===-1)throw new RuntimeException('Cannot fork payout eligibility clients');
        if($pid===0) {
            while(microtime(true)<$start)usleep(500);
            try {
                $outbox=app(PaymentService::class)->enqueueRelease(Trip::findOrFail($tripId),Passenger::findOrFail($bookingId));
                file_put_contents($file,json_encode(['id'=>$outbox->id,'allocation_id'=>$outbox->allocation_id,'status'=>$outbox->status],JSON_THROW_ON_ERROR));
            } catch(Throwable $e) {file_put_contents($file,json_encode(['error'=>$e->getMessage()]));exit(1);}
            exit(0);
        }
        $children[]=$pid;
    }
    foreach($children as $pid){pcntl_waitpid($pid,$status);if(!pcntl_wifexited($status)||pcntl_wexitstatus($status)!==0)throw new RuntimeException('Payout eligibility client failed');}
    $responses=[];foreach($files as $file){$responses[]=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);unlink($file);}
    return $responses;
}
function simultaneousCapture(array $capture):array {
    DB::disconnect();$files=[];$children=[];$start=microtime(true)+0.15;
    for($i=0;$i<2;$i++) {
        $file=tempnam('/tmp','mobility-capture-');$files[]=$file;$pid=pcntl_fork();
        if($pid===-1)throw new RuntimeException('Cannot fork capture retry clients');
        if($pid===0) {
            while(microtime(true)<$start)usleep(500);
            try {
                $attempt=app(PaymentService::class)->ingestCapturedPayment($capture);
                file_put_contents($file,json_encode(['id'=>$attempt->id,'status'=>$attempt->status,'payment_reference'=>$attempt->payment_reference],JSON_THROW_ON_ERROR));
            } catch(Throwable $e) {file_put_contents($file,json_encode(['error'=>$e->getMessage()]));exit(1);}
            exit(0);
        }
        $children[]=$pid;
    }
    foreach($children as $pid){pcntl_waitpid($pid,$status);if(!pcntl_wifexited($status)||pcntl_wexitstatus($status)!==0)throw new RuntimeException('Capture retry client failed');}
    $responses=[];foreach($files as $file){$responses[]=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);unlink($file);}
    return $responses;
}
function paymentRejected(string $name,callable $call,string $code):void {
    try {$call();check($name,false);} catch(PaymentException $e) {check($name,$e->codeName===$code,['code'=>$e->codeName]);}
}
function simultaneousCash(int $trip,int $booking,array $rider,array $driver):array {
    DB::disconnect();$files=[];$children=[];$start=microtime(true)+0.15;
    foreach([['actor'=>$rider,'action'=>'rider_paid','key'=>'cash-rider'],['actor'=>$driver,'action'=>'driver_received','key'=>'cash-driver']] as $item) {
        $file=tempnam('/tmp','mobility-cash-');$files[]=$file;$pid=pcntl_fork();
        if($pid===-1)throw new RuntimeException('Cannot fork cash acknowledgement clients');
        if($pid===0) {
            while(microtime(true)<$start)usleep(500);
            file_put_contents($file,json_encode(callApi('POST',"mobility/trips/$trip/bookings/$booking/cash-receipts",['action'=>$item['action']],$item['actor']['token'],$item['key']),JSON_THROW_ON_ERROR));
            exit(0);
        }
        $children[]=$pid;
    }
    foreach($children as $pid){pcntl_waitpid($pid,$status);if(!pcntl_wifexited($status)||pcntl_wexitstatus($status)!==0)throw new RuntimeException('Cash acknowledgement client failed');}
    $responses=[];foreach($files as $file){$responses[]=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);unlink($file);}
    return $responses;
}

try {
    $run=bin2hex(random_bytes(5));$host=actor('host',$run);$guest=actor('guest',$run);$other=actor('other',$run);
    $operatorPassword='synthetic-op-'.bin2hex(random_bytes(8));
    $operator=User::factory()->create(['email'=>"lifecycle-operator-$run@example.test",'password'=>Hash::make($operatorPassword)]);
    $operator->forceFill(['is_admin'=>true])->save();
    $opLogin=expect('operator logs in',callApi('POST','login',['email'=>$operator->email,'password'=>$operatorPassword]));
    $op=['id'=>(int)$operator->id,'token'=>$opLogin['token']];
    $color=\STS\Models\CarColor::firstOrCreate(['slug'=>'lifecycle-fixture'],['name'=>'Synthetic','hex'=>'#808080','is_active'=>true,'sort_order'=>0]);
    expect('host registers vehicle',callApi('POST','cars',['patente'=>'LIF'.random_int(100,999),'brand_other'=>'Fixture',
        'model_other'=>'Model','car_color_id'=>$color->id,'year'=>2020],$host['token']));
    $points=[['address'=>'Synthetic A','json_address'=>['name'=>'A','state'=>'Test'],'lat'=>10.0,'lng'=>20.0],
        ['address'=>'Synthetic B','json_address'=>['name'=>'B','state'=>'Test'],'lat'=>10.1,'lng'=>20.1]];
    \STS\Models\RouteCache::updateOrCreate(['hashed_points'=>hash('sha256',json_encode($points))],[
        'points'=>$points,'route_data'=>['status'=>true,'data'=>['distance'=>50000,'duration'=>3600,'recommended_trip_price_cents'=>500],'message'=>'OK'],
        'expires_at'=>now()->addDay()]);
    $payload=['is_passenger'=>0,'from_town'=>'Synthetic A','to_town'=>'Synthetic B','punto_partida'=>'Start','punto_llegada'=>'End',
        'trip_date'=>now()->addDays(2)->format('Y-m-d H:i:s'),'total_seats'=>1,'friendship_type_id'=>2,
        'estimated_time'=>'01:00','distance'=>50,'co2'=>1,'description'=>'Lifecycle integration fixture','points'=>$points];
    $created=expect('host offers trip',callApi('POST','trips',$payload,$host['token']));$id=(int)$created['data']['id'];
    expect('guest requests seat',callApi('POST',"trips/$id/requests",[],$guest['token']));
    expect('host accepts guest',callApi('POST',"trips/$id/requests/{$guest['id']}/accept",[],$host['token']));
    $money=app(PaymentService::class);$moneyTrip=Trip::findOrFail($id);
    $moneyBooking=Passenger::where('trip_id',$id)->where('user_id',$guest['id'])->where('request_state',Passenger::STATE_ACCEPTED)->firstOrFail();
    $money->snapshotFare($moneyTrip,$moneyBooking,15000,1500);
    $attempt=$money->createPaymentAttempt($moneyTrip,$moneyBooking,'razorpay','test','order_Synthetic'.$run);
    $rawCapture=json_encode(['entity'=>'event','account_id'=>'acc_Synthetic','event'=>'payment.captured',
        'payload'=>['payment'=>['entity'=>['entity'=>'payment','id'=>'pay_Synthetic'.$run,'order_id'=>$attempt->order_reference,
        'amount'=>15000,'currency'=>'INR','status'=>'captured','captured'=>true,'method'=>'upi','amount_refunded'=>0,'amount_transferred'=>0]]]],JSON_THROW_ON_ERROR);
    $syntheticSecret='synthetic-webhook-probe-secret-no-provider';
    $verifier=new RazorpayCaptureVerifier($syntheticSecret,'test','acc_Synthetic');
    paymentRejected('forged UPI callback rejected',fn()=>$verifier->verify($rawCapture,str_repeat('0',64),'evt_'.$run),'WEBHOOK_SIGNATURE_INVALID');
    check('forged callback creates no financial postings',DB::table('mobility_financial_postings')->where('booking_id',$moneyBooking->id)->count()===0);
    $capture=$verifier->verify($rawCapture,hash_hmac('sha256',$rawCapture,$syntheticSecret),'evt_'.$run);
    $captureRetries=simultaneousCapture($capture);
    check('simultaneous capture retry returns one payment',$captureRetries[0]===$captureRetries[1]&&$captureRetries[0]['status']==='captured');
    $money->ingestCapturedPayment($capture);
    $anotherEvent=$capture;$anotherEvent['event_id']='evt_second_'.$run;$money->ingestCapturedPayment($anotherEvent);
    check('duplicate capture events credit funds once',DB::table('mobility_financial_postings')->where('booking_id',$moneyBooking->id)->count()===2);
    $money->registerBeneficiary($host['id'],'razorpay','test','beneficiary_Synthetic'.$run,true,true);
    paymentRejected('captured payment cannot release before trip',fn()=>$money->enqueueRelease($moneyTrip,$moneyBooking),'PAYOUT_EARLY');
    expect('unauthenticated snapshot denied',callApi('GET',"mobility/trips/$id/lifecycle"),401);
    expect('outsider snapshot denied',callApi('GET',"mobility/trips/$id/lifecycle",[],$other['token']),403);
    expect('guest snapshot available',callApi('GET',"mobility/trips/$id/lifecycle",[],$guest['token']));
    expect('unauthenticated drop-off denied',callApi('POST',"mobility/trips/$id/dropoff-confirmation",[],null,'dropoff-1'),401);
    expect('drop-off requires retry key',callApi('POST',"mobility/trips/$id/dropoff-confirmation",[],$guest['token']),422);
    expect('guest cannot confirm before trip',callApi('POST',"mobility/trips/$id/dropoff-confirmation",[],$guest['token'],'early'),409);
    expect('outsider cannot confirm drop-off',callApi('POST',"mobility/trips/$id/dropoff-confirmation",[],$other['token'],'outsider'),403);
    expect('nonoperator audit denied',callApi('GET',"mobility/operator/trips/$id",[],$host['token']),403);
    expect('missing idempotency key rejected',callApi('POST',"mobility/trips/$id/transitions",['action'=>'start'],$host['token']),422);
    expect('guest start denied',transition($id,$guest,'start','guest-start'),403);
    expect('invalid first completion denied',transition($id,$host,'complete','invalid-first'),409);
    check('invalid first action creates no lifecycle row',!DB::table('mobility_trip_lifecycles')->where('trip_id',$id)->exists());
    check('rejected calls create no audit events',DB::table('mobility_trip_events')->where('trip_id',$id)->count()===0);
    $simultaneous=simultaneousStart($id,$host);
    $started=expect('first simultaneous host start', $simultaneous[0]);
    $sameStart=expect('second simultaneous host start', $simultaneous[1]);
    check('simultaneous same-key start gives one durable event',canonical($started)===canonical($sameStart)
        &&DB::table('mobility_trip_events')->where('trip_id',$id)->count()===1);
    check('started state persisted',$started['state']==='in_progress'&&$started['version']===1);
    $replay=expect('start retry replays',transition($id,$host,'start','start-1'));
    check('start replay returns identical response',canonical($replay)===canonical($started));
    expect('idempotency conflict rejected',transition($id,$host,'complete','start-1'),409);
    expect('completion blocked before boarding',transition($id,$host,'complete','too-early'),409);
    expect('legacy guest cancellation blocked',callApi('POST',"trips/$id/requests/{$guest['id']}/cancel",[],$guest['token']),409);
    expect('legacy new request blocked',callApi('POST',"trips/$id/requests",[],$other['token']),409);
    expect('legacy seat edit blocked',callApi('POST',"trips/$id/changeSeats",['increment'=>1],$host['token']),409);
    expect('legacy trip edit blocked',callApi('PUT',"trips/$id",$payload,$host['token']),409);
    expect('legacy trip deletion blocked',callApi('DELETE',"trips/$id",[],$host['token']),409);
    $offers=expect('discovery excludes started trip',callApi('GET','trips?page_size=100',[],$other['token']));
    check('started trip absent from available offers',!in_array($id,array_column($offers['data']??[],'id')));
    expect('alight before board rejected',transition($id,$host,'alight','bad-alight',$guest['id']),409);
    expect('unbooked passenger boarding rejected',transition($id,$host,'board','bad-board',$other['id']),409);
    expect('host boards accepted guest',transition($id,$host,'board','board-1',$guest['id']));
    expect('guest cannot confirm while onboard',callApi('POST',"mobility/trips/$id/dropoff-confirmation",[],$guest['token'],'early'),409);
    expect('second board action rejected',transition($id,$host,'board','board-again',$guest['id']),409);
    expect('completion blocked with guest onboard',transition($id,$host,'complete','onboard-complete'),409);
    expect('host alights guest',transition($id,$host,'alight','alight-1',$guest['id']));
    expect('host cannot spoof rider confirmation',callApi('POST',"mobility/trips/$id/dropoff-confirmation",['user_id'=>$guest['id']],$host['token'],'spoof'),403);
    expect('operator cannot override rider confirmation',callApi('POST',"mobility/trips/$id/dropoff-confirmation",['user_id'=>$guest['id']],$op['token'],'spoof'),403);
    check('driver alighting alone creates no rider proof',DB::table('mobility_dropoff_confirmations')->where('trip_id',$id)->count()===0);
    paymentRejected('driver alight alone cannot release funds',fn()=>$money->enqueueRelease($moneyTrip,$moneyBooking),'PAYOUT_EARLY');
    $confirmations=simultaneousDropoff($id,$guest);
    $proof=expect('first simultaneous rider confirmation',$confirmations[0]);
    $proofRetry=expect('second simultaneous rider confirmation',$confirmations[1]);
    check('rider confirmation retry gives one immutable receipt',canonical($proof)===canonical($proofRetry)
        &&DB::table('mobility_dropoff_confirmations')->where('trip_id',$id)->count()===1
        &&$proof['user_id']===$guest['id']);
    expect('rider confirmation cannot be overwritten',callApi('POST',"mobility/trips/$id/dropoff-confirmation",[],$guest['token'],'replace'),409);
    expect('reboarding after drop denied',transition($id,$host,'board','reboard',$guest['id']),409);
    $share=\STS\Models\TripLiveShare::factory()->create(['trip_id'=>$id,'user_id'=>$host['id'],'is_active'=>true,
        'lat'=>10.0,'lng'=>20.0,'recorded_at'=>now(),'stopped_at'=>null]);
    expect('active synthetic public share visible',callApi('GET','live/'.$share->share_token));
    $completed=expect('host completes trip',transition($id,$host,'complete','complete-1'));
    $share->refresh();
    check('completion stops sharing and clears coordinates',!$share->is_active&&$share->lat===null&&$share->lng===null&&$share->recorded_at===null&&$share->stopped_at!==null);
    expect('inactive public share revoked',callApi('GET','live/'.$share->share_token),404);
    expect('terminal trip sharing restart blocked',callApi('POST',"trips/$id/live-share/start",[],$host['token']),409);
    expect('terminal trip location update blocked',callApi('PUT',"trips/$id/live-share/location",['lat'=>10,'lng'=>20],$host['token']),409);
    check('completion persisted',$completed['state']==='completed'&&$completed['version']===4);
    $release=simultaneousRelease($id,$moneyBooking->id);
    check('simultaneous payout eligibility creates one durable command',$release[0]===$release[1]
        &&DB::table('mobility_payout_allocations')->where('booking_id',$moneyBooking->id)->count()===1
        &&DB::table('mobility_payout_outbox')->where('allocation_id',$release[0]['allocation_id'])->count()===1);
    $ledger=DB::table('mobility_financial_postings')->where('booking_id',$moneyBooking->id);
    check('financial postings balance after driver allocation',(int)(clone $ledger)->where('side','debit')->sum('amount')===(int)(clone $ledger)->where('side','credit')->sum('amount'));
    check('driver receives allocated earnings and commission stays explicit',(int)(clone $ledger)->where('account','driver_payable')->sum('amount')===13500
        &&(int)(clone $ledger)->where('account','platform_fee_pending')->sum('amount')===1500);
    $money->blockRefundOrDispute($moneyTrip,$moneyBooking,'dispute');
    check('dispute blocks a queued payout command',DB::table('mobility_payout_outbox')->where('id',$release[0]['id'])->value('status')==='blocked');
    paymentRejected('blocked payout cannot be re-enqueued',fn()=>$money->enqueueRelease($moneyTrip,$moneyBooking),'PAYOUT_BLOCKED');
    $riderPayments=expect('rider reads own payment history',callApi('GET',"mobility/trips/$id/payments",[],$guest['token']));
    check('rider sees own test collection without provider references',count($riderPayments['bookings'])===1
        &&$riderPayments['bookings'][0]['booking_id']===$moneyBooking->id
        &&$riderPayments['bookings'][0]['provider_collections'][0]['environment']==='test'
        &&!array_key_exists('order_reference',$riderPayments['bookings'][0]['provider_collections'][0])
        &&!array_key_exists('rider_id',$riderPayments['bookings'][0]));
    $driverPayments=expect('driver reads trip earnings',callApi('GET',"mobility/trips/$id/payments",[],$host['token']));
    check('driver sees blocked allocation and no settlement claim',$driverPayments['bookings'][0]['allocated_driver_amount']===13500
        &&$driverPayments['bookings'][0]['driver_payout_status']==='blocked'&&$driverPayments['bookings'][0]['settlement_status']==='not_verified');
    expect('outsider cannot read financial history',callApi('GET',"mobility/trips/$id/payments",[],$other['token']),403);
    expect('rider cannot read operator financial audit',callApi('GET',"mobility/operator/trips/$id/payments",[],$guest['token']),403);
    $operatorPayments=expect('operator reads financial audit',callApi('GET',"mobility/operator/trips/$id/payments",[],$op['token']));
    check('operator sees provider reference and blocking reason',$operatorPayments['bookings'][0]['provider_collections'][0]['order_reference']===$attempt->order_reference
        &&$operatorPayments['bookings'][0]['block_reasons']===['dispute']);
    expect('financial history bounds page size',callApi('GET',"mobility/trips/$id/payments?page_size=101",[],$host['token']),422);
    check('rider receipt replays after completion',canonical(expect('post-completion rider receipt retry',callApi('POST',"mobility/trips/$id/dropoff-confirmation",[],$guest['token'],'dropoff-1')))===canonical($proof));
    expect('terminal trip rejects new start',transition($id,$host,'start','start-again'),409);
    check('old retry remains identical after completion',canonical(expect('old start retry after completion',transition($id,$host,'start','start-1')))===canonical($started));
    $audit=expect('operator inspects trip',callApi('GET',"mobility/operator/trips/$id",[],$op['token']));
    check('operator sees contiguous audit versions',array_column($audit['events'],'version')===[1,2,3,4]);
    check('operator sees expected trip actions',array_column($audit['events'],'action')===['start','board','alight','complete']);
    check('failed operations and retries add no events',DB::table('mobility_trip_events')->where('trip_id',$id)->count()===4);
    $historyFalse=expect('false history flag does not expose completed offers',callApi('GET','trips?history=0&page_size=100',[],$other['token']));
    check('completed trip absent with history false',!in_array($id,array_column($historyFalse['data']??[],'id')));
    $payload['trip_date']=now()->addDays(3)->format('Y-m-d H:i:s');
    $created=expect('host offers cancellation trip',callApi('POST','trips',$payload,$host['token']));$cancelId=(int)$created['data']['id'];
    expect('guest requests cancellation trip',callApi('POST',"trips/$cancelId/requests",[],$guest['token']));
    expect('host accepts cancellation guest',callApi('POST',"trips/$cancelId/requests/{$guest['id']}/accept",[],$host['token']));
    $cancelShare=\STS\Models\TripLiveShare::factory()->create(['trip_id'=>$cancelId,'user_id'=>$host['id'],'is_active'=>true,'lat'=>10,'lng'=>20,'recorded_at'=>now(),'stopped_at'=>null]);
    $cancelled=expect('host cancels planned trip',transition($cancelId,$host,'cancel','cancel-1'));
    check('cancel state persisted',$cancelled['state']==='cancelled');
    check('cancel stops sharing and clears coordinates',!$cancelShare->fresh()->is_active&&$cancelShare->fresh()->lat===null);
    expect('cancel revokes public share',callApi('GET','live/'.$cancelShare->share_token),404);
    check('cancellation releases bookings',Passenger::where('trip_id',$cancelId)->whereIn('request_state',[0,1,4])->count()===0);
    $cancelView=expect('cancelled guest can recover own outcome',callApi('GET',"mobility/trips/$cancelId/lifecycle",[],$guest['token']));
    check('cancelled guest sees only own booking', $cancelView['state']==='cancelled'&&count($cancelView['participants'])===1
        &&$cancelView['participants'][0]['passenger_id']===$guest['id']&&$cancelView['participants'][0]['booking_state']===Passenger::STATE_CANCELED);
    expect('unrelated actor still cannot inspect cancellation',callApi('GET',"mobility/trips/$cancelId/lifecycle",[],$other['token']),403);
    check('cancel retry replays',canonical(expect('repeat cancellation',transition($cancelId,$host,'cancel','cancel-1')))===canonical($cancelled));
    expect('cancelled trip cannot start',transition($cancelId,$host,'start','cancel-start'),409);
    expect('cancelled trip blocks legacy request',callApi('POST',"trips/$cancelId/requests",[],$other['token']),409);
    expect('operator inspects cancelled trip',callApi('GET',"mobility/operator/trips/$cancelId",[],$op['token']));
    $payload['trip_date']=now()->addDays(4)->format('Y-m-d H:i:s');
    $cashCreated=expect('host offers cash trip',callApi('POST','trips',$payload,$host['token']));$cashId=(int)$cashCreated['data']['id'];
    expect('guest requests cash trip',callApi('POST',"trips/$cashId/requests",[],$guest['token']));
    expect('host accepts cash guest',callApi('POST',"trips/$cashId/requests/{$guest['id']}/accept",[],$host['token']));
    $cashTrip=Trip::findOrFail($cashId);$cashBooking=Passenger::where('trip_id',$cashId)->where('user_id',$guest['id'])->firstOrFail();
    $money->snapshotFare($cashTrip,$cashBooking,15000,1500);$cashPath="mobility/trips/$cashId/bookings/{$cashBooking->id}/cash-receipts";
    expect('cash receipt denied before start',callApi('POST',$cashPath,['action'=>'rider_paid'],$guest['token'],'early-cash'),422);
    expect('host starts cash trip',transition($cashId,$host,'start','cash-start'));
    expect('host boards cash guest',transition($cashId,$host,'board','cash-board',$guest['id']));
    expect('driver cannot impersonate cash rider',callApi('POST',$cashPath,['action'=>'rider_paid','user_id'=>$guest['id']],$host['token'],'spoof'),403);
    expect('operator cannot impersonate cash rider',callApi('POST',$cashPath,['action'=>'rider_paid','user_id'=>$guest['id']],$op['token'],'spoof'),403);
    expect('cash action validates type',callApi('POST',$cashPath,['action'=>['rider_paid']],$guest['token'],'invalid'),422);
    $cashResponses=simultaneousCash($cashId,$cashBooking->id,$guest,$host);
    $riderCash=expect('simultaneous rider cash acknowledgement',$cashResponses[0]);
    expect('simultaneous driver cash acknowledgement',$cashResponses[1]);
    $cashRecord=DB::table('mobility_cash_receipts')->where('booking_id',$cashBooking->id)->first();
    check('cash race gives one two-party receipt',$cashRecord&&$cashRecord->rider_confirmed_at&&$cashRecord->driver_confirmed_at
        &&DB::table('mobility_cash_receipts')->where('booking_id',$cashBooking->id)->count()===1
        &&DB::table('mobility_cash_receipt_events')->where('booking_id',$cashBooking->id)->count()===2);
    paymentRejected('cash selection blocks provider checkout',fn()=>$money->createPaymentAttempt($cashTrip,$cashBooking,'razorpay','test','order_forbidden'),'PAYMENT_METHOD_CONFLICT');
    paymentRejected('cash cannot enter provider payout',fn()=>$money->enqueueRelease($cashTrip,$cashBooking),'PAYMENT_METHOD_CONFLICT');
    $cashView=expect('cash driver reads confirmed receipt',callApi('GET',"mobility/trips/$cashId/payments",[],$host['token']));
    check('cash is shown separately from provider settlement',$cashView['bookings'][0]['collection_state']==='cash_confirmed'
        &&$cashView['bookings'][0]['driver_payout_status']==='not_applicable_cash'
        &&$cashView['bookings'][0]['cash_commission_status']==='not_settled');
    expect('host alights cash guest',transition($cashId,$host,'alight','cash-alight',$guest['id']));
    expect('cash guest acknowledges drop-off',callApi('POST',"mobility/trips/$cashId/dropoff-confirmation",[],$guest['token'],'cash-dropoff'));
    expect('host completes cash trip',transition($cashId,$host,'complete','cash-complete'));
    $completedCashView=expect('completed cash history remains available',callApi('GET',"mobility/trips/$cashId/payments",[],$guest['token']));
    check('completed cash trip still requires no provider settlement',$completedCashView['bookings'][0]['collection_state']==='cash_confirmed'
        &&$completedCashView['bookings'][0]['settlement_status']==='not_applicable_cash');
    $disputedCash=expect('rider disputes cash receipt',callApi('POST',$cashPath,['action'=>'dispute'],$guest['token'],'cash-dispute'));
    check('cash dispute response is durable',$disputedCash['status']==='disputed'&&$disputedCash['disputed_at']!==null);
    check('old cash retry preserves original acknowledgement',canonical(expect('cash retry after dispute',callApi('POST',$cashPath,['action'=>'rider_paid'],$guest['token'],'cash-rider')))===canonical($riderCash));
    expect('different-key duplicate cash action rejected',callApi('POST',$cashPath,['action'=>'rider_paid'],$guest['token'],'cash-again'),422);
    check('cash creates no provider funds or payout',DB::table('mobility_payment_attempts')->where('booking_id',$cashBooking->id)->count()===0
        &&DB::table('mobility_financial_postings')->where('booking_id',$cashBooking->id)->count()===0
        &&DB::table('mobility_payout_allocations')->where('booking_id',$cashBooking->id)->count()===0);
    $report['completed']=true;
}catch(Throwable $e){$report['completed']=false;$report['failure']=$e->getMessage();}
$report['finished_at']=gmdate(DATE_ATOM);
file_put_contents('/opt/mobility/results/lifecycle-latest.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($report['completed']?0:1);
