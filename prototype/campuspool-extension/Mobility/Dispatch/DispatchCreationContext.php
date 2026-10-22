<?php

namespace STS\Mobility\Dispatch;

/** Server-only immediate-trip seam; public dated-trip validation remains strict. */
final class DispatchCreationContext
{
    private static ?array $binding = null;

    public static function run(int $driverId, string $date, callable $work): mixed
    {
        $previous = self::$binding;
        self::$binding = ['driver_id' => $driverId, 'date' => $date];
        try {
            return $work();
        } finally {
            self::$binding = $previous;
        }
    }

    public static function activeFor(int $driverId): bool
    {
        return self::$binding !== null && self::$binding['driver_id'] === $driverId && \STS\Mobility\Routing\TrustedRouteContext::active();
    }

    public static function dateRule(int $driverId, array $data): string
    {
        if (self::$binding !== null && self::$binding['driver_id'] === $driverId && ($data['trip_date'] ?? null) === self::$binding['date'] && \STS\Mobility\Routing\TrustedRouteContext::active()) {
            return 'required|date_format:Y-m-d H:i:s';
        }

        return 'required_without:weekly_schedule|nullable|date|after:now';
    }
}
