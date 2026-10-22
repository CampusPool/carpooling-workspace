<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Recurrence\ProgramControlService;
use STS\Models\Car;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

final class MobilityRecurringControlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('MOBILITY_COHORT_REQUIRED=true');
        putenv('MOBILITY_COLLEGE_CODE=CAMPUS-BLR');
    }

    protected function tearDown(): void
    {
        putenv('MOBILITY_COHORT_REQUIRED');
        putenv('MOBILITY_COLLEGE_CODE');
        parent::tearDown();
    }

    public function test_owner_pause_is_versioned_and_replay_conflict_is_safe(): void
    {
        [$owner, $program] = $this->program();
        $service = app(ProgramControlService::class);
        $result = $service->control($owner, $program, 'pause-key-1', 1, 'pause', 'holiday');
        $this->assertSame('paused', $result['program']['state']);
        $this->assertSame(2, $result['program']['version']);
        $this->assertEquals($result, $service->control($owner, $program, 'pause-key-1', 1, 'pause', 'holiday'));
        try {
            $service->control($owner, $program, 'pause-key-1', 1, 'pause', 'different');
            $this->fail();
        } catch (\Throwable $e) {
            $this->assertSame('IDEMPOTENCY_CONFLICT', $e->machineCode);
        }
    }

    public function test_non_owner_and_stale_version_are_rejected_without_mutation(): void
    {
        [$owner, $program] = $this->program();
        $other = User::factory()->create();
        $service = app(ProgramControlService::class);
        try {
            $service->control($other, $program, 'wrong-owner', 1, 'pause', 'x');
            $this->fail();
        } catch (\Throwable $e) {
            $this->assertSame('PROGRAM_NOT_FOUND', $e->machineCode);
        }
        $service->control($owner, $program, 'pause-key-2', 1, 'pause', 'x');
        try {
            $service->control($owner, $program, 'stale-key-1', 1, 'resume', 'x');
            $this->fail();
        } catch (\Throwable $e) {
            $this->assertSame('PROGRAM_VERSION_STALE', $e->machineCode);
        }
        $this->assertSame('paused', DB::table('mobility_recurring_programs')->where('id', $program)->value('state'));
    }

    public function test_edit_starts_after_watermark_and_does_not_rewrite_published_rows(): void
    {
        [$owner, $program] = $this->program(['generated_through' => now('Asia/Kolkata')->format('Y-m-d')]);
        $occurrence = DB::table('mobility_recurring_occurrences')->insertGetId(['program_id' => $program, 'service_date' => now('Asia/Kolkata')->addDay()->format('Y-m-d'), 'trip_id' => Trip::factory()->create(['user_id' => $owner->id])->id, 'quote_id' => $this->quote($owner), 'program_version' => 1, 'config_snapshot' => json_encode(['total_seats' => 2]), 'created_at' => now(), 'updated_at' => now()]);
        $service = app(ProgramControlService::class);
        $service->edit($owner, $program, 'edit-key-1', 1, now('Asia/Kolkata')->addDay()->format('Y-m-d'), ['total_seats' => 3], 'capacity');
        $this->assertSame(1, DB::table('mobility_recurring_occurrences')->where('id', $occurrence)->value('program_version'));
        $this->assertSame(3, DB::table('mobility_recurring_programs')->where('id', $program)->value('total_seats'));
        try {
            $service->edit($owner, $program, 'edit-key-2', 2, now('Asia/Kolkata')->addDays(2)->format('Y-m-d'), ['total_seats' => 4], 'late');
            $this->fail();
        } catch (\Throwable $e) {
            $this->assertSame('PROGRAM_EDIT_WATERMARK', $e->machineCode);
        }
    }

    private function program(array $extra = []): array
    {
        $owner = User::factory()->create(['active' => true, 'banned' => false]);
        $car = Car::factory()->withCatalog()->create(['user_id' => $owner->id]);
        DB::table('mobility_memberships')->insert(['user_id' => $owner->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mobility_host_applications')->insert(['user_id' => $owner->id, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => \STS\Mobility\Trust\CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $quote = $this->quote($owner);
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('mobility_recurring_programs')->insert(array_merge(['id' => $id, 'user_id' => $owner->id, 'car_id' => $car->id, 'source_quote_id' => $quote, 'route_snapshot' => json_encode(['vehicle_type' => 'car']), 'weekly_schedule' => 2, 'departure_time' => '08:00', 'date_from' => now('Asia/Kolkata')->format('Y-m-d'), 'date_to' => now('Asia/Kolkata')->addDays(30)->format('Y-m-d'), 'total_seats' => 2, 'vehicle_type' => 'car', 'max_fare_minor' => 1000, 'pricing_version' => 'test-v1', 'description' => '', 'state' => 'active', 'version' => 1, 'generated_through' => null, 'last_generation_code' => null, 'create_key_hash' => hash('sha256', $id), 'request_hash' => hash('sha256', $id), 'created_at' => now(), 'updated_at' => now()], $extra));

        return [$owner, $id];
    }

    private function quote(User $owner): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('mobility_route_quotes')->insert(['id' => $id, 'user_id' => $owner->id, 'college_code' => 'CAMPUS-BLR', 'snapshot' => json_encode(['vehicle_type' => 'car', 'seat_price_minor' => 1000, 'pricing_version' => 'test-v1', 'synthetic_only' => true]), 'expires_at' => now('UTC')->addHour(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
