<?php

namespace STS\Mobility\Dispatch;

/** Request-scoped proof that a new accepted passenger is factory-owned. */
final class DispatchBookingContext
{
    private static ?array $binding = null;

    public static function run(int $tripId, int $riderId, callable $work): mixed
    {
        $previous = self::$binding;
        self::$binding = ['trip_id' => $tripId, 'rider_id' => $riderId];
        try {
            return $work();
        } finally {
            self::$binding = $previous;
        }
    }

    public static function allows(int $tripId, int $riderId): bool
    {
        return self::$binding !== null && self::$binding['trip_id'] === $tripId && self::$binding['rider_id'] === $riderId;
    }
}
