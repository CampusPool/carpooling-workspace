<?php

// Actual local OSRM I/O proof; no database, actors, trips or money are modified.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('MOBILITY_ROAD_PROBE') !== 'local-only') {
    fwrite(STDERR, "Explicit local testing probe required.\n");
    exit(2);
}
require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use STS\Mobility\Routing\OsrmRouteProvider;
use STS\Mobility\Routing\RoutingException;

$versions = [];
foreach (['car', 'bike'] as $mode) {
    $manifest = json_decode(file_get_contents('/routing-manifests/'.$mode.'.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['mode'] ?? '') !== $mode || ($manifest['ready'] ?? false) !== true) {
        throw new RuntimeException('Prepared mode manifest required.');
    }
    $versions[$mode] = $manifest['data_version'];
}
config(['mobility_routing.provider' => 'osrm', 'mobility_routing.synthetic_only' => false,
    'mobility_routing.car_base_url' => 'http://mobility-road-router-car:5000',
    'mobility_routing.bike_base_url' => 'http://mobility-road-router-bike:5000',
    'mobility_routing.car_profile' => 'driving', 'mobility_routing.bike_profile' => 'driving',
    'mobility_routing.car_expected_data_version' => $versions['car'],
    'mobility_routing.bike_expected_data_version' => $versions['bike']]);
$provider = new OsrmRouteProvider;
$a = ['lng' => 77.5946, 'lat' => 12.9716];
$b = ['lng' => 77.6200, 'lat' => 12.9352];
$checks = [];
foreach (['car', 'bike'] as $mode) {
    $route = $provider->route($a, $b, $mode);
    if ($route['data_version'] !== $versions[$mode] || $route['distance_meters'] <= 0 || $route['duration_seconds'] <= 0) {
        throw new RuntimeException('Actual local route failed validation.');
    }
    $checks[] = ['name' => $mode.' actual HTTP provider', 'passed' => true, 'route' => $route];
}
$reject = function (string $name, callable $call, string $code) use (&$checks): void {
    try {
        $call();
        throw new RuntimeException('Expected failure '.$name);
    } catch (RoutingException $error) {
        if ($error->machineCode !== $code) {
            throw new RuntimeException($name.': '.$error->machineCode.' != '.$code);
        }
        $checks[] = ['name' => $name, 'passed' => true, 'code' => $code];
    }
};
$reject('outside graph', fn () => $provider->route(['lng' => 0, 'lat' => 0], ['lng' => 0.001, 'lat' => 0], 'car'), 'NO_ROUTE');
config(['mobility_routing.car_base_url' => 'http://mobility-road-router-bike:5000']);
$reject('car request connected to bike graph', fn () => $provider->route($a, $b, 'car'), 'ROUTE_GRAPH_MISMATCH');
config(['mobility_routing.car_base_url' => 'http://mobility-road-router-car:5000', 'mobility_routing.car_expected_data_version' => OsrmRouteProvider::dataVersion('car', str_repeat('a', 64), str_repeat('b', 64))]);
$reject('changed graph hash', fn () => $provider->route($a, $b, 'car'), 'ROUTE_GRAPH_MISMATCH');
config(['mobility_routing.car_expected_data_version' => $versions['car'], 'mobility_routing.car_base_url' => 'http://127.0.0.1:9']);
$reject('actual connection refusal', fn () => $provider->route($a, $b, 'car'), 'ROUTE_PROVIDER_UNAVAILABLE');
config(['mobility_routing.car_expected_data_version' => null]);
$reject('missing graph pin', fn () => $provider->route($a, $b, 'car'), 'ROUTING_MISCONFIGURED');
echo json_encode(['boundary' => 'Real local OSM car/bike graph HTTP through the application adapter. Public-road software coordinates only; no approved passenger stop, journey, fare, ETA calibration, GPS, device or payment proof.', 'checks' => $checks, 'all_checks_passed' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
