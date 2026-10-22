<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Routing\RouteProvider;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

/** HTTP-level transaction and provenance coverage for recurring programs. */
final class MobilityRecurringProgramsHttpTest extends TestCase
{
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Carbon::setTestNow('2026-10-20 12:00:00');
        \Carbon\CarbonImmutable::setTestNow('2026-10-20 12:00:00');
        config(['mobility_routing.pricing_version' => 'test-v1', 'mobility_routing.car_minor_per_km' => 100, 'mobility_routing.synthetic_only' => true]);
        foreach (['MOBILITY_COHORT_REQUIRED' => 'true', 'MOBILITY_COLLEGE_CODE' => 'CAMPUS-BLR'] as $key => $value) {
            $this->previous[$key] = getenv($key);
            putenv($key.'='.$value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
        \Illuminate\Support\Carbon::setTestNow();
        \Carbon\CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_create_materializes_independent_monday_wednesday_inventory_and_replays_identity(): void
    {
        [$owner, $car] = $this->host();
        $quote = $this->quote($owner, 321);
        $from = now('Asia/Kolkata')->format('Y-m-d');
        $to = now('Asia/Kolkata')->addDays(8)->format('Y-m-d');
        $payload = $this->payload($quote, $car, 1 | 4, $from, $to, 2);

        $first = $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs', $payload, ['Idempotency-Key' => 'program-create-1'])->assertCreated();
        $program = $first->json('data.program.id');
        $dates = DB::table('mobility_recurring_occurrences')->where('program_id', $program)->orderBy('service_date')->get();
        $this->assertGreaterThanOrEqual(2, $dates->count());
        $this->assertSame($dates->count(), $dates->pluck('trip_id')->unique()->count());
        $this->assertSame($dates->count(), $dates->pluck('quote_id')->unique()->count());
        $this->assertSame($dates->count(), DB::table('mobility_pool_publications')->whereIn('trip_id', $dates->pluck('trip_id'))->count());
        $this->assertSame(2, (int) Trip::findOrFail($dates[0]->trip_id)->total_seats);
        $this->assertSame(321, (int) Trip::findOrFail($dates[0]->trip_id)->seat_price_cents);
        $this->assertDatabaseHas('mobility_route_quotes', ['id' => $dates[1]->quote_id, 'parent_quote_id' => $quote]);

        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs', $payload, ['Idempotency-Key' => 'program-create-1'])
            ->assertOk()->assertJsonPath('data.existing', true)->assertJsonPath('data.program.id', $program);
        $this->assertSame(1, DB::table('mobility_recurring_programs')->where('id', $program)->count());
        $this->assertSame($dates->count(), DB::table('mobility_recurring_occurrences')->where('program_id', $program)->count());
    }

    public function test_daily_program_with_more_than_five_dates_does_not_ban_host(): void
    {
        [$owner, $car] = $this->host();
        $from = now('Asia/Kolkata')->format('Y-m-d');
        $to = now('Asia/Kolkata')->addDays(7)->format('Y-m-d');
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs', $this->payload($this->quote($owner), $car, 127, $from, $to, 1), ['Idempotency-Key' => 'daily-program-1'])->assertCreated();
        $this->assertGreaterThan(5, DB::table('mobility_recurring_occurrences')->count());
        $this->assertFalse((bool) DB::table('users')->where('id', $owner->id)->value('banned'));
    }

    public function test_provider_failure_preserves_watermark_and_history(): void
    {
        [$owner, $car] = $this->host();
        $from = now('Asia/Kolkata')->format('Y-m-d');
        $to = now('Asia/Kolkata')->addDays(21)->format('Y-m-d');
        $created = $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs', $this->payload($this->quote($owner), $car, 127, $from, $to, 1), ['Idempotency-Key' => 'provider-program-1'])->assertCreated();
        $id = $created->json('data.program.id');
        $before = DB::table('mobility_recurring_programs')->where('id', $id)->first();
        $occurrences = DB::table('mobility_recurring_occurrences')->where('program_id', $id)->count();
        $this->app->instance(RouteProvider::class, new class implements RouteProvider
        {
            public function route(array $pickup, array $dropoff, string $vehicleType): array
            {
                throw new \RuntimeException('synthetic provider outage');
            }
        });
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$id.'/generate', ['expected_version' => $before->version, 'until' => $to], ['Idempotency-Key' => 'provider-outage-1'])
            ->assertStatus(500)->assertJsonPath('error.code', 'PROGRAM_UNAVAILABLE');
        $after = DB::table('mobility_recurring_programs')->where('id', $id)->first();
        $this->assertSame($before->generated_through, $after->generated_through);
        $this->assertSame($occurrences, DB::table('mobility_recurring_occurrences')->where('program_id', $id)->count());
        $this->assertSame(1, DB::table('mobility_recurring_actions')->where('program_id', $id)->count());
    }

    public function test_mid_generation_failure_rolls_back_program_trips_points_publications_and_child_quotes(): void
    {
        [$owner, $car] = $this->host();
        $quote = $this->quote($owner);
        $before = [];
        foreach (['trips', 'trips_points', 'trip_routes', 'mobility_pool_publications', 'mobility_route_quotes', 'mobility_recurring_programs', 'mobility_recurring_occurrences'] as $table) {
            $before[$table] = DB::table($table)->count();
        }
        \STS\Models\TripPoint::created(static function (): void {
            static $calls = 0;
            if (++$calls === 3) {
                throw new \RuntimeException('synthetic second occurrence failure');
            }
        });
        try {
            $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs', $this->payload($quote, $car, 127, now('Asia/Kolkata')->format('Y-m-d'), now('Asia/Kolkata')->addDays(7)->format('Y-m-d'), 1), ['Idempotency-Key' => 'rollback-program-1'])->assertStatus(500);
        } finally {
            \STS\Models\TripPoint::flushEventListeners();
        }
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' rolled back');
        }
        $this->assertNull(DB::table('mobility_route_quotes')->where('id', $quote)->value('consumed_trip_id'));
    }

    public function test_pause_and_resume_are_versioned_and_stale_control_cannot_mutate_program(): void
    {
        [$owner, $car] = $this->host();
        $id = $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs', $this->payload($this->quote($owner), $car), ['Idempotency-Key' => 'control-program-1'])->assertCreated()->json('data.program.id');
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$id.'/control', ['expected_version' => 1, 'action' => 'pause', 'reason' => 'holiday'], ['Idempotency-Key' => 'pause-program-1'])->assertOk();
        $this->assertDatabaseHas('mobility_recurring_programs', ['id' => $id, 'state' => 'paused', 'version' => 2]);
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$id.'/control', ['expected_version' => 1, 'action' => 'resume', 'reason' => 'back'], ['Idempotency-Key' => 'stale-resume-1'])->assertStatus(409)->assertJsonPath('error.code', 'PROGRAM_VERSION_STALE');
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$id.'/control', ['expected_version' => 2, 'action' => 'resume', 'reason' => 'back'], ['Idempotency-Key' => 'resume-program-1'])->assertOk();
        $this->assertDatabaseHas('mobility_recurring_programs', ['id' => $id, 'state' => 'active', 'version' => 3]);
    }

    public function test_later_generation_uses_fresh_quote_outside_generation_locks_and_replays_without_provider(): void
    {
        [$owner, $car] = $this->host();
        $from = now('Asia/Kolkata')->format('Y-m-d');
        $end = now('Asia/Kolkata')->addDays(21)->format('Y-m-d');
        $created = $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs', $this->payload($this->quote($owner), $car, 127, $from, $end), ['Idempotency-Key' => 'fresh-generation-create'])->assertCreated();
        $id = $created->json('data.program.id');
        $watermark = $created->json('data.program.generated_through');
        $level = DB::connection()->transactionLevel();
        $calls = 0;
        $this->app->instance(RouteProvider::class, new class($calls, $level) implements RouteProvider
        {
            public function __construct(private int &$calls, private int $level) {}

            public function route(array $pickup, array $dropoff, string $vehicleType): array
            {
                if (DB::connection()->transactionLevel() !== $this->level) {
                    throw new \RuntimeException('Provider ran under an extra transaction');
                }
                $this->calls++;

                return ['distance_meters' => 1000, 'duration_seconds' => 120, 'provider' => 'synthetic-fresh', 'profile' => 'test-car'];
            }
        });
        $body = ['expected_version' => 1, 'until' => $end];
        $generated = $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$id.'/generate', $body, ['Idempotency-Key' => 'fresh-generation-key'])->assertOk();
        $this->assertSame(1, $calls);
        $future = DB::table('mobility_recurring_occurrences')->where('program_id', $id)->where('service_date', '>', $watermark)->get();
        $this->assertCount(7, $future);
        foreach ($future as $row) {
            $quote = DB::table('mobility_route_quotes')->where('id', $row->quote_id)->first();
            $snapshot = json_decode($quote->snapshot, true);
            $this->assertSame(100, $snapshot['seat_price_minor']);
            $this->assertSame('synthetic-fresh', $snapshot['provider']);
            $this->assertNotNull($quote->parent_quote_id);
            $this->assertSame(100, (int) Trip::findOrFail($row->trip_id)->seat_price_cents);
        }
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$id.'/control', ['expected_version' => 1, 'action' => 'pause', 'reason' => 'test'], ['Idempotency-Key' => 'fresh-generation-pause'])->assertOk();
        $replay = $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$id.'/generate', $body, ['Idempotency-Key' => 'fresh-generation-key'])->assertOk();
        $this->assertEquals($generated->json(), $replay->json());
        $this->assertSame(1, $calls);
    }

    public function test_requote_required_preserves_inventory_and_does_not_consume_action_key(): void
    {
        [$owner, $car] = $this->host();
        $end = now('Asia/Kolkata')->addDays(21)->format('Y-m-d');
        $created = $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs', $this->payload($this->quote($owner), $car, 127, now('Asia/Kolkata')->format('Y-m-d'), $end), ['Idempotency-Key' => 'requote-create-key'])->assertCreated();
        $id = $created->json('data.program.id');
        $before = DB::table('mobility_recurring_occurrences')->where('program_id', $id)->count();
        $this->app->instance(RouteProvider::class, new class implements RouteProvider
        {
            public function route(array $pickup, array $dropoff, string $vehicleType): array
            {
                return ['distance_meters' => 10000, 'duration_seconds' => 120, 'provider' => 'synthetic', 'profile' => 'test-car'];
            }
        });
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$id.'/generate', ['expected_version' => 1, 'until' => $end], ['Idempotency-Key' => 'requote-generate-key'])->assertStatus(409)->assertJsonPath('error.code', 'PROGRAM_REQUOTE_REQUIRED');
        $this->assertSame($created->json('data.program.generated_through'), DB::table('mobility_recurring_programs')->where('id', $id)->value('generated_through'));
        $this->assertSame($before, DB::table('mobility_recurring_occurrences')->where('program_id', $id)->count());
        $this->assertDatabaseMissing('mobility_recurring_actions', ['program_id' => $id, 'key_hash' => hash('sha256', 'requote-generate-key')]);
    }

    public function test_graph_change_without_fare_change_preserves_existing_recurring_inventory(): void
    {
        [$owner, $car] = $this->host();
        $quote = $this->quote($owner);
        $snapshot = json_decode(DB::table('mobility_route_quotes')->where('id', $quote)->value('snapshot'), true);
        $snapshot['data_version'] = \STS\Mobility\Routing\OsrmRouteProvider::dataVersion('car', str_repeat('a', 64), str_repeat('b', 64));
        DB::table('mobility_route_quotes')->where('id', $quote)->update(['snapshot' => json_encode($snapshot)]);
        $end = now('Asia/Kolkata')->addDays(21)->format('Y-m-d');
        $created = $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs', $this->payload($quote, $car, 127, now('Asia/Kolkata')->format('Y-m-d'), $end), ['Idempotency-Key' => 'graph-drift-create'])->assertCreated();
        $id = $created->json('data.program.id');
        $before = DB::table('mobility_recurring_occurrences')->where('program_id', $id)->orderBy('id')->get()->toJson();
        $tripCount = DB::table('trips')->count();
        $this->app->instance(RouteProvider::class, new class implements RouteProvider
        {
            public function route(array $pickup, array $dropoff, string $vehicleType): array
            {
                return ['distance_meters' => 1000, 'duration_seconds' => 120, 'provider' => 'synthetic', 'profile' => 'test-car', 'data_version' => \STS\Mobility\Routing\OsrmRouteProvider::dataVersion('car', str_repeat('c', 64), str_repeat('d', 64))];
            }
        });
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$id.'/generate', ['expected_version' => 1, 'until' => $end], ['Idempotency-Key' => 'graph-drift-generate'])->assertStatus(409)->assertJsonPath('error.code', 'PROGRAM_REQUOTE_REQUIRED');
        $this->assertSame($created->json('data.program.generated_through'), DB::table('mobility_recurring_programs')->where('id', $id)->value('generated_through'));
        $this->assertSame($before, DB::table('mobility_recurring_occurrences')->where('program_id', $id)->orderBy('id')->get()->toJson());
        $this->assertSame($tripCount, DB::table('trips')->count());
        $this->assertDatabaseMissing('mobility_recurring_actions', ['program_id' => $id, 'key_hash' => hash('sha256', 'graph-drift-generate')]);
    }

    public function test_noop_needs_no_provider_and_create_replay_survives_expiry_date_window_and_rate_limit(): void
    {
        [$owner, $car] = $this->host();
        $payload = $this->payload($this->quote($owner), $car);
        $created = $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs', $payload, ['Idempotency-Key' => 'stable-create-key'])->assertCreated();
        $id = $created->json('data.program.id');
        $this->app->instance(RouteProvider::class, new class implements RouteProvider
        {
            public function route(array $pickup, array $dropoff, string $vehicleType): array
            {
                throw new \RuntimeException('No-op must not route');
            }
        });
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$id.'/generate', ['expected_version' => 1, 'until' => $created->json('data.program.generated_through')], ['Idempotency-Key' => 'stable-noop-key'])->assertOk()->assertJsonPath('data.generated', []);
        // The committed replay must not depend on the first date still being in the creation window.
        \Illuminate\Support\Carbon::setTestNow(now('Asia/Kolkata')->addDays(2));
        \Carbon\CarbonImmutable::setTestNow(now('Asia/Kolkata'));
        $replay = $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs', array_reverse($payload, true), ['Idempotency-Key' => 'stable-create-key'])->assertOk();
        $expected = $created->json('data');
        $expected['existing'] = true;
        $this->assertEquals($expected, $replay->json('data'));
    }

    public function test_exception_only_timeline_pages_advance_without_materialized_dates(): void
    {
        [$owner, $car] = $this->host();
        $end = now('Asia/Kolkata')->addDays(21)->format('Y-m-d');
        $created = $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs', $this->payload($this->quote($owner), $car, 127, now('Asia/Kolkata')->format('Y-m-d'), $end), ['Idempotency-Key' => 'timeline-create-key'])->assertCreated();
        $id = $created->json('data.program.id');
        $watermark = $created->json('data.program.generated_through');
        $one = \Carbon\CarbonImmutable::parse($watermark, 'Asia/Kolkata')->addDay()->format('Y-m-d');
        $two = \Carbon\CarbonImmutable::parse($watermark, 'Asia/Kolkata')->addDays(3)->format('Y-m-d');
        foreach ([$one, $two] as $index => $date) {
            $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$id.'/exceptions', ['expected_version' => $index + 1, 'service_date' => $date, 'action' => 'skip', 'reason' => 'holiday'], ['Idempotency-Key' => 'timeline-skip-'.$index])->assertOk();
        }
        $this->actingAsApiUser($owner)->getJson('/api/mobility/recurring-programs/'.$id.'?page_size=1&after_date='.$watermark)->assertOk()->assertJsonPath('data.occurrences', [])->assertJsonPath('data.exceptions.0.service_date', $one)->assertJsonPath('data.next_cursor', $one);
        $this->actingAsApiUser($owner)->getJson('/api/mobility/recurring-programs/'.$id.'?page_size=1&after_date='.$one)->assertOk()->assertJsonPath('data.occurrences', [])->assertJsonPath('data.exceptions.0.service_date', $two)->assertJsonPath('data.next_cursor', null);
    }

    public function test_explicit_future_pricing_approval_keeps_original_source_and_audits_fresh_quote(): void
    {
        [$owner, $car] = $this->host();
        $source = $this->quote($owner);
        $end = now('Asia/Kolkata')->addDays(21)->format('Y-m-d');
        $created = $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs', $this->payload($source, $car, 127, now('Asia/Kolkata')->format('Y-m-d'), $end), ['Idempotency-Key' => 'pricing-approval-create'])->assertCreated();
        $id = $created->json('data.program.id');
        $fresh = $this->quote($owner, 987, 'test-v2');
        $edit = ['expected_version' => 1, 'effective_from' => $created->json('data.program.next_edit_from'), 'quote_id' => $fresh, 'reason' => 'Explicit synthetic price approval'];
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$id.'/edit', $edit, ['Idempotency-Key' => 'pricing-approval-edit'])->assertOk()->assertJsonPath('data.program.max_fare_minor', 987)->assertJsonPath('data.program.pricing_version', 'test-v2');
        $this->assertSame($source, DB::table('mobility_recurring_programs')->where('id', $id)->value('source_quote_id'));
        $action = DB::table('mobility_recurring_actions')->where('program_id', $id)->where('action', 'edit')->first();
        $evidence = json_decode($action->after_snapshot, true);
        $this->assertSame($fresh, $evidence['approval_quote_id']);
        $this->assertSame(hash('sha256', DB::table('mobility_route_quotes')->where('id', $fresh)->value('snapshot')), $evidence['approval_quote_snapshot_hash']);
        foreach ($created->json('data.occurrences') as $item) {
            $this->assertSame(321, (int) Trip::findOrFail($item['trip_id'])->seat_price_cents);
        }
        // Reordered edit fields must replay the same decision, even after its quote expires.
        DB::table('mobility_route_quotes')->where('id', $fresh)->update(['expires_at' => now('UTC')->subMinute()]);
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$id.'/edit', array_reverse($edit, true), ['Idempotency-Key' => 'pricing-approval-edit'])->assertOk()->assertJsonPath('data.program.version', 2);
    }

    private function payload(string $quote, Car $car, int $mask = 127, ?string $from = null, ?string $to = null, int $seats = 1): array
    {
        return ['quote_id' => $quote, 'car_id' => $car->id, 'weekly_schedule' => $mask, 'departure_time' => '23:30', 'date_from' => $from ?: now('Asia/Kolkata')->format('Y-m-d'), 'date_to' => $to ?: now('Asia/Kolkata')->addDays(7)->format('Y-m-d'), 'total_seats' => $seats, 'description' => 'Synthetic recurring fixture'];
    }

    private function host(): array
    {
        $owner = User::factory()->create(['active' => true, 'banned' => false, 'identity_validated' => true, 'nro_doc' => 'SYNTHETIC-ID', 'mobile_phone' => '0000000000']);
        DB::table('mobility_memberships')->insert(['user_id' => $owner->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $car = Car::factory()->withCatalog()->create(['user_id' => $owner->id]);
        DB::table('mobility_host_applications')->insert(['user_id' => $owner->id, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $car];
    }

    private function quote(User $owner, int $fare = 321, string $version = 'test-v1'): string
    {
        foreach ([[501, 'Synthetic A', 13.0, 77.5], [502, 'Synthetic B', 13.01, 77.51]] as [$id, $name, $lat, $lng]) {
            DB::table('nodes_geo')->updateOrInsert(['id' => $id], ['name' => $name, 'lat' => $lat, 'lng' => $lng, 'type' => 'synthetic']);
        }
        foreach ([[101, 501, 'Synthetic A', 13.0, 77.5], [102, 502, 'Synthetic B', 13.01, 77.51]] as [$id, $node, $name, $lat, $lng]) {
            DB::table('mobility_pickup_points')->updateOrInsert(['id' => $id], ['college_code' => 'CAMPUS-BLR', 'canonical_node_id' => $node, 'version' => 1, 'name' => $name, 'lat' => $lat, 'lng' => $lng, 'vehicle_modes' => json_encode(['car']), 'active' => true, 'verified_at' => now(), 'synthetic_only' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $id = (string) Str::uuid();
        $points = [['address' => 'Synthetic A', 'json_address' => ['id' => 501, 'name' => 'Synthetic A'], 'lat' => 13.0, 'lng' => 77.5], ['address' => 'Synthetic B', 'json_address' => ['id' => 502, 'name' => 'Synthetic B'], 'lat' => 13.01, 'lng' => 77.51]];
        DB::table('mobility_route_quotes')->insert(['id' => $id, 'user_id' => $owner->id, 'college_code' => 'CAMPUS-BLR', 'snapshot' => json_encode(['vehicle_type' => 'car', 'provider' => 'synthetic', 'profile' => 'car', 'points' => $points, 'distance_meters' => 2000, 'duration_seconds' => 600, 'currency' => 'INR', 'seat_price_minor' => $fare, 'pricing_version' => $version, 'synthetic_only' => true, 'pickup_points' => [['id' => 101, 'version' => 1, 'canonical_node_id' => 501], ['id' => 102, 'version' => 1, 'canonical_node_id' => 502]]]), 'expires_at' => now('UTC')->addMinutes(5), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
