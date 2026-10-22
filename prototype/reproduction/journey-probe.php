<?php
declare(strict_types=1);

// Evaluation only: actual HTTP + MySQL, synthetic actors, no provider credentials.
require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\RouteCache;
use STS\Models\Trip;
use STS\Models\User;

$report = [
    'source_revision' => '6ba3d8913a8f77e664e848a526f196690a03c1e8',
    'probe_sha256' => hash_file('sha256', __FILE__),
    'variant' => getenv('MOBILITY_CARPOOLEAR_VARIANT') ?: 'pristine',
    'applied_patch_sha256' => getenv('MOBILITY_PATCH_DIGEST') ?: null,
    'started_at' => gmdate(DATE_ATOM),
    'method' => 'Actual local HTTP requests and durable MySQL observations; no business-service mocks',
    'fixture_boundaries' => [
        'Activation tokens read from synthetic local accounts instead of delivered email.',
        'One synthetic route-cache entry supplies routing/pricing; no real routing provider tested.',
        'One synthetic active vehicle color seeds otherwise empty reference catalog data.',
        'No identity provider, real payment, host payout or real trip is exercised.',
        'Native clients and production HTTP server are not exercised.',
    ],
    'steps' => [], 'checks' => [], 'gaps' => [],
];

function httpCall(string $method, string $path, array $data = [], ?string $token = null): array
{
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer '.$token;
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers),
        'ignore_errors' => true, 'timeout' => 20]];
    if ($method !== 'GET') $options['http']['content'] = json_encode($data, JSON_THROW_ON_ERROR);
    $body = @file_get_contents('http://127.0.0.1:8000/api/'.$path, false, stream_context_create($options));
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^HTTP\/\S+ (\d+)/', $line, $m)) $status = (int) $m[1];
    }
    return ['status' => $status, 'json' => json_decode($body ?: '', true)];
}

function step(string $name, array $response, int $expected = 200): array
{
    global $report;
    $report['steps'][] = ['name' => $name, 'status' => $response['status'], 'expected' => $expected];
    if ($response['status'] !== $expected) {
        // Only error names, never raw responses, tokens, activation links or profiles.
        $errorKeys = array_keys($response['json']['errors'] ?? []);
        $codes = [];
        $errors = $response['json']['errors'] ?? [];
        array_walk_recursive($errors, static function ($value) use (&$codes) {
            if (is_string($value) && preg_match('/^[a-z_]{1,60}$/', $value)) $codes[] = $value;
        });
        throw new RuntimeException($name.' returned '.$response['status'].'; error keys: '.implode(',', $errorKeys).'; codes: '.implode(',', $codes));
    }
    return $response['json'] ?? [];
}

function verify(string $name, bool $passed, array $observation = []): void
{
    global $report;
    $report['checks'][] = ['name' => $name, 'passed' => $passed, 'observation' => $observation];
}

function registerActor(string $role, string $run): array
{
    $email = 'journey-'.$role.'-'.$run.'@example.test';
    $password = 'local-fixture-'.bin2hex(random_bytes(8));
    step('register '.$role, httpCall('POST', 'users', [
        'name' => 'Synthetic '.$role, 'email' => $email,
        'password' => $password, 'password_confirmation' => $password,
        'description' => 'Synthetic local evaluation account', 'image' => 'synthetic-fixture.png',
        'nro_doc' => '90000'.random_int(100,999), 'mobile_phone' => '0000000000',
        'terms_and_conditions' => true,
    ]));
    $user = User::query()->where('email', $email)->firstOrFail();
    if (!$user->activation_token) throw new RuntimeException('Missing synthetic activation token');
    step('activate '.$role, httpCall('POST', 'activate/'.$user->activation_token));
    $login = step('login '.$role, httpCall('POST', 'login', ['email' => $email, 'password' => $password]));
    if (!is_string($login['token'] ?? null)) throw new RuntimeException('Missing login token');
    step('read profile '.$role, httpCall('GET', 'users/me', [], $login['token']));
    return ['id' => (int) $user->id, 'token' => $login['token']];
}

