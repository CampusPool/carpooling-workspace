<?php

require __DIR__.'/../Mobility/Matching/RouteSegmentMatcher.php';
require __DIR__.'/../Mobility/Matching/TripMatchReference.php';

use STS\Mobility\Matching\RouteSegmentMatcher;
use STS\Mobility\Matching\TripMatchReference;

function check(bool $condition, string $label): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('FAILED: '.$label);
    }
}

$assertions = 0;

$query = [
    'rider_id' => 11, 'origin_stop_id' => 20, 'destination_stop_id' => 40,
    'seats' => 2, 'service_date' => '2026-10-18',
    'earliest_pickup_epoch' => 1000, 'latest_pickup_epoch' => 1300,
    'preferred_pickup_epoch' => 1090, 'max_results' => 20,
];
$host = static function (int $id, array $stops, array $times, array $seats, int $hostId = 22): array {
    return [
        'trip_id' => $id, 'host_id' => $hostId, 'service_date' => '2026-10-18',
        'stops' => $stops, 'arrival_epochs' => $times, 'segment_free_seats' => $seats,
    ];
};
$offers = [
    $host(7, [20, 30, 40], [1080, 1130, 1200], [3, 2]),
    $host(8, [20, 30, 40], [1100, 1180, 1250], [3, 3]),
    $host(9, [40, 30, 20], [1080, 1130, 1200], [3, 3]), // backwards
    $host(10, [20, 30, 40], [1100, 1170, 1250], [3, 1]), // oversold segment
    $host(11, [20, 30, 40], [900, 950, 990], [3, 3]), // outside time window
    $host(12, [20, 30, 40], [1100, 1150, 1200], [3, 3], 11), // self match
    $host(7, [20, 30, 40], [1200, 1220, 1250], [3, 3]), // duplicate trip
    $host(13, [20, 30, 20, 40], [1100, 1150, 1200, 1250], [3, 3, 3]), // ambiguous stops
    $host(14, [20, 30, 40], [1100, 1099, 1250], [3, 3]), // time regression
];
$results = RouteSegmentMatcher::rank($query, $offers);
check(array_column($results, 'trip_id') === [7, 8], 'deterministic candidate ordering');
check($results[0]['segment_available_seats'] === 2, 'minimum seat availability along full route');
check($results[0]['pickup_difference_seconds'] === 10, 'time score is absolute difference');
check($results[0]['ride_duration_seconds'] === 120, 'duration is calculated from stop timestamps');

$boundary = $query;
$boundary['earliest_pickup_epoch'] = 1080;
$boundary['latest_pickup_epoch'] = 1080;
$boundary['preferred_pickup_epoch'] = 1080;
check(array_column(RouteSegmentMatcher::rank($boundary, $offers), 'trip_id') === [7], 'inclusive time boundary');
$single = $query;
$single['max_results'] = 1;
check(count(RouteSegmentMatcher::rank($single, $offers)) === 1, 'bounded results');
$badDate = $query;
$badDate['service_date'] = '2026-02-30';
$thrown = false;
try {
    RouteSegmentMatcher::rank($badDate, $offers);
} catch (InvalidArgumentException $e) {
    $thrown = true;
}
check($thrown, 'invalid calendar date rejected');
$badSeats = $query;
$badSeats['seats'] = '2';
$thrown = false;
try {
    RouteSegmentMatcher::rank($badSeats, $offers);
} catch (InvalidArgumentException $e) {
    $thrown = true;
}
check($thrown, 'numeric strings rejected');
$badRoute = $query;
$badRoute['origin_stop_id'] = $badRoute['destination_stop_id'];
$thrown = false;
try {
    RouteSegmentMatcher::rank($badRoute, $offers);
} catch (InvalidArgumentException $e) {
    $thrown = true;
}
check($thrown, 'same pickup/dropoff rejected');
$reference = new TripMatchReference('match:abc', 'trip:52', 'HOST', '2026-10-18');
check($reference->asArray() === [
    'tripMatchId' => 'match:abc', 'tripId' => 'trip:52',
    'roleType' => 'HOST', 'date' => '2026-10-18',
], 'typed match reference preserves four identity fields');
$thrown = false;
try {
    new TripMatchReference('unknown secret/abc', 'trip:52', 'HOST', '2026-10-18');
} catch (InvalidArgumentException $e) {
    $thrown = true;
}
check($thrown, 'reject invalid match identifier');
$thrown = false;
try {
    new TripMatchReference('match:abc', 'trip:52', 'GUEST', '2026-02-30');
} catch (InvalidArgumentException $e) {
    $thrown = true;
}
check($thrown, 'reject invalid match reference date');
echo "PASS: {$assertions} assertions covering route direction, segment capacity, time windows, self-match, deduplication, tie ordering and input errors\n";
