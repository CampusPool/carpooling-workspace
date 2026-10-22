<?php

namespace STS\Mobility\Recurrence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Read-only preview of the existing Carpoolear weekly bitmask.
 * Monday=1, Tuesday=2, ... Sunday=64; not a booked occurrence.
 */
final class WeeklySchedulePreview
{
    /** @return list<array{service_date:string,weekday:int,departure_local:string,departure_epoch:int}> */
    public static function dates(int $mask, string $time, string $start, int $days = 14, string $zoneName = 'Asia/Kolkata'): array
    {
        if ($mask < 1 || $mask > 127 || $days < 1 || $days > 31
            || !preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $time)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $start)) {
            throw new InvalidArgumentException('Invalid weekly schedule request.');
        }
        $zone = new DateTimeZone($zoneName);
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $start, $zone);
        if (!$first || $first->format('Y-m-d') !== $start) {
            throw new InvalidArgumentException('Invalid calendar date.');
        }
        $matches = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $day = $first->modify("+{$offset} days");
            $weekday = (int) $day->format('N');
            if (($mask & (1 << ($weekday - 1))) === 0) {
                continue;
            }
            $wall = $day->format('Y-m-d').' '.$time;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $wall, $zone);
            // Do not silently round nonexistent daylight-saving wall times.
            if (!$date || $date->format('Y-m-d H:i:s') !== $wall) {
                continue;
            }
            $matches[] = [
                'service_date' => $day->format('Y-m-d'),
                'weekday' => $weekday,
                'departure_local' => $date->format('Y-m-d\TH:i:sP'),
                'departure_epoch' => $date->getTimestamp(),
            ];
        }
        return $matches;
    }
}
