<?php

namespace STS\Mobility\Routing;

/**
 * Request scoped bridge for an already verified quote.  The canonical vendor
 * repository can only see route metrics through this bridge; it never reads
 * client supplied route data or the points-only RouteCache.
 */
final class TrustedRouteContext
{
    private static ?array $context = null;

    public static function push(array $points, array $quote): void
    {
        self::$context = [
            'points' => self::canonicalPoints($points),
            'quote' => $quote,
        ];
    }

    /** A server-validated private quote may never fall back to global route nodes. */
    public static function privateEndpoints(): bool
    {
        if ((self::$context['quote']['endpoint_schema'] ?? null) !== 'actor-private-v1') return false;
        $quote = self::$context['quote'];
        $id = $quote['private_quote_id'] ?? null;
        if (! is_string($id) || ! \Illuminate\Support\Str::isUuid($id)
            || ! is_array($quote['points'] ?? null)
            || self::canonicalPoints($quote['points']) !== self::$context['points']) throw new \LogicException('Private canonical quote linkage differs');
        foreach (self::$context['points'] as $point) {
            if (($point['json_address']['private_quote_id'] ?? null) !== $id || isset($point['json_address']['id'])) throw new \LogicException('Private canonical endpoint cannot carry a catalog identity');
        }
        return true;
    }

    public static function reset(): void
    {
        self::$context = null;
    }

    public static function active(): bool
    {
        return self::$context !== null;
    }

    /** Return the vendor-shaped trip info only for the exact ordered points. */
    public static function tripInfoFor(array $points): ?array
    {
        if (self::$context === null || self::canonicalPoints($points) !== self::$context['points']) {
            if (self::$context !== null) {
                throw new \LogicException('Trusted route points differ from quote');
            }

            return null;
        }

        $quote = self::$context['quote'];

        return [
            'status' => true,
            'data' => [
                'distance' => (int) $quote['distance_meters'],
                'duration' => (int) $quote['duration_seconds'],
                'recommended_trip_price_cents' => (int) $quote['seat_price_minor'],
                'maximum_trip_price_cents' => (int) $quote['seat_price_minor'],
            ],
            'message' => 'TRUSTED_ROUTE_QUOTE',
        ];
    }

    public static function quote(): ?array
    {
        return self::$context['quote'] ?? null;
    }

    /** @return list<array{address:string,json_address:array,lat:float,lng:float}> */
    private static function canonicalPoints(array $points): array
    {
        return array_map(static function (array $point): array {
            return [
                'address' => (string) ($point['address'] ?? ''),
                'json_address' => is_array($point['json_address'] ?? null) ? $point['json_address'] : [],
                'lat' => (float) $point['lat'],
                'lng' => (float) $point['lng'],
            ];
        }, array_values($points));
    }
}
