<?php

namespace STS\Mobility\Journeys;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Dispatch\DispatchException;
use STS\Mobility\Routing\RouteDataVersion;

/** Immutable private quote parser. Fresh admission is a separate locked check. */
final class PrivateJourneyTerms
{
    public static function forQuote(object $quote): array
    {
        try { $s = json_decode((string) $quote->snapshot, true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { self::invalid(); }
        $hash = hash('sha256', (string) $quote->snapshot);
        $consent = DB::table('mobility_private_journey_quotes')->where('route_quote_id', $quote->id)->first();
        if (! $consent || $consent->state !== 'accepted' || ! $consent->driver_approved_at || ! $consent->rider_consented_at
            || ! hash_equals((string) $consent->quote_hash, $hash) || ! hash_equals((string) $consent->driver_approval_hash, $hash)
            || (int) $consent->rider_id !== (int) $quote->user_id || ! Str::isUuid($quote->id)
            || ($s['schema'] ?? null) !== 'private-full-journey-quote-v1' || ($s['selection_id'] ?? null) !== $consent->selection_id
            || ($s['rider_id'] ?? null) !== (int) $quote->user_id || ($s['driver_id'] ?? null) !== (int) $consent->driver_id
            || $s['driver_id'] < 1 || $s['driver_id'] === $s['rider_id']
            || ! in_array($s['vehicle_type'] ?? null, ['car', 'bike'], true) || ! in_array($s['ride_mode'] ?? null, ['scheduled', 'on_demand'], true)) self::invalid();
        foreach (['origin', 'destination'] as $role) {
            $endpoint = $s[$role.'_endpoint'] ?? null;
            if (! is_array($endpoint) || ($endpoint['id'] ?? null) !== $consent->{$role.'_endpoint_id'} || ($endpoint['hash'] ?? null) !== $consent->{$role.'_endpoint_hash'}) self::invalid();
        }
        $path = $s['driver_path'] ?? null;
        if (! is_array($path) || ($path['provider'] ?? null) !== 'osrm' || ! is_string($path['profile'] ?? null) || $path['profile'] === ''
            || ! RouteDataVersion::valid($path['data_version'] ?? null, $s['vehicle_type'])
            || ! is_array($path['waypoints'] ?? null) || count($path['waypoints']) !== 4 || ! is_array($path['legs'] ?? null) || count($path['legs']) !== 3) self::invalid();
        $ride = $s['riding'] ?? null;
        if (! is_array($ride) || $ride != $path['legs'][1]) self::invalid();
        foreach (['distance_meters' => 100000, 'duration_seconds' => 86400] as $metric => $max) {
            if (! is_int($ride[$metric] ?? null) || $ride[$metric] < 1 || $ride[$metric] > $max) self::invalid();
        }
        $walk = 0; $footVersion = null;
        foreach (['access', 'egress'] as $leg) {
            $walking = $s[$leg] ?? null; $foot = $walking['path'] ?? null;
            if (! is_array($walking) || ! is_int($walking['total_meters'] ?? null) || $walking['total_meters'] < 0
                || ! is_array($foot) || ($foot['provider'] ?? null) !== 'osrm' || ! is_string($foot['data_version'] ?? null)
                || ! preg_match('/^foot\|crop_sha256=[a-f0-9]{64}\|profile_sha256=[a-f0-9]{64}$/D', $foot['data_version'])
                || ($footVersion !== null && $footVersion !== $foot['data_version'])) self::invalid();
            $footVersion = $foot['data_version']; $walk += $walking['total_meters'];
        }
        if (! is_int($s['max_walk_meters'] ?? null) || $s['max_walk_meters'] < 0 || $s['max_walk_meters'] > 999
            || ($s['total_walking_meters'] ?? null) !== $walk || $walk > $s['max_walk_meters']) self::invalid();
        $price = $s['pricing'] ?? null;
        if (! is_array($price) || ($price['currency'] ?? null) !== 'INR' || ($price['demo_only'] ?? null) !== true
            || ! is_string($price['policy_version'] ?? null) || $price['policy_version'] === ''
            || ($price['riding_distance_meters'] ?? null) !== $ride['distance_meters']) self::invalid();
        foreach (['base_fare_minor', 'direct_pickup_surcharge_minor', 'driver_additional_compensation_minor', 'total_fare_minor'] as $field) {
            if (! is_int($price[$field] ?? null) || $price[$field] < 0 || $price[$field] > 100000000) self::invalid();
        }
        if ($price['base_fare_minor'] + $price['direct_pickup_surcharge_minor'] !== $price['total_fare_minor']
            || $price['driver_additional_compensation_minor'] !== $price['direct_pickup_surcharge_minor']
            || ! in_array($s['choice'] ?? null, ['DIRECT_RIDER_PICKUP', 'ON_ROUTE_MEETING_POINT'], true)
            || ($s['choice'] === 'DIRECT_RIDER_PICKUP' ? $price['direct_pickup_surcharge_minor'] < 1 : $price['direct_pickup_surcharge_minor'] !== 0)) self::invalid();
        $points = [];
        foreach ([1 => 'pickup', 2 => 'dropoff'] as $index => $role) {
            $pair = $path['waypoints'][$index]['location'] ?? null;
            if (! is_array($pair) || count($pair) !== 2 || ! is_numeric($pair[0]) || ! is_numeric($pair[1])
                || ! is_finite((float) $pair[0]) || ! is_finite((float) $pair[1]) || $pair[0] < 77.2 || $pair[0] > 77.95 || $pair[1] < 12.65 || $pair[1] > 13.35) self::invalid();
            $points[] = ['address' => 'Private '.$role, 'lat' => (float) $pair[1], 'lng' => (float) $pair[0],
                'json_address' => ['private_quote_id' => $quote->id, 'role' => $role]];
        }
        $canonical = ['endpoint_schema' => 'actor-private-v1', 'private_quote_id' => $quote->id, 'points' => $points,
            'provider' => 'osrm', 'profile' => $path['profile'], 'data_version' => $path['data_version'], 'synthetic_only' => false,
            'distance_meters' => $ride['distance_meters'], 'duration_seconds' => $ride['duration_seconds'], 'seat_price_minor' => $price['total_fare_minor'], 'currency' => 'INR'];
        return ['private' => true, 'snapshot' => $canonical, 'private_snapshot' => $s, 'hash' => $hash, 'quote_id' => $quote->id,
            'journey_quote_id' => $consent->id, 'rider_id' => (int) $quote->user_id, 'driver_id' => (int) $consent->driver_id,
            'college_code' => $quote->college_code, 'fare_minor' => $price['total_fare_minor'], 'vehicle_type' => $s['vehicle_type'],
            'ride_mode' => $s['ride_mode'], 'pickup_point_id' => null, 'points' => $points];
    }

    private static function invalid(): never { throw new DispatchException('PRIVATE_QUOTE_INVALID', 409); }
}
