<?php

/** Explicit disposable software proof: actual road HTTP, TCP/JWT, MySQL contention.
 * No physical users, booking/payment actions or persistent pilot mutations.
 */
use Illuminate\Support\Facades\DB;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\User;

require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$report = ['all_checks_passed' => false, 'checks' => [], 'boundary' => 'Disposable MySQL, generated test actors, actual Bengaluru car/bike/foot graphs, real TCP/JWT requests. Pickup agreements only; no physical/device, booking, fare or payment proof.'];
$output = '/opt/mobility/results/pickup-agreement-http.json';
$peerServer = null;

function checkProof(string $name, bool $ok, array $observed = []): void
{
    global $report;
    $report['checks'][] = ['name' => $name, 'passed' => $ok, 'observed' => $observed];
    if (! $ok) throw new RuntimeException($name);
}

function requestHandle(string $method, string $path, ?string $token, ?array $body = null, ?string $key = null): CurlHandle
{
    $ch = curl_init('http://127.0.0.1:8000/api/mobility/'.$path);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer '.$token;
    if ($key !== null) $headers[] = 'Idempotency-Key: '.$key;
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 80, CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    return $ch;
}

function responseValue(CurlHandle $ch, string|false $raw): array
{
    if ($raw === false) throw new RuntimeException('local HTTP transport failed');
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $result = [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode(substr($raw, $headerSize), true, 512, JSON_THROW_ON_ERROR), strtolower(substr($raw, 0, $headerSize))];
    curl_close($ch);
    return $result;
}

function apiProof(string $method, string $path, ?string $token, ?array $body = null, ?string $key = null): array
{
    $ch = requestHandle($method, $path, $token, $body, $key);
    return responseValue($ch, curl_exec($ch));
}

/** Hold the common first trust row until TWO actual HTTP workers wait in InnoDB. */
function contendedProof(array $requests, int $firstUserId, ?Closure $beforeRelease = null): array
{
    $observer = new PDO('mysql:host=mysql;dbname=carpoolear', 'root', 'smoke-root-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $holder = new PDO('mysql:host=mysql;dbname=carpoolear', 'root', 'smoke-root-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $holder->beginTransaction();
    $stmt = $holder->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE'); $stmt->execute([$firstUserId]); $stmt->fetchAll();
    $multi = curl_multi_init(); $handles = [];
    foreach ($requests as $index => $request) {
        $handle = requestHandle(...$request);
        // Distinct listeners avoid PHP's shared accept queue scheduling both
        // sockets on one worker. Both boot this same Laravel app and use its DB.
        if ($index === 1) curl_setopt($handle, CURLOPT_URL, 'http://127.0.0.1:18082/api/mobility/'.$request[1]);
        $handles[] = $handle; curl_multi_add_handle($multi, $handle);
    }
    $observed = 0; $running = 0;
    try {
        $deadline = microtime(true) + 8;
        do {
            curl_multi_exec($multi, $running);
            $sql = "SELECT COUNT(DISTINCT w.REQUESTING_ENGINE_TRANSACTION_ID) FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID AND l.ENGINE=w.ENGINE WHERE l.OBJECT_SCHEMA='carpoolear' AND l.OBJECT_NAME='users' AND l.LOCK_DATA=".$observer->quote((string) $firstUserId);
            $observed = max($observed, (int) $observer->query($sql)->fetchColumn());
            if ($observed >= count($requests)) break;
            if ($running) curl_multi_select($multi, 0.05);
        } while ($running && microtime(true) < $deadline);
        $waits = $observer->query("SELECT l.OBJECT_NAME,l.INDEX_NAME,l.LOCK_DATA,l.LOCK_MODE FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID AND l.ENGINE=w.ENGINE WHERE l.OBJECT_SCHEMA='carpoolear'")->fetchAll(PDO::FETCH_ASSOC);
        if ($beforeRelease) $beforeRelease($holder);
        $holder->commit();
        do { curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 0.05); } while ($running);
        $results = [];
        foreach ($handles as $handle) { $raw = curl_multi_getcontent($handle); curl_multi_remove_handle($multi, $handle); $results[] = responseValue($handle, $raw); }
        checkProof('two TCP workers demonstrably blocked on the same trust row', $observed >= count($requests), ['blocked_transactions' => $observed, 'lock_waits' => $observed >= count($requests) ? [] : $waits, 'statuses' => array_column($results, 0)]);
        return $results;
    } finally {
        if ($holder->inTransaction()) $holder->rollBack();
        curl_multi_close($multi);
    }
}

