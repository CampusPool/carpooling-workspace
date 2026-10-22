<?php

namespace STS\Mobility\Segments;

/** Internal server binding; never populated directly from request fields. */
final class BookingIntervalContext
{
    private static ?array $binding = null;

    public static function run(array $binding, callable $work): mixed
    {
        $previous = self::$binding;
        self::$binding = $binding;
        try {
            return $work();
        } finally {
            self::$binding = $previous;
        }
    }

    public static function for(int $trip, int $rider): ?array
    {
        return self::$binding !== null && (int) self::$binding['trip_id'] === $trip && (int) self::$binding['user_id'] === $rider ? self::$binding : null;
    }
}
