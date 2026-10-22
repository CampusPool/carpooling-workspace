<?php

namespace STS\Mobility\Dispatch;

use STS\Mobility\Routing\RouteDataVersion;

/** Validates and extracts the immutable, rider-owned quote snapshot. */
final class DispatchTerms
{
    public static function forQuote(object $quote): array
    {
        $raw = $quote->snapshot ?? null;
        if (! is_string($raw) || $raw === '') {
            self::fail('QUOTE_INVALID');
        }
        try {
            $snapshot = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            self::fail('QUOTE_INVALID');
        }
        if (($snapshot['schema'] ?? null) === 'private-full-journey-quote-v1') {
            return \STS\Mobility\Journeys\PrivateJourneyTerms::forQuote($quote);
        }
        if (! is_array($snapshot) || ! is_array($snapshot['points'] ?? null) || ! array_is_list($snapshot['points']) || count($snapshot['points']) !== 2
            || ! is_array($snapshot['pickup_points'] ?? null) || ! array_is_list($snapshot['pickup_points']) || count($snapshot['pickup_points']) !== 2
            || ! is_string($quote->id ?? null) || ! preg_match('/^[0-9a-f-]{36}$/iD', (string) $quote->id)
            || ! is_int($quote->user_id ?? null) && ! ctype_digit((string) ($quote->user_id ?? ''))) {
            self::fail('QUOTE_INVALID');
        }
        $vehicle = $snapshot['vehicle_type'] ?? null;
        if (! is_string($vehicle) || ! in_array($vehicle, ['bike', 'car'], true)
            || ($snapshot['currency'] ?? null) !== 'INR'
            || ! is_string($snapshot['provider'] ?? null) || $snapshot['provider'] === ''
            || ! is_string($snapshot['profile'] ?? null) || $snapshot['profile'] === ''
            || ! is_bool($snapshot['synthetic_only'] ?? null)) {
            self::fail('QUOTE_INVALID');
        }
        foreach (['distance_meters' => 100000, 'duration_seconds' => 86400, 'seat_price_minor' => 100000000] as $field => $max) {
            $minimum = $field === 'seat_price_minor' ? 0 : 1;
            if (! is_int($snapshot[$field] ?? null) || $snapshot[$field] < $minimum || $snapshot[$field] > $max) {
                self::fail('QUOTE_INVALID');
            }
        }
        foreach ($snapshot['points'] as $index => $point) {
            if (! is_array($point) || ! is_string($point['address'] ?? null) || ! is_array($point['json_address'] ?? null)
                || ! is_numeric($point['lat'] ?? null) || ! is_numeric($point['lng'] ?? null)
                || (int) ($point['json_address']['id'] ?? 0) !== (int) ($snapshot['pickup_points'][$index]['canonical_node_id'] ?? 0)
                || (string) ($point['json_address']['name'] ?? '') !== (string) $point['address']) {
                self::fail('QUOTE_INVALID');
            }
        }
        $stops = $snapshot['pickup_points'];
        if ((int) ($stops[0]['id'] ?? 0) < 1 || (int) ($stops[1]['id'] ?? 0) < 1
            || (int) ($stops[0]['id'] ?? 0) === (int) ($stops[1]['id'] ?? 0)
            || (int) ($stops[0]['canonical_node_id'] ?? 0) < 1 || (int) ($stops[1]['canonical_node_id'] ?? 0) < 1
            || (int) ($stops[0]['canonical_node_id'] ?? 0) === (int) ($stops[1]['canonical_node_id'] ?? 0)) {
            self::fail('QUOTE_INVALID');
        }
        foreach ($stops as $stop) {
            if (! is_array($stop) || (int) ($stop['version'] ?? 0) < 1) {
                self::fail('QUOTE_INVALID');
            }
        }
        if (($snapshot['distance_meters'] ?? null) !== (($snapshot['legs'][0]['distance_meters'] ?? $snapshot['distance_meters']))) {
            self::fail('QUOTE_INVALID');
        }
        if ($snapshot['synthetic_only'] && ! app()->environment('testing')) {
            self::fail('QUOTE_SYNTHETIC_DISABLED', 503);
        }
        if (! RouteDataVersion::allowed($snapshot['data_version'] ?? null, $vehicle, (bool) $snapshot['synthetic_only'])) {
            self::fail('QUOTE_INVALID');
        }
        $legs = $snapshot['legs'] ?? null;
        if ($legs !== null && (! is_array($legs) || ! array_is_list($legs) || count($legs) !== 1 || ($legs[0]['from_index'] ?? null) !== 0 || ($legs[0]['to_index'] ?? null) !== 1
            || ! is_int($legs[0]['distance_meters'] ?? null) || ! is_int($legs[0]['duration_seconds'] ?? null)
            || $legs[0]['distance_meters'] < 1 || $legs[0]['duration_seconds'] < 1
            || ($legs[0]['provider'] ?? null) !== $snapshot['provider'] || ($legs[0]['profile'] ?? null) !== $snapshot['profile']
            || ($legs[0]['data_version'] ?? null) !== ($snapshot['data_version'] ?? null))) {
            self::fail('QUOTE_INVALID');
        }
        $fare = (int) $snapshot['seat_price_minor'];
        if ($legs !== null && (! is_int($legs[0]['seat_price_minor'] ?? null) || $legs[0]['seat_price_minor'] < 0 || $legs[0]['seat_price_minor'] !== $fare
            || $legs[0]['distance_meters'] !== $snapshot['distance_meters'] || $legs[0]['duration_seconds'] !== $snapshot['duration_seconds'])) {
            self::fail('QUOTE_INVALID');
        }

        return [
            'snapshot' => $snapshot,
            'hash' => hash('sha256', $raw),
            'quote_id' => (string) $quote->id,
            'rider_id' => (int) $quote->user_id,
            'college_code' => (string) ($quote->college_code ?? ($snapshot['college_code'] ?? '')),
            'fare_minor' => $fare,
            'vehicle_type' => $vehicle,
            'pickup_point_id' => (int) $stops[0]['id'],
            'points' => $snapshot['points'],
        ];
    }

    private static function fail(string $code): never
    {
        if (class_exists(DispatchException::class)) {
            throw new DispatchException($code, 409);
        }
        throw new \RuntimeException($code);
    }
}