function memberProof(): User
{
    $user = User::factory()->create(['active' => true, 'banned' => false]);
    DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    return $user;
}

function unchangedProof(): array
{
    $out = [];
    foreach (['nodes_geo', 'mobility_pickup_points', 'trips', 'trips_points', 'trip_passengers', 'mobility_route_quotes', 'mobility_pool_publications', 'mobility_dispatch_requests', 'mobility_dispatch_assignments', 'mobility_financial_postings'] as $table) {
        $rows = DB::table($table)->orderBy('id')->get();
        $out[$table] = ['count' => $rows->count(), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }
    return $out;
}

try {
    if (getenv('MOBILITY_PICKUP_AGREEMENT_PROOF') !== 'local-only' || ! app()->environment('testing')
        || config('database.connections.mysql.host') !== 'mysql' || config('database.connections.mysql.database') !== 'carpoolear'
        || CohortPolicy::college() !== 'CAMPUS-BLR'
        || ! is_file('/opt/mobility/lifecycle-probe.php')) throw new RuntimeException('disposable local guard required');
    foreach (['car', 'bike', 'foot'] as $mode) {
        if (config('mobility_routing.'.$mode.'_base_url') !== 'http://mobility-road-router-'.$mode.':5000') throw new RuntimeException('local graph guard required');
    }
    $health = false;
    for ($i = 0; $i < 20; $i++) {
        $ch = curl_init('http://127.0.0.1:8000/api/health'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        $value = curl_exec($ch); curl_close($ch);
        if ($value !== false && (json_decode($value, true)['status'] ?? null) === 'ok') { $health = true; break; }
        usleep(250000);
    }
    checkProof('disposable web application healthy', $health);
    $peerEnv = getenv(); unset($peerEnv['PHP_CLI_SERVER_WORKERS']);
    $peerServer = proc_open(['php', '-S', '127.0.0.1:18082', '/var/www/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],
        [0 => ['file','/dev/null','r'], 1 => ['file','/opt/mobility/results/pickup-proof-peer.log','a'], 2 => ['file','/opt/mobility/results/pickup-proof-peer.log','a']], $pipes, '/var/www/public', $peerEnv);
    if (! is_resource($peerServer)) throw new RuntimeException('proof peer could not start');
    $peerReady = false;
    for ($i = 0; $i < 20; $i++) {
        $ch = curl_init('http://127.0.0.1:18082/api/health'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        $value = curl_exec($ch); curl_close($ch);
        if ($value !== false && (json_decode($value, true)['status'] ?? null) === 'ok') { $peerReady = true; break; }
        usleep(100000);
    }
    checkProof('independent proof listener boots the same application', $peerReady);
    $before = unchangedProof();
    foreach (['car', 'bike'] as $mode) {
        foreach (['scheduled', 'on_demand'] as $rideMode) {
            $host = memberProof(); $rider = memberProof(); $outsider = memberProof();
            if ($mode === 'bike' && $rideMode === 'on_demand') {
                // Deliberately public test-only credentials, in a guarded disposable DB.
                // These are never reused in the persistent pilot or an external service.
                $host->update(['email' => 'pickup-proof-host@example.test', 'password' => bcrypt('PickupProof-LocalOnly-2026!')]);
                $rider->update(['email' => 'pickup-proof-rider@example.test', 'password' => bcrypt('PickupProof-LocalOnly-2026!')]);
            }
            $tokens = [];
            foreach (['host' => $host, 'rider' => $rider, 'outsider' => $outsider] as $role => $user) $tokens[$role] = auth('api')->fromUser($user);
            $car = Car::factory()->withCatalog()->create(['user_id' => $host->id]);
            DB::table('mobility_host_applications')->insert(['user_id' => $host->id, 'car_id' => $car->id, 'vehicle_type' => $mode, 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $prefix = $mode.'-'.$rideMode;
            [$status, $body] = apiProof('POST', 'pickup-plans', $tokens['host'], ['car_id' => $car->id, 'vehicle_type' => $mode, 'ride_mode' => $rideMode,
                'points' => [['lng' => 77.5946, 'lat' => 12.9716], ['lng' => 77.6200, 'lat' => 12.9352]]], $prefix.'-plan');
            checkProof($prefix.' actual-road plan via TCP/JWT', $status === 201, ['status' => $status]);
            $plan = $body['data']['plan'];
            [$status, $intentBody] = apiProof('POST', 'pickup-intents', $tokens['rider'], ['plan_id' => $plan['id'], 'invite_token' => $body['data']['invite_token'], 'origin' => ['lng' => 77.598, 'lat' => 12.9706], 'max_walk_meters' => 2000], $prefix.'-intent');
            checkProof($prefix.' private rider origin', $status === 201, ['status' => $status]);
            $intent = $intentBody['data']['intent'];
            [$status, $previewBody] = apiProof('POST', 'pickup-intents/'.$intent['id'].'/previews', $tokens['host'], ['expected_plan_version' => 1, 'expected_intent_version' => 1, 'max_detour_meters' => 10000, 'max_detour_seconds' => 3600], $prefix.'-preview');
            checkProof($prefix.' real three-graph preview', $status === 201, ['status' => $status]);
            $preview = $previewBody['data']['preview'];
            if ($mode === 'bike' && $rideMode === 'on_demand') $report['browser_fixture'] = ['preview_id' => $preview['id'], 'intent_id' => $intent['id'], 'plan_id' => $plan['id']];
            $previousId = null;
            foreach (['DIRECT_RIDER_PICKUP', 'ON_ROUTE_MEETING_POINT'] as $choice) {
                $payload = ['choice' => $choice, 'expected_plan_version' => 1, 'expected_intent_version' => 1];
                $path = 'pickup-previews/'.$preview['id'].'/selections'; $key = $prefix.'-'.$choice;
                if ($mode === 'car' && $rideMode === 'scheduled') {
                    $results = contendedProof([['POST', $path, $tokens['host'], $payload, $key], ['POST', $path, $tokens['host'], $payload, $key]], min($host->id, $rider->id));
                    checkProof($prefix.' contended same-key selection converges', array_column($results, 0) === [201, 200] || array_column($results, 0) === [200, 201], ['statuses' => array_column($results, 0)]);
                    $selection = $results[0][1]['data']['selection'];
                    checkProof('same-key driver requests return one selection', $selection['id'] === $results[1][1]['data']['selection']['id']);
                } else {
                    [$status, $selected] = apiProof('POST', $path, $tokens['host'], $payload, $key);
                    checkProof($prefix.' '.$choice.' selected', $status === 201, ['status' => $status]);
                    $selection = $selected['data']['selection'];
                }
                checkProof('agreement remains pickup-only', $selection['booking_enabled'] === false && $selection['scope'] === 'pickup_only' && $selection['choice'] === $choice);
                $row = DB::table('mobility_pickup_selections')->where('id', $selection['id'])->first();
                checkProof('exact immutable terms hash matches stored JSON', hash('sha256', $row->terms) === $selection['terms_hash']);
                $immutable = ['terms' => $row->terms, 'terms_hash' => $row->terms_hash, 'preview_hash' => $row->preview_hash];
                [$status, , $headers] = apiProof('GET', 'pickup-selections/'.$selection['id'], $tokens['rider']);
                checkProof('rider private reconnect and no-store', $status === 200 && str_contains($headers, 'private') && str_contains($headers, 'no-store'));
                [$status] = apiProof('GET', 'pickup-selections/'.$selection['id'], $tokens['outsider']); checkProof('outsider cannot read private pickup', $status === 403);
                [$status] = apiProof('POST', 'pickup-selections/'.$selection['id'].'/confirm', $tokens['host'], ['expected_version' => $selection['version'], 'terms_hash' => $selection['terms_hash'], 'access_acknowledged' => true], $key.'-wrong'); checkProof('driver cannot confirm for rider', $status === 403);
                $confirm = ['expected_version' => $selection['version'], 'terms_hash' => $selection['terms_hash'], 'access_acknowledged' => true];
                if ($mode === 'car' && $rideMode === 'scheduled') {
                    $confirmPath = 'pickup-selections/'.$selection['id'].'/confirm';
                    $results = contendedProof([['POST', $confirmPath, $tokens['rider'], $confirm, $key.'-confirm'], ['POST', $confirmPath, $tokens['rider'], $confirm, $key.'-confirm']], min($host->id, $rider->id));
                    checkProof('contended rider confirmations converge', array_column($results, 0) === [200, 200], ['statuses' => array_column($results, 0)]);
                } else {
                    [$status] = apiProof('POST', 'pickup-selections/'.$selection['id'].'/confirm', $tokens['rider'], $confirm, $key.'-confirm'); checkProof('rider confirms exact choice', $status === 200);
                }
                $row = DB::table('mobility_pickup_selections')->where('id', $selection['id'])->first();
                checkProof('confirmation retains exact geometry and consent hash', $row->state === 'confirmed' && $row->rider_confirmed_at !== null && $immutable === ['terms' => $row->terms, 'terms_hash' => $row->terms_hash, 'preview_hash' => $row->preview_hash]);
                if ($previousId !== null) checkProof('new driver choice supersedes old consent without transferring it', DB::table('mobility_pickup_selections')->where('id', $previousId)->value('state') === 'superseded');
                $previousId = $selection['id'];
            }
            $binding = ['expected_version' => $selection['version'], 'terms_hash' => $selection['terms_hash']];
            [$status] = apiProof('POST', 'pickup-selections/'.$selection['id'].'/revoke', $tokens['host'], $binding, $prefix.'-revoke'); checkProof('driver revokes pickup agreement', $status === 200);
            [$status] = apiProof('POST', 'pickup-selections/'.$selection['id'].'/confirm', $tokens['rider'], $confirm, $key.'-confirm');
            checkProof('replayed confirmation cannot revive revoked agreement', ($status === 200 && DB::table('mobility_pickup_selections')->where('id', $selection['id'])->value('state') === 'revoked') || $status === 409, ['status' => $status]);
            checkProof('no active agreement remains after revocation', DB::table('mobility_pickup_selections')->where('intent_id', $intent['id'])->whereNotNull('active_intent_id')->count() === 0);
            if ($mode === 'car' && $rideMode === 'scheduled') {
                foreach (['revoke', 'decline', 'source-change'] as $race) {
                    [$status, $selected] = apiProof('POST', 'pickup-previews/'.$preview['id'].'/selections', $tokens['host'], ['choice' => 'DIRECT_RIDER_PICKUP', 'expected_plan_version' => 1, 'expected_intent_version' => 1], 'race-selection-'.$race);
                    checkProof('fresh pending agreement for '.$race.' race', $status === 201);
                    $s = $selected['data']['selection'];
                    $bind = ['expected_version' => $s['version'], 'terms_hash' => $s['terms_hash']];
                    $confirmBody = $bind + ['access_acknowledged' => true];
                    $base = 'pickup-selections/'.$s['id'].'/';
                    $requests = [['POST', $base.'confirm', $tokens['rider'], $confirmBody, 'race-confirm-'.$race]];
                    $requests[] = $race === 'source-change'
                        ? ['POST', $base.'confirm', $tokens['rider'], $confirmBody, 'race-confirm-source-change-2']
                        : ['POST', $base.$race, $tokens[$race === 'revoke' ? 'host' : 'rider'], $bind, 'race-close-'.$race];
                    $during = $race === 'source-change' ? function (PDO $holder) use ($intent): void {
                        $statement = $holder->prepare('UPDATE mobility_pickup_intents SET version=version+1 WHERE id=?'); $statement->execute([$intent['id']]);
                    } : null;
                    $results = contendedProof($requests, min($host->id, $rider->id), $during);
                    $state = DB::table('mobility_pickup_selections')->where('id', $s['id'])->value('state');
                    if ($race === 'revoke') checkProof('simultaneous confirmation and revocation end revoked', in_array($results[0][0], [200,409], true) && $results[1][0] === 200 && $state === 'revoked', ['statuses' => array_column($results, 0), 'state' => $state]);
                    elseif ($race === 'decline') {
                        $statuses = array_column($results, 0); sort($statuses);
                        checkProof('simultaneous confirm and decline have exactly one winning transition', $statuses === [200,409] && in_array($state, ['confirmed','declined'], true), ['statuses' => array_column($results, 0), 'state' => $state]);
                    } else checkProof('source change while waiting invalidates both confirmations after locks', array_column($results, 0) === [409,409] && $state === 'pending', ['statuses' => array_column($results, 0)]);
                    if ($state === 'confirmed' || $state === 'pending') {
                        [$status] = apiProof('POST', $base.'revoke', $tokens['host'], $bind, 'race-final-close-'.$race); checkProof('race fixture closes without booking effects', $status === 200);
                    }
                }
            }
        }
    }
    checkProof('exact canonical catalog ride quote dispatch and money tables unchanged', unchangedProof() === $before);
    $report['all_checks_passed'] = true;
} catch (Throwable $error) {
    // No exception payload, tokens, locations or actor identities in shared output.
    $report['probe_error'] = 'Pickup agreement proof incomplete; inspect the failed check and private local application log.';
} finally {
    if (is_resource($peerServer)) { proc_terminate($peerServer); proc_close($peerServer); }
    file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
}
if (! $report['all_checks_passed']) { fwrite(STDERR, "PICKUP_AGREEMENT_PROOF_FAILED\n"); exit(1); }
echo 'PASS: '.count($report['checks'])." actual-road pickup agreement TCP/JWT/MySQL checks\n";
