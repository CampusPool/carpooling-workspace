<?php

namespace STS\Mobility\Matching;

use InvalidArgumentException;

/**
 * Original, deterministic route-segment candidate selector.
 *
 * Trust boundary: $offers must be server-selected, authorized, non-terminal trips
 * with authoritative per-segment remaining capacity and stop-arrival estimates.
 * This method does not query a database, reserve seats, or claim HOPR parity.
 */
final class RouteSegmentMatcher
{
    /**
     * @param array<string,mixed> $query
     * @param list<array<string,mixed>> $offers
     * @return list<array<string,int>>
     */
    public static function rank(array $query, array $offers): array
    {
        self::requirePositiveInt($query, 'rider_id');
        self::requirePositiveInt($query, 'origin_stop_id');
        self::requirePositiveInt($query, 'destination_stop_id');
        self::requirePositiveInt($query, 'seats');
        self::requireNonnegativeInt($query, 'earliest_pickup_epoch');
        self::requireNonnegativeInt($query, 'latest_pickup_epoch');
        self::requireNonnegativeInt($query, 'preferred_pickup_epoch');
        self::requirePositiveInt($query, 'max_results');
        $date = self::requireDate($query);
        if ($query['origin_stop_id'] === $query['destination_stop_id']
            || $query['earliest_pickup_epoch'] > $query['latest_pickup_epoch']
            || $query['preferred_pickup_epoch'] < $query['earliest_pickup_epoch']
            || $query['preferred_pickup_epoch'] > $query['latest_pickup_epoch']
            || $query['max_results'] > 100) {
            throw new InvalidArgumentException('Invalid route or search bounds.');
        }

        $ranked = [];
        $seenTripIds = [];
        foreach ($offers as $offer) {
            if (!is_array($offer)
                || !isset($offer['trip_id'], $offer['host_id'], $offer['service_date'],
                    $offer['stops'], $offer['arrival_epochs'], $offer['segment_free_seats'])
                || !is_int($offer['trip_id']) || $offer['trip_id'] <= 0
                || !is_int($offer['host_id']) || $offer['host_id'] <= 0
                || $offer['host_id'] === $query['rider_id']
                || $offer['service_date'] !== $date
                || !is_array($offer['stops']) || !array_is_list($offer['stops'])
                || !is_array($offer['arrival_epochs']) || !array_is_list($offer['arrival_epochs'])
                || !is_array($offer['segment_free_seats']) || !array_is_list($offer['segment_free_seats'])) {
                continue;
            }
            $stops = $offer['stops'];
            $arrival = $offer['arrival_epochs'];
            $free = $offer['segment_free_seats'];
            $count = count($stops);
            if ($count < 2 || $count !== count($arrival) || count($free) !== $count - 1) {
                continue;
            }
            // Repeated stop IDs create ambiguous pickup/drop-off interpretation.
            if (count(array_unique($stops, SORT_REGULAR)) !== $count) {
                continue;
            }
            $valid = true;
            for ($i = 0; $i < $count; $i++) {
                if (!is_int($stops[$i]) || $stops[$i] <= 0
                    || !is_int($arrival[$i]) || $arrival[$i] < 0
                    || ($i > 0 && $arrival[$i] <= $arrival[$i - 1])) {
                    $valid = false;
                    break;
                }
                if ($i < $count - 1 && (!is_int($free[$i]) || $free[$i] < 0)) {
                    $valid = false;
                    break;
                }
            }
            if (!$valid) {
                continue;
            }
            $pickup = array_search($query['origin_stop_id'], $stops, true);
            $dropoff = array_search($query['destination_stop_id'], $stops, true);
            if ($pickup === false || $dropoff === false || $pickup >= $dropoff) {
                continue;
            }
            $pickupTime = $arrival[$pickup];
            if ($pickupTime < $query['earliest_pickup_epoch']
                || $pickupTime > $query['latest_pickup_epoch']) {
                continue;
            }
            $available = min(array_slice($free, $pickup, $dropoff - $pickup));
            if ($available < $query['seats']) {
                continue;
            }
            // Deduplicate by canonical trip ID; duplicate offers are not trusted.
            if (isset($seenTripIds[$offer['trip_id']])) {
                continue;
            }
            $seenTripIds[$offer['trip_id']] = true;
            $ranked[] = [
                'trip_id' => $offer['trip_id'],
                'pickup_stop_id' => $query['origin_stop_id'],
                'dropoff_stop_id' => $query['destination_stop_id'],
                'pickup_epoch' => $pickupTime,
                'dropoff_epoch' => $arrival[$dropoff],
                'segment_available_seats' => $available,
                'pickup_difference_seconds' => abs($pickupTime - $query['preferred_pickup_epoch']),
                'ride_duration_seconds' => $arrival[$dropoff] - $pickupTime,
            ];
        }
        usort($ranked, static fn (array $a, array $b): int =>
            [$a['pickup_difference_seconds'], $a['ride_duration_seconds'], $a['trip_id']]
            <=> [$b['pickup_difference_seconds'], $b['ride_duration_seconds'], $b['trip_id']]);
        return array_slice($ranked, 0, $query['max_results']);
    }

    /** @param array<string,mixed> $input */
    private static function requirePositiveInt(array $input, string $key): void
    {
        if (!isset($input[$key]) || !is_int($input[$key]) || $input[$key] <= 0) {
            throw new InvalidArgumentException("Invalid {$key}.");
        }
    }

    /** @param array<string,mixed> $input */
    private static function requireNonnegativeInt(array $input, string $key): void
    {
        if (!isset($input[$key]) || !is_int($input[$key]) || $input[$key] < 0) {
            throw new InvalidArgumentException("Invalid {$key}.");
        }
    }

    /** @param array<string,mixed> $input */
    private static function requireDate(array $input): string
    {
        $date = $input['service_date'] ?? null;
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            throw new InvalidArgumentException('Invalid service_date.');
        }
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        if (!checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('Invalid service_date.');
        }
        return $date;
    }
}
