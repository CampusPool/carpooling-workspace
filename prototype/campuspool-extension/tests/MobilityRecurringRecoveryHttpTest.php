<?php

namespace Tests\Feature\Mobility;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Payments\PaymentService;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

/** Recovery and admission behavior for real dated recurring inventory. */
final class MobilityRecurringRecoveryHttpTest extends TestCase
{
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Carbon::setTestNow('2026-10-20 12:00:00');
        CarbonImmutable::setTestNow('2026-10-20 12:00:00');
        foreach (['MOBILITY_COHORT_REQUIRED', 'MOBILITY_COLLEGE_CODE'] as $key) {
            $this->previous[$key] = getenv($key);
        }
        config(['app.timezone' => 'Asia/Kolkata', 'mobility_routing.synthetic_only' => true]);
        putenv('MOBILITY_COHORT_REQUIRED=true');
        putenv('MOBILITY_COLLEGE_CODE=CAMPUS-BLR');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        foreach ($this->previous as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
        parent::tearDown();
    }

    public function test_request_and_accept_on_one_date_does_not_consume_other_date_capacity(): void
    {
        [$owner, $car, $program] = $this->program(1 | 4, 2);
        $occurrences = DB::table('mobility_recurring_occurrences')->where('program_id', $program)->orderBy('service_date')->get();
        $this->assertGreaterThanOrEqual(2, $occurrences->count());
        $rider = $this->member();
        $first = $occurrences[0];
        $second = $occurrences[1];
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$first->trip_id.'/requests')->assertOk();
        $this->actingAsApiUser($owner)->postJson('/api/trips/'.$first->trip_id.'/requests/'.$rider->id.'/accept')->assertOk();
        $this->assertSame(1, DB::table('trip_passengers')->where('trip_id', $first->trip_id)->where('request_state', Passenger::STATE_ACCEPTED)->count());
        $this->assertSame(0, DB::table('trip_passengers')->where('trip_id', $second->trip_id)->where('request_state', Passenger::STATE_ACCEPTED)->count());
        $this->assertSame(2, (int) Trip::query()->findOrFail($second->trip_id)->total_seats);
        $this->assertSame($program, DB::table('mobility_recurring_occurrences')->where('trip_id', $first->trip_id)->value('program_id'));
    }

    public function test_pause_blocks_discovery_request_and_accept_but_pending_cancel_and_history_remain_available(): void
    {
        [$owner, $car, $program] = $this->program(127, 1);
        $occurrence = DB::table('mobility_recurring_occurrences')->where('program_id', $program)->orderBy('service_date')->first();
        $rider = $this->member();
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$occurrence->trip_id.'/requests')->assertOk();
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$program.'/control', ['expected_version' => 1, 'action' => 'pause', 'reason' => 'holiday'], ['Idempotency-Key' => 'recovery-pause-1'])->assertOk();
        $this->actingAsApiUser($rider)->getJson('/api/mobility/pool-offers?service_date='.$occurrence->service_date)->assertOk()->assertJsonMissing(['id' => $occurrence->trip_id]);
        $this->actingAsApiUser($owner)->postJson('/api/trips/'.$occurrence->trip_id.'/requests/'.$rider->id.'/accept')->assertStatus(409)->assertJsonPath('error.code', 'PROGRAM_PAUSED');
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$occurrence->trip_id.'/requests/'.$rider->id.'/cancel')->assertOk();
        $this->actingAsApiUser($rider)->getJson('/api/trips/'.$occurrence->trip_id)->assertOk();
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$program.'/control', ['expected_version' => 2, 'action' => 'resume', 'reason' => 'back'], ['Idempotency-Key' => 'recovery-resume-1'])->assertOk();
        $this->assertDatabaseHas('mobility_recurring_programs', ['id' => $program, 'state' => 'active', 'version' => 3]);
        $this->assertDatabaseHas('trip_passengers', ['trip_id' => $occurrence->trip_id, 'user_id' => $rider->id, 'request_state' => Passenger::STATE_CANCELED]);
    }

    public function test_skip_accepted_date_cancels_only_that_trip_and_records_payment_block(): void
    {
        [$owner, $car, $program] = $this->program(1 | 4, 1);
        $rows = DB::table('mobility_recurring_occurrences')->where('program_id', $program)->orderBy('service_date')->get();
        $this->assertGreaterThanOrEqual(2, $rows->count());
        $rider = $this->member();
        $target = $rows[0];
        $other = $rows[1];
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$target->trip_id.'/requests')->assertOk();
        $this->actingAsApiUser($owner)->postJson('/api/trips/'.$target->trip_id.'/requests/'.$rider->id.'/accept')->assertOk();
        $booking = Passenger::query()->where('trip_id', $target->trip_id)->where('user_id', $rider->id)->firstOrFail();
        $trip = Trip::query()->findOrFail($target->trip_id);
        $payments = app(PaymentService::class);
        $payments->snapshotFare($trip, $booking, 321, 32);
        $payments->createPaymentAttempt($trip, $booking, 'test-provider', 'test', 'recurring-order-'.$booking->id);
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$program.'/exceptions', ['expected_version' => 1, 'service_date' => $target->service_date, 'action' => 'skip', 'reason' => 'road closure'], ['Idempotency-Key' => 'skip-recovery-1'])->assertOk()->assertJsonPath('data.financial_recovery_required', true);
        $this->assertDatabaseHas('mobility_recurring_exceptions', ['program_id' => $program, 'service_date' => $target->service_date, 'action' => 'skip']);
        $this->assertDatabaseHas('mobility_trip_lifecycles', ['trip_id' => $target->trip_id, 'state' => 'cancelled']);
        $this->assertDatabaseHas('mobility_payment_blocks', ['booking_id' => $booking->id, 'trip_id' => $target->trip_id, 'reason' => 'recurring_occurrence_cancelled', 'active' => true]);
        $this->assertDatabaseMissing('mobility_trip_lifecycles', ['trip_id' => $other->trip_id, 'state' => 'cancelled']);
        $this->assertDatabaseMissing('mobility_recurring_exceptions', ['program_id' => $program, 'service_date' => $other->service_date]);
    }

    public function test_future_edit_keeps_published_occurrence_terms_and_rejects_malicious_fields(): void
    {
        [$owner, $car, $program] = $this->program(127, 1);
        $published = DB::table('mobility_recurring_occurrences')->where('program_id', $program)->orderBy('service_date')->first();
        $beforeTrip = Trip::query()->findOrFail($published->trip_id);
        $beforeSnapshot = DB::table('mobility_recurring_occurrences')->where('id', $published->id)->value('config_snapshot');
        $p = DB::table('mobility_recurring_programs')->where('id', $program)->first();
        $effective = CarbonImmutable::parse($p->generated_through, 'Asia/Kolkata')->addDay()->format('Y-m-d');
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$program.'/edit', ['expected_version' => $p->version, 'effective_from' => $effective, 'departure_time' => '22:15', 'total_seats' => 2, 'reason' => 'new timetable'], ['Idempotency-Key' => 'edit-recovery-1'])->assertOk();
        $afterTrip = Trip::query()->findOrFail($published->trip_id);
        $this->assertEquals($beforeTrip->trip_date, $afterTrip->trip_date);
        $this->assertSame($beforeTrip->seat_price_cents, $afterTrip->seat_price_cents);
        $this->assertSame($beforeSnapshot, DB::table('mobility_recurring_occurrences')->where('id', $published->id)->value('config_snapshot'));
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$program.'/edit', ['expected_version' => $p->version + 1, 'effective_from' => $effective, 'state' => 'paid', 'reason' => 'injection'], ['Idempotency-Key' => 'edit-injection-1'])->assertStatus(422)->assertJsonPath('error.code', 'PROGRAM_INPUT_INVALID');
    }

    public function test_failure_on_second_payment_block_rolls_back_cancellation_and_every_control_row(): void
    {
        [$owner, $car, $program] = $this->program(1 | 4, 2);
        $occ = DB::table('mobility_recurring_occurrences')->where('program_id', $program)->orderBy('service_date')->first();
        $riders = [$this->member(), $this->member()];
        foreach ($riders as $rider) {
            $this->actingAsApiUser($rider)->postJson('/api/trips/'.$occ->trip_id.'/requests')->assertOk();
            $this->actingAsApiUser($owner)->postJson('/api/trips/'.$occ->trip_id.'/requests/'.$rider->id.'/accept')->assertOk();
        }
        $writes = 0;
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $event) use (&$writes): void {
            if (str_starts_with(strtolower($event->sql), 'insert into `mobility_payment_blocks`') && ++$writes === 2) {
                throw new \RuntimeException('Synthetic second recovery-block failure');
            }
        });
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$program.'/exceptions', ['expected_version' => 1, 'service_date' => $occ->service_date, 'action' => 'skip', 'reason' => 'atomic failure fixture'], ['Idempotency-Key' => 'skip-block-failure-key'])->assertStatus(500)->assertJsonPath('error.code', 'PROGRAM_UNAVAILABLE');
        $this->assertSame(2, $writes);
        $this->assertSame(2, DB::table('trip_passengers')->where('trip_id', $occ->trip_id)->where('request_state', Passenger::STATE_ACCEPTED)->count());
        $this->assertDatabaseMissing('mobility_trip_lifecycles', ['trip_id' => $occ->trip_id, 'state' => 'cancelled']);
        $this->assertDatabaseMissing('mobility_recurring_exceptions', ['program_id' => $program]);
        $this->assertDatabaseMissing('mobility_payment_blocks', ['trip_id' => $occ->trip_id]);
        $this->assertDatabaseMissing('mobility_recurring_actions', ['program_id' => $program, 'action' => 'skip']);
        $this->assertDatabaseHas('mobility_recurring_programs', ['id' => $program, 'version' => 1]);
        $this->assertSame(Trip::STATE_READY, Trip::findOrFail($occ->trip_id)->state);
    }

    public function test_suspended_member_keeps_owner_controls_and_history_but_cannot_edit_or_generate(): void
    {
        [$owner, $car, $program] = $this->program(127, 1);
        DB::table('mobility_memberships')->where('user_id', $owner->id)->update(['status' => 'suspended']);
        $this->actingAsApiUser($owner)->getJson('/api/mobility/recurring-programs/'.$program)->assertOk();
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$program.'/control', ['expected_version' => 1, 'action' => 'pause', 'reason' => 'recover while suspended'], ['Idempotency-Key' => 'suspended-pause-key'])->assertOk();
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$program.'/generate', ['expected_version' => 2, 'until' => '2026-10-28'], ['Idempotency-Key' => 'suspended-generate-key'])->assertStatus(403)->assertJsonPath('error.code', 'MEMBERSHIP_REQUIRED');
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$program.'/edit', ['expected_version' => 2, 'effective_from' => '2026-10-29', 'total_seats' => 2, 'reason' => 'must fail'], ['Idempotency-Key' => 'suspended-edit-key'])->assertStatus(403);
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$program.'/exceptions', ['expected_version' => 2, 'service_date' => '2026-10-21', 'action' => 'skip', 'reason' => 'recover while suspended'], ['Idempotency-Key' => 'suspended-skip-key'])->assertOk()->assertJsonPath('data.financial_recovery_required', false);
    }

    public function test_invalid_control_scalars_null_edits_calendar_dates_and_changed_vehicle_are_denied(): void
    {
        [$owner, $car, $program] = $this->program(127, 1);
        foreach ([['total_seats' => null], ['weekly_schedule' => null], ['date_to' => '2026-02-30']] as $index => $bad) {
            $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$program.'/edit', $bad + ['expected_version' => 1, 'effective_from' => '2026-10-29', 'reason' => 'invalid fixture'], ['Idempotency-Key' => 'invalid-edit-'.$index])->assertStatus(422);
        }
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$program.'/control', ['expected_version' => 1, 'action' => [], 'reason' => 'fixture'], ['Idempotency-Key' => 'invalid-control-key'])->assertStatus(422);
        $car->description = 'Changed after approval';
        $car->save();
        $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs/'.$program.'/generate', ['expected_version' => 1, 'until' => '2026-10-28'], ['Idempotency-Key' => 'changed-vehicle-key'])->assertStatus(403)->assertJsonPath('error.code', 'HOST_APPROVAL_REQUIRED');
        $this->assertDatabaseHas('mobility_recurring_programs', ['id' => $program, 'version' => 1]);
    }

    public function test_banned_account_cannot_read_owned_program_or_list_even_with_existing_login(): void
    {
        [$owner, $car, $program] = $this->program(127, 1);
        $owner->banned = true;
        $owner->save();
        $this->actingAsApiUser($owner)->getJson('/api/mobility/recurring-programs')->assertStatus(403);
        $this->actingAsApiUser($owner)->getJson('/api/mobility/recurring-programs/'.$program)->assertStatus(403);
        foreach (['list', 'show'] as $method) {
            try {
                app(\STS\Mobility\Recurrence\ProgramService::class)->$method($owner, ...($method === 'show' ? [$program] : []));
                $this->fail('Banned account admitted');
            } catch (\STS\Mobility\Recurrence\ProgramException $error) {
                $this->assertSame('ACCOUNT_RESTRICTED', $error->machineCode);
            }
        }
    }

    private function program(int $mask, int $seats): array
    {
        $owner = $this->host();
        $quote = $this->quote($owner);
        $car = Car::query()->where('user_id', $owner->id)->firstOrFail();
        $payload = ['quote_id' => $quote, 'car_id' => $car->id, 'weekly_schedule' => $mask, 'departure_time' => '23:30', 'date_from' => '2026-10-20', 'date_to' => '2026-10-28', 'total_seats' => $seats, 'description' => 'recovery fixture'];
        $response = $this->actingAsApiUser($owner)->postJson('/api/mobility/recurring-programs', $payload, ['Idempotency-Key' => 'create-recovery-'.Str::lower(Str::random(10))])->assertCreated();

        return [$owner, $car, $response->json('data.program.id')];
    }

    private function host(): User
    {
        $owner = User::factory()->create(['active' => true, 'banned' => false, 'identity_validated' => true, 'nro_doc' => 'RECOVERY-ID', 'mobile_phone' => '0000000000']);
        DB::table('mobility_memberships')->insert(['user_id' => $owner->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $car = Car::factory()->withCatalog()->create(['user_id' => $owner->id]);
        DB::table('mobility_host_applications')->insert(['user_id' => $owner->id, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $owner;
    }

    private function member(): User
    {
        $rider = User::factory()->create(['active' => true, 'banned' => false, 'identity_validated' => true]);
        DB::table('mobility_memberships')->insert(['user_id' => $rider->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $rider;
    }

    private function quote(User $owner): string
    {
        foreach ([[501, 'Recovery A', 13.0, 77.5], [502, 'Recovery B', 13.01, 77.51]] as [$id, $name, $lat, $lng]) {
            DB::table('nodes_geo')->updateOrInsert(['id' => $id], ['name' => $name, 'lat' => $lat, 'lng' => $lng, 'type' => 'synthetic']);
        }
        foreach ([[101, 501, 'Recovery A', 13.0, 77.5], [102, 502, 'Recovery B', 13.01, 77.51]] as [$id, $node, $name, $lat, $lng]) {
            DB::table('mobility_pickup_points')->updateOrInsert(['id' => $id], ['college_code' => 'CAMPUS-BLR', 'canonical_node_id' => $node, 'version' => 1, 'name' => $name, 'lat' => $lat, 'lng' => $lng, 'vehicle_modes' => json_encode(['car']), 'active' => true, 'verified_at' => now(), 'synthetic_only' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $id = (string) Str::uuid();
        $points = [['address' => 'Recovery A', 'json_address' => ['id' => 501, 'name' => 'Recovery A'], 'lat' => 13.0, 'lng' => 77.5], ['address' => 'Recovery B', 'json_address' => ['id' => 502, 'name' => 'Recovery B'], 'lat' => 13.01, 'lng' => 77.51]];
        DB::table('mobility_route_quotes')->insert(['id' => $id, 'user_id' => $owner->id, 'college_code' => 'CAMPUS-BLR', 'snapshot' => json_encode(['vehicle_type' => 'car', 'provider' => 'synthetic', 'profile' => 'car', 'points' => $points, 'distance_meters' => 2000, 'duration_seconds' => 600, 'currency' => 'INR', 'seat_price_minor' => 321, 'pricing_version' => 'test-v1', 'synthetic_only' => true, 'pickup_points' => [['id' => 101, 'version' => 1, 'canonical_node_id' => 501], ['id' => 102, 'version' => 1, 'canonical_node_id' => 502]]]), 'expires_at' => now('UTC')->addMinutes(5), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
