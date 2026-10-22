<?php

namespace Tests\Feature\Mobility;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use STS\Mobility\Recurrence\ProgramCalendar;
use STS\Mobility\Recurrence\ProgramException;

final class MobilityRecurringGenerationTest extends TestCase
{
    public function test_calendar_is_india_local_and_bounded_to_mask(): void
    {
        $dates = ProgramCalendar::dates(1 | 4, '09:30', '2026-10-19', '2026-10-25');
        self::assertSame(['2026-10-19', '2026-10-21'], array_column($dates, 0));
        self::assertSame('2026-10-19T09:30:00+05:30', $dates[0][1]->toIso8601String());
    }

    public function test_generation_watermark_never_exceeds_twenty_eight_days(): void
    {
        $now = CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Kolkata');
        self::assertSame('2026-11-03', ProgramCalendar::generationThrough('2026-10-20', '2027-10-20', $now));
        self::assertSame('2026-11-17', ProgramCalendar::generationThrough('2026-11-10', '2027-10-20', $now));
    }

    public function test_initial_window_keeps_every_matching_date_even_when_more_than_five(): void
    {
        $dates = ProgramCalendar::dates(127, '09:30', '2026-10-20', '2026-10-28');
        self::assertCount(9, $dates);
        self::assertSame('2026-10-28', $dates[8][0]);
    }

    public function test_invalid_program_calendar_has_machine_error(): void
    {
        $this->expectException(ProgramException::class);
        try {
            ProgramCalendar::dates(0, '9:30', '2026-10-20', '2026-10-21');
        } catch (ProgramException $e) {
            self::assertSame('PROGRAM_INPUT_INVALID',$e->machineCode);
            throw $e;
        }
    }
}
