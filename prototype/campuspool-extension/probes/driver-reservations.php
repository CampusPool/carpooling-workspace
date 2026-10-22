<?php

/** Disposable TCP/JWT/MySQL proof for the driver reservation admission fence. */
use Illuminate\Support\Facades\DB;
use STS\Mobility\Dispatch\DispatchWorker;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\User;

require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$report = ['all_checks_passed' => false, 'boundary' => 'Disposable synthetic approved actors and Bengaluru internal road graph only; no Google, payment, production capacity, or physical-device claim.', 'checks' => []];
$peer = null;

function proof(string $name, bool $passed, array $observed = []): void
{
    global $report;
    $report['checks'][] = ['name' => $name, 'passed' => $passed, 'observed' => $observed];
    if (! $passed) {
        throw new RuntimeException($name);
    }
}

function httpHandle(string $method, string $path, ?string $token, ?array $body = null, ?string $key = null, int $port = 8000): CurlHandle
{
    $handle = curl_init('http://127.0.0.1:'.$port.'/api/'.$path);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer '.$token;
    if ($key !== null) $headers[] = 'Idempotency-Key: '.$key;
    curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 80, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));

    return $handle;
}

function response(CurlHandle $handle, string|false $raw): array
{
    if ($raw === false) throw new RuntimeException('Local HTTP transport failed');
    $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $data = json_decode(substr($raw, $headerSize), true);
    curl_close($handle);

    return [$status, is_array($data) ? $data : []];
}

function request(string $method, string $path, ?string $token, ?array $body = null, ?string $key = null, int $port = 8000): array
{
    $handle = httpHandle($method, $path, $token, $body, $key, $port);

    return response($handle, curl_exec($handle));
}

function expectHttp(string $name, string $method, string $path, ?string $token, ?array $body = null, ?string $key = null, array $statuses = [200], int $port = 8000): array
{
    [$status, $data] = request($method, $path, $token, $body, $key, $port);
    proof($name, in_array($status, $statuses, true), ['status' => $status, 'code' => $data['error']['code'] ?? null]);

    return $data;
}

function member(): User
{
    $user = User::factory()->create(['active' => true, 'banned' => false, 'identity_validated' => true]);
    DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

    return $user;
}

