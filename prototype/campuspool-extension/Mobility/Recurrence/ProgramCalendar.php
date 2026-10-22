<?php

namespace STS\Mobility\Recurrence;

use Carbon\CarbonImmutable;

final class ProgramCalendar
{
    public const ZONE = 'Asia/Kolkata';

    public static function dates(int $mask, string $time, string $from, string $to): array
    {
        ProgramInputs::integer($mask, 1, 127);
        ProgramInputs::time($time);
        $start = ProgramInputs::date($from);
        $end = ProgramInputs::date($to);
        if ($end->lt($start) || $end->diffInDays($start, true) > 28) {
            throw new ProgramException('GENERATION_RANGE_INVALID');
        }
        $out = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            if (($mask & (1 << ($date->dayOfWeekIso - 1))) !== 0) {
                $out[] = [$date->format('Y-m-d'), $date->setTimeFromTimeString($time)];
            }
        }

        return $out;
    }

    public static function generationThrough(string $dateFrom, string $dateTo, ?CarbonImmutable $now = null): string
    {
        $today = ($now ?: CarbonImmutable::now(self::ZONE))->startOfDay();
        $target = $today->addDays(14)->max(ProgramInputs::date($dateFrom)->addDays(7));

        return $target->min($today->addDays(28))->min(ProgramInputs::date($dateTo))->format('Y-m-d');
    }
}
