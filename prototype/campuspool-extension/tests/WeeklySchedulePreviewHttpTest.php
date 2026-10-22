<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

final class WeeklySchedulePreviewHttpTest extends TestCase
{
    public function test_only_owner_can_preview_existing_weekly_trip_without_creating_occurrences(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $trip = Trip::factory()->create([
            'user_id' => $owner->id,
            'weekly_schedule' => 127,
            'weekly_schedule_time' => '08:30:00',
        ]);
        $uri = '/api/mobility/trips/'.$trip->id.'/schedule-preview';
        $before = DB::table('trips')->count();

        $this->actingAsApiUser($other)->getJson($uri)->assertForbidden()
            ->assertJsonPath('error.code', 'TRIP_OWNER_REQUIRED');

        $from = now('Asia/Kolkata')->format('Y-m-d');
        $this->actingAsApiUser($owner)->getJson($uri.'?days=2&from='.$from)
            ->assertOk()
            ->assertJsonPath('is_preview_only', true)
            ->assertJsonPath('occurrences_materialized', false)
            ->assertJsonPath('timezone', 'Asia/Kolkata')
            ->assertJsonPath('trip_id', $trip->id)
            ->assertJsonCount(2, 'dates')
            ->assertJsonPath('dates.0.service_date', $from);
        $this->assertSame($before, DB::table('trips')->count());
    }

    public function test_invalid_and_nonrecurring_plans_fail_closed(): void
    {
        $owner = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $owner->id, 'weekly_schedule' => 0]);
        $uri = '/api/mobility/trips/'.$trip->id.'/schedule-preview';
        $this->actingAsApiUser($owner)->getJson($uri)
            ->assertStatus(422)->assertJsonPath('error.code', 'NOT_A_WEEKLY_PLAN');

        $trip->update(['weekly_schedule' => 1, 'weekly_schedule_time' => '07:30:00']);
        $this->actingAsApiUser($owner)->getJson($uri.'?days=32')
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PREVIEW_RANGE');
        $this->actingAsApiUser($owner)->getJson($uri.'?days=2&from='.(now('Asia/Kolkata')->addYears(2)->format('Y')).'-02-30')
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_SCHEDULE');
        $this->actingAsApiUser($owner)->getJson($uri.'?days=2&from=2000-01-01')
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_START_DATE');
    }
}