function fixtureStops(): array
{
    $suffix = (int) (microtime(true) * 1000) % 100000;
    $a = 700000 + $suffix * 2;
    $b = $a + 1;
    DB::table('nodes_geo')->insert([
        ['id' => $a, 'name' => 'MG Road Synthetic Landmark', 'lat' => 12.9716, 'lng' => 77.5946, 'type' => 'synthetic'],
        ['id' => $b, 'name' => 'Electronic City Synthetic Landmark', 'lat' => 12.8406, 'lng' => 77.6600, 'type' => 'synthetic'],
    ]);
    DB::table('mobility_pickup_points')->insert([
        ['id' => $a, 'college_code' => 'CAMPUS-BLR', 'canonical_node_id' => $a, 'version' => 1, 'name' => 'MG Road Synthetic Landmark', 'lat' => 12.9716, 'lng' => 77.5946, 'vehicle_modes' => json_encode(['car']), 'active' => true, 'verified_at' => now(), 'synthetic_only' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => $b, 'college_code' => 'CAMPUS-BLR', 'canonical_node_id' => $b, 'version' => 1, 'name' => 'Electronic City Synthetic Landmark', 'lat' => 12.8406, 'lng' => 77.6600, 'vehicle_modes' => json_encode(['car']), 'active' => true, 'verified_at' => now(), 'synthetic_only' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    return [$a, $b];
}

function race(string $scheduledPath, string $scheduledToken, string $scheduledKey, string $offerPath, string $offerToken, string $offerKey, string $tripId, string $requestId): array
{
    $pdo = new PDO('mysql:host=mysql;dbname=carpoolear', 'root', 'smoke-root-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $observer = new PDO('mysql:host=mysql;dbname=carpoolear', 'root', 'smoke-root-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->beginTransaction();
    $lockTrip = $pdo->prepare('SELECT id FROM trips WHERE id = ? FOR UPDATE'); $lockTrip->execute([$tripId]); $lockTrip->fetch();
    $lockRequest = $pdo->prepare('SELECT id FROM mobility_dispatch_requests WHERE id = ? FOR UPDATE'); $lockRequest->execute([$requestId]); $lockRequest->fetch();
    $multi = curl_multi_init(); $handles = [];
    foreach ([[$scheduledPath, $scheduledToken, $scheduledKey, 8000], [$offerPath, $offerToken, $offerKey, 18082]] as [$path, $token, $key, $port]) {
        $handle = httpHandle('POST', $path, $token, [], $key, $port); $handles[] = $handle; curl_multi_add_handle($multi, $handle);
    }
    $waiters = 0; $running = 0; $deadline = microtime(true) + 10;
    do {
        curl_multi_exec($multi, $running);
        $waiters = max($waiters, (int) $observer->query("SELECT COUNT(DISTINCT w.REQUESTING_ENGINE_TRANSACTION_ID) FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.BLOCKING_ENGINE_LOCK_ID AND l.ENGINE = w.ENGINE WHERE l.OBJECT_SCHEMA = 'carpoolear' AND l.OBJECT_NAME IN ('trips','mobility_dispatch_requests')")->fetchColumn());
        if ($waiters >= 2 || ! $running || microtime(true) >= $deadline) break;
        curl_multi_select($multi, 0.05);
    } while (true);
    $pdo->commit();
    do { curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 0.05); } while ($running);
    $results = [];
    foreach ($handles as $handle) { $raw = curl_multi_getcontent($handle); curl_multi_remove_handle($multi, $handle); $results[] = response($handle, $raw); }
    curl_multi_close($multi);
    proof('two TCP workers observed waiting on held canonical rows', $waiters >= 2, ['blocked_transactions' => $waiters]);
    proof('reservation race has no server error', ! in_array(500, array_column($results, 0), true), ['statuses' => array_column($results, 0)]);

    return $results;
}

try {
    if (getenv('MOBILITY_DRIVER_RESERVATION_PROOF') !== 'local-only' || ! app()->environment('testing') || config('database.connections.mysql.host') !== 'mysql' || config('database.connections.mysql.database') !== 'carpoolear' || CohortPolicy::college() !== 'CAMPUS-BLR' || ! is_file('/opt/mobility/lifecycle-probe.php')) throw new RuntimeException('Disposable local-only guard required');
    foreach (['car', 'bike', 'foot'] as $mode) if (config('mobility_routing.'.$mode.'_base_url') !== 'http://mobility-road-router-'.$mode.':5000') throw new RuntimeException('Internal road graph guard required');
    $peerEnv = getenv(); unset($peerEnv['PHP_CLI_SERVER_WORKERS']);
    $peer = proc_open(['php', '-S', '127.0.0.1:18082', '/var/www/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, '/var/www/public', $peerEnv);
    if (! is_resource($peer)) throw new RuntimeException('Independent proof listener failed');
    $ready = false;
    for ($i = 0; $i < 30; $i++) { try { [$status] = request('GET', 'health', null, null, null, 18082); if ($status === 200) { $ready = true; break; } } catch (RuntimeException) {} usleep(100000); }
    proof('independent TCP listener ready', $ready);
    [$pickup, $dropoff] = fixtureStops();
    $host = member(); $riderA = member(); $riderB = member();
    $host->forceFill(['nro_doc' => 'DEMO-ONLY', 'mobile_phone' => '0000000000'])->save();
    $car = Car::factory()->withCatalog()->create(['user_id' => $host->id]);
    DB::table('mobility_host_applications')->insert(['user_id' => $host->id, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $ht = auth('api')->fromUser($host); $at = auth('api')->fromUser($riderA); $bt = auth('api')->fromUser($riderB);
    $quoteA = expectHttp('scheduled real-road quote', 'POST', 'mobility/routes/quote', $ht, ['pickup_point_id' => $pickup, 'dropoff_point_id' => $dropoff, 'vehicle_type' => 'car'], 'reservation-scheduled-quote', [201]);
    $duration = (int) ($quoteA['data']['duration_seconds'] ?? 0); $buffer = (int) config('mobility_routing.driver_reservation_buffer_seconds', 300);
    proof('route duration plus reservation buffer exceeds scheduled window', $duration + (2 * $buffer) > 17 * 60, ['duration_seconds' => $duration, 'buffer_seconds' => $buffer]);
    $departure = now('Asia/Kolkata')->addMinutes(17)->startOfMinute()->format('Y-m-d\\TH:i');
    $published = expectHttp('scheduled trip publication', 'POST', 'mobility/pool-offers', $ht, ['quote_id' => $quoteA['data']['id'], 'car_id' => $car->id, 'trip_date' => $departure, 'total_seats' => 1, 'description' => 'Synthetic reservation proof'], 'reservation-publish', [201]);
    $tripId = (string) $published['data']['id'];
    expectHttp('scheduled rider A pending request', 'POST', 'trips/'.$tripId.'/requests', $at, [], 'reservation-rider-a', [200, 201]);
    $quoteB = expectHttp('on-demand real-road quote', 'POST', 'mobility/routes/quote', $bt, ['pickup_point_id' => $pickup, 'dropoff_point_id' => $dropoff, 'vehicle_type' => 'car'], 'reservation-demand-quote', [201]);
    $request = expectHttp('on-demand rider B request', 'POST', 'mobility/dispatch/requests', $bt, ['quote_id' => $quoteB['data']['id']], 'reservation-demand-request', [201]);
    $requestId = (string) $request['id'];
    expectHttp('driver availability for targeted offer', 'PUT', 'mobility/dispatch/availability', $ht, ['online' => true, 'scope' => 'catalog', 'car_id' => $car->id, 'pickup_point_id' => $pickup]);
    app(DispatchWorker::class)->process();
    $offers = expectHttp('dispatch worker target offer', 'GET', 'mobility/dispatch/offers', $ht)['data'] ?? [];
    $offer = array_values(array_filter($offers, fn (array $item): bool => (string) ($item['request_id'] ?? '') === $requestId))[0] ?? null;
    proof('worker generated one target offer', is_array($offer), ['offer_count' => count($offers)]);
    DB::beginTransaction();
    try {
        DB::table('trip_passengers')->count(); // Establish an older repeatable-read snapshot before either acceptance commits.
    $raceResults = race('trips/'.$tripId.'/requests/'.$riderA->id.'/accept', $ht, 'reservation-scheduled-accept', 'mobility/dispatch/offers/'.$offer['id'].'/accept', $ht, 'reservation-demand-accept', $tripId, $requestId);
        try {
            \STS\Mobility\Admission\DriverReservationPolicy::fence((int) $host->id);
            $terms = \STS\Mobility\Dispatch\DispatchTerms::forQuote(DB::table('mobility_route_quotes')->where('id', $quoteB['data']['id'])->first());
            \STS\Mobility\Admission\DriverReservationPolicy::immediate((int) $host->id, $terms, \Carbon\CarbonImmutable::now('UTC'));
            $code = null;
        } catch (\STS\Mobility\Segments\SegmentException $error) { $code = $error->machineCode; }
        // If both NOWAIT requests lose, the sequential retry below establishes a winner.
        $committed = in_array(200, array_column($raceResults, 0), true);
        proof('older read snapshot cannot hide a committed driver obligation', ! $committed || in_array($code, ['DRIVER_ROUTE_OVERLAP', 'DRIVER_ACTIVE_TRIP'], true), ['committed_winner' => $committed, 'code' => $code]);
    } finally { DB::rollBack(); }
    $statuses = array_column($raceResults, 0); $successes = count(array_filter($statuses, fn (int $status): bool => $status === 200));
    proof('reservation race statuses are limited to success or conflict', count(array_diff($statuses, [200, 409])) === 0, ['statuses' => $statuses]);
    proof('at most one competing acceptance commits', $successes <= 1, ['statuses' => $statuses, 'successful_commits' => $successes]);
    $accepted = DB::table('trip_passengers')->where('trip_id', $tripId)->where('request_state', 1)->count() + DB::table('mobility_dispatch_assignments')->where('request_id', $requestId)->count();
    proof('one canonical accepted driver obligation at most', $accepted <= 1, ['accepted_obligations' => $accepted]);
    if ($successes === 0) {
        expectHttp('finite NOWAIT conflicts recover on sequential retry', 'POST', 'trips/'.$tripId.'/requests/'.$riderA->id.'/accept', $ht, [], 'reservation-scheduled-accept');
        $statuses[0] = 200;
    }
    if (($statuses[0] ?? 0) === 200) {
        $loser = expectHttp('dispatch cannot overlap committed scheduled winner', 'POST', 'mobility/dispatch/offers/'.$offer['id'].'/accept', $ht, [], 'reservation-demand-accept', [409]);
        proof('dispatch sees committed scheduled overlap', ($loser['error']['code'] ?? null) === 'DRIVER_ROUTE_OVERLAP');
        proof('dispatch loser remains unassigned', DB::table('mobility_dispatch_assignments')->where('request_id', $requestId)->count() === 0);
        expectHttp('scheduled winner cancellation releases reservation', 'POST', 'trips/'.$tripId.'/requests/'.$riderA->id.'/cancel', $at, [], 'reservation-scheduled-cancel');
        $retry = expectHttp('dispatch same-key retry commits after cancellation', 'POST', 'mobility/dispatch/offers/'.$offer['id'].'/accept', $ht, [], 'reservation-demand-accept');
        $replay = expectHttp('committed dispatch retry replays original assignment', 'POST', 'mobility/dispatch/offers/'.$offer['id'].'/accept', $ht, [], 'reservation-demand-accept');
        proof('dispatch recovery produces exactly one assignment', $retry == $replay && DB::table('mobility_dispatch_assignments')->where('request_id', $requestId)->count() === 1);
    } else {
        $loser = expectHttp('scheduled cannot overlap committed dispatch winner', 'POST', 'trips/'.$tripId.'/requests/'.$riderA->id.'/accept', $ht, [], 'reservation-scheduled-accept', [409]);
        proof('scheduled sees active dispatch obligation', ($loser['error']['code'] ?? null) === 'DRIVER_ACTIVE_TRIP');
        proof('scheduled loser stays pending', DB::table('trip_passengers')->where('trip_id', $tripId)->where('user_id', $riderA->id)->where('request_state', 0)->count() === 1);
        expectHttp('dispatch winner cancellation releases reservation', 'POST', 'mobility/dispatch/requests/'.$requestId.'/cancel', $bt, [], 'reservation-demand-cancel');
        expectHttp('scheduled same-key retry commits after cancellation', 'POST', 'trips/'.$tripId.'/requests/'.$riderA->id.'/accept', $ht, [], 'reservation-scheduled-accept');
        proof('scheduled recovery produces exactly one accepted rider', DB::table('trip_passengers')->where('trip_id', $tripId)->where('user_id', $riderA->id)->where('request_state', 1)->count() === 1);
    }
    proof('pending rider A remains represented after race', DB::table('trip_passengers')->where('trip_id', $tripId)->where('user_id', $riderA->id)->exists(), ['pending_rows' => DB::table('trip_passengers')->where('trip_id', $tripId)->where('user_id', $riderA->id)->count()]);
    $report['all_checks_passed'] = true;
} catch (Throwable $error) {
    $report['failure'] = get_class($error);
    if ($error instanceof \STS\Mobility\Segments\SegmentException) $report['failure_code'] = $error->machineCode;
} finally {
    if (is_resource($peer)) { proc_terminate($peer); proc_close($peer); }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}
exit($report['all_checks_passed'] ? 0 : 1);
