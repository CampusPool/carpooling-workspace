<?php

namespace STS\Mobility\Journeys;

final class PrivateScheduledAdmissionContext
{
    private static ?array $binding = null;

    public static function run(int $tripId, int $riderId, callable $work): mixed
    {
        $old = self::$binding;
        self::$binding = ['trip_id' => $tripId, 'rider_id' => $riderId];
        try {
            return $work();
        } finally {
            self::$binding = $old;
        }
    }

    public static function allows(int $tripId, int $riderId): bool
    {
        return self::$binding !== null && self::$binding['trip_id'] === $tripId && self::$binding['rider_id'] === $riderId;
    }
}