function concurrentCalls(array $calls): array
{
    DB::disconnect();
    $start = microtime(true) + 0.15;
    $children = [];
    foreach ($calls as [$method,$path,$token]) {
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('Cannot fork contention clients');
        if ($pid === 0) {
            while (microtime(true) < $start) usleep(500);
            $response = httpCall($method,$path,[],$token);
            exit($response['status'] === 200 ? 0 : (in_array($response['status'],[409,422],true) ? 2 : 3));
        }
        $children[] = $pid;
    }
    $statuses = [];
    foreach ($children as $pid) { pcntl_waitpid($pid,$status); $statuses[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 4; }
    return $statuses;
}

try {
    $run = bin2hex(random_bytes(5));
    $host = registerActor('host', $run);
    $guest = registerActor('guest', $run);
    $other = registerActor('other', $run);
    $claimEmail = 'journey-claim-'.$run.'@example.test';
    step('registration with untrusted verification fields', httpCall('POST','users',[
        'name'=>'Synthetic untrusted claim','email'=>$claimEmail,
        'password'=>'synthetic-claim-password','password_confirmation'=>'synthetic-claim-password',
        'identity_validated'=>true,'driver_is_verified'=>true,'is_admin'=>true,
    ]));
    $claim = User::where('email',$claimEmail)->firstOrFail();
    verify('registration cannot self-assign trust or admin', !$claim->identity_validated && !$claim->driver_is_verified && !$claim->is_admin,
        ['identity_validated'=>(bool)$claim->identity_validated,'driver_is_verified'=>(bool)$claim->driver_is_verified,'is_admin'=>(bool)$claim->is_admin]);

    STS\Models\CarColor::query()->firstOrCreate(['slug' => 'synthetic-probe'], [
        'name' => 'Synthetic probe', 'hex' => '#808080', 'is_active' => true, 'sort_order' => 0,
    ]);
    $colors = step('read car catalog', httpCall('GET', 'car-colors'));
    $color = $colors['data'][0]['id'] ?? $colors[0]['id'] ?? null;
    if (!$color) throw new RuntimeException('No seeded car color');
    step('create host vehicle', httpCall('POST', 'cars', [
        'patente' => 'SYN'.random_int(100,999), 'description' => 'Synthetic fixture vehicle',
        'brand_other' => 'FixtureBrand', 'model_other' => 'FixtureModel',
        'car_color_id' => $color, 'year' => 2020,
    ], $host['token']));

    $points = [
        ['address' => 'Synthetic A', 'json_address' => ['name' => 'A', 'state' => 'Test'], 'lat' => 10.0, 'lng' => 20.0],
        ['address' => 'Synthetic B', 'json_address' => ['name' => 'B', 'state' => 'Test'], 'lat' => 10.1, 'lng' => 20.1],
    ];
    RouteCache::query()->updateOrCreate(['hashed_points' => hash('sha256', json_encode($points))], [
        'points' => $points, 'route_data' => ['status' => true, 'data' => [
            'distance' => 50000, 'duration' => 3600, 'recommended_trip_price_cents' => 500], 'message' => 'OK'],
        'expires_at' => now()->addDay(),
    ]);
    $payload = ['is_passenger' => 0, 'from_town' => 'Synthetic A', 'to_town' => 'Synthetic B',
        'punto_partida' => 'Synthetic start', 'punto_llegada' => 'Synthetic finish',
        'trip_date' => now()->addDays(2)->format('Y-m-d H:i:s'), 'total_seats' => 1,
        'friendship_type_id' => Trip::PRIVACY_PUBLIC, 'estimated_time' => '01:00',
        'distance' => 50, 'co2' => 1, 'description' => 'Synthetic platform evaluation', 'points' => $points];
    $created = step('host creates trip', httpCall('POST', 'trips', $payload, $host['token']));
    $tripId = (int) ($created['data']['id'] ?? 0);
    if (!$tripId) throw new RuntimeException('Missing created trip identifier');
    $search = step('guest discovers offers', httpCall('GET', 'trips?page_size=100', [], $guest['token']));
    verify('created trip discoverable', in_array($tripId, array_column($search['data'] ?? [], 'id')));
    step('guest requests seat', httpCall('POST', "trips/$tripId/requests", [], $guest['token']));
    $duplicate = httpCall('POST', "trips/$tripId/requests", [], $guest['token']);
    verify('duplicate active request rejected', $duplicate['status'] === 422, ['status' => $duplicate['status']]);
    step('host accepts guest', httpCall('POST', "trips/$tripId/requests/{$guest['id']}/accept", [], $host['token']));
    verify('accepted seat persisted', Passenger::where('trip_id',$tripId)->where('user_id',$guest['id'])
        ->where('request_state',Passenger::STATE_ACCEPTED)->count() === 1);
    verify('capacity consumed', (int) Trip::findOrFail($tripId)->seats_available === 0);

    // Security check against our disposable local instance, never the reference app.
    $unauthorized = httpCall('POST', "trips/$tripId/requests/{$guest['id']}/cancel", [], $other['token']);
    $stillAccepted = Passenger::where('trip_id',$tripId)->where('user_id',$guest['id'])
        ->where('request_state',Passenger::STATE_ACCEPTED)->exists();
    verify('unrelated actor cannot cancel accepted passenger', $unauthorized['status'] >= 400 && $stillAccepted,
        ['status' => $unauthorized['status'], 'accepted_row_remains' => $stillAccepted]);
    if ($stillAccepted) step('guest cancels own seat', httpCall('POST', "trips/$tripId/requests/{$guest['id']}/cancel", [], $guest['token']));
    verify('cancelled seat released', (int) Trip::findOrFail($tripId)->seats_available === 1);

    // Repeated real HTTP acceptance races; no code hooks or artificial repository delay.
    DB::disconnect();
    $trials = [];
    for ($i=0; $i<12; $i++) {
        $racePayload = $payload;
        $racePayload['trip_date'] = now()->addDays(2)->addMinutes($i+1)->format('Y-m-d H:i:s');
        $trip = step('create contention trip '.$i, httpCall('POST', 'trips', $racePayload, $host['token']));
        $id = (int) $trip['data']['id'];
        foreach ([$guest,$other] as $actor) step('contention request '.$i, httpCall('POST', "trips/$id/requests", [], $actor['token']));
        $statuses = concurrentCalls([
            ['POST',"trips/$id/requests/{$guest['id']}/accept",$host['token']],
            ['POST',"trips/$id/requests/{$other['id']}/accept",$host['token']],
        ]);
        $accepted = Passenger::where('trip_id',$id)->where('request_state',Passenger::STATE_ACCEPTED)
            ->where('passenger_type',Passenger::TYPE_PASAJERO)->count();
        $trials[] = ['trial'=>$i,'accepted'=>$accepted,'capacity'=>1,'client_exit_codes'=>$statuses];
    }
    verify('last seat has exactly one winner in sampled concurrent accepts', count(array_filter($trials,fn($t)=>$t['accepted']!==1 || count(array_filter($t['client_exit_codes'],fn($s)=>$s===0))!==1 || count(array_filter($t['client_exit_codes'],fn($s)=>$s===2))!==1))===0,
        ['trials'=>$trials,'coverage'=>'12 sampled races on a multi-worker PHP development server; passing does not prove universal concurrency safety']);
    $duplicates = [];
    for ($i=0;$i<8;$i++) {
        $duplicatePayload = $payload;
        $duplicatePayload['trip_date'] = now()->addDays(3)->addMinutes($i)->format('Y-m-d H:i:s');
        $trip = step('create duplicate-request trial '.$i,httpCall('POST','trips',$duplicatePayload,$host['token']));
        $id = (int)$trip['data']['id'];
        $statuses = concurrentCalls([['POST',"trips/$id/requests",$guest['token']],['POST',"trips/$id/requests",$guest['token']]]);
        $rows = Passenger::where('trip_id',$id)->where('user_id',$guest['id'])
            ->whereIn('request_state',[Passenger::STATE_PENDING,Passenger::STATE_ACCEPTED,Passenger::STATE_WAITING_PAYMENT])->count();
        $duplicates[] = ['trial'=>$i,'active_rows'=>$rows,'client_exit_codes'=>$statuses];
    }
    verify('concurrent duplicate request has exactly one active row',count(array_filter($duplicates,fn($t)=>$t['active_rows']!==1 || count(array_filter($t['client_exit_codes'],fn($s)=>$s===0))!==1 || count(array_filter($t['client_exit_codes'],fn($s)=>$s===2))!==1))===0,
        ['trials'=>$duplicates,'coverage'=>'8 sampled duplicate-request races; no artificial repository barrier']);
    step('host enables automatic acceptance',httpCall('PUT','users',['autoaccept_requests'=>true],$host['token']));
    verify('automatic acceptance enabled', (bool)User::findOrFail($host['id'])->autoaccept_requests);
    $automatic = [];
    for ($i=0;$i<6;$i++) {
        $autoPayload = $payload;
        $autoPayload['trip_date'] = now()->addDays(4)->addMinutes($i)->format('Y-m-d H:i:s');
        $trip = step('create automatic-accept trial '.$i,httpCall('POST','trips',$autoPayload,$host['token']));
        $id = (int)$trip['data']['id'];
        $statuses = concurrentCalls([['POST',"trips/$id/requests",$guest['token']],['POST',"trips/$id/requests",$other['token']]]);
        $accepted = Passenger::where('trip_id',$id)->where('request_state',Passenger::STATE_ACCEPTED)->where('passenger_type',Passenger::TYPE_PASAJERO)->count();
        $pending = Passenger::where('trip_id',$id)->where('request_state',Passenger::STATE_PENDING)->where('passenger_type',Passenger::TYPE_PASAJERO)->count();
        $automatic[] = ['trial'=>$i,'accepted'=>$accepted,'pending'=>$pending,'capacity'=>1,'client_exit_codes'=>$statuses];
    }
    verify('automatic accept reserves one seat and keeps other request pending',count(array_filter($automatic,fn($t)=>$t['accepted']!==1 || $t['pending']!==1 || $t['client_exit_codes']!==[0,0]))===0,
        ['trials'=>$automatic,'policy'=>'A request remains pending when automatic confirmation cannot reserve a seat; no false error after durable request creation.']);
    $report['gaps'] = ['No trip start/completion endpoint established in source audit.',
        'No occurrence-specific recurrence or segment capacity exercised.',
        'No provider-backed trip settlement, refunds or operator trip ledger established.'];
    $report['completed_probe'] = true;
} catch (Throwable $e) {
    $report['completed_probe'] = false;
    $report['failure'] = $e->getMessage();
}
$report['finished_at'] = gmdate(DATE_ATOM);
$report['all_checks_passed'] = $report['completed_probe'] && !in_array(false,array_column($report['checks'],'passed'),true);
file_put_contents('/opt/mobility/results/journey-latest.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($report['all_checks_passed'] ? 0 : 1);
