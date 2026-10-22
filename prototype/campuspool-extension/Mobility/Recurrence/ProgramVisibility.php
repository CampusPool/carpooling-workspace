<?php

namespace STS\Mobility\Recurrence;

/** Hide paused/skipped dated inventory in rider searches; owner/history reads remain intact. */
final class ProgramVisibility
{
    public static function scope($query): void
    {
        $query->whereNotExists(function ($q): void {
            $q->selectRaw('1')->from('mobility_recurring_occurrences as recurring_occurrence')
                ->join('mobility_recurring_programs as recurring_program', 'recurring_program.id', '=', 'recurring_occurrence.program_id')
                ->whereColumn('recurring_occurrence.trip_id', 'trips.id')
                ->where(function ($state): void {
                    $state->where('recurring_program.state', '!=', 'active')->orWhereExists(function ($skip): void {
                        $skip->selectRaw('1')->from('mobility_recurring_exceptions as recurring_skip')
                            ->whereColumn('recurring_skip.program_id', 'recurring_program.id')->whereColumn('recurring_skip.service_date', 'recurring_occurrence.service_date')->where('recurring_skip.action', 'skip');
                    });
                });
        });
    }
}
