<?php

namespace Tests\Feature\Mobility;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use STS\Mobility\Payments\PaymentException;
use STS\Mobility\Routing\RouteProvider;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

final class MobilitySegmentBookingTest extends TestCase
{
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20 12:00:00');
        \Illuminate\Support\Carbon::setTestNow('2026-10-20 12:00:00');
        foreach (['MOBILITY_COHORT_REQUIRED' => 'true', 'MOBILITY_COLLEGE_CODE' => 'CAMPUS-BLR'] as $key => $value) {
            $this->previous[$key] = getenv($key);
            putenv($key.'='.$value);
        }
        config(['app.timezone' => 'Asia/Kolkata', 'mobility_routing.pricing_version' => 'test-v1', 'mobility_routing.car_minor_per_km' => 100, 'mobility_routing.synthetic_only' => true]);
        $this->bindProvider();
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

    public function test_one_seat_trip_accepts_adjacent_intervals_and_releases_only_the_cancelled_leg(): void
    {
        [$host, $car] = $this->host();
        $trip = $this->publish($host, $car);
        [$ab, $riderAb] = $this->requestFor(101, 102, 'rider-ab');
        [$ac, $riderAc] = $this->search(101, 103, 'rider-ac');
        $this->accept($host, $trip, $riderAb);
        [$bc, $riderBc] = $this->requestFor(102, 103, 'rider-bc');
        $this->accept($host, $trip, $riderBc);
        $this->assertSame([0, 0], $this->free($trip));

        $this->requestMatch($riderAc, $ac, 'request-ac')->assertCreated();
        $this->actingAsApiUser($host)->postJson('/api/trips/'.$trip.'/requests/'.$riderAc->id.'/accept')->assertStatus(422);
        $this->assertDatabaseHas('trip_passengers', ['trip_id' => $trip, 'user_id' => $riderAc->id, 'request_state' => Passenger::STATE_PENDING]);

        $this->actingAsApiUser($riderAb)->postJson('/api/trips/'.$trip.'/requests/'.$riderAb->id.'/cancel')->assertOk();
        $this->assertSame([1, 0], $this->free($trip));
        [$abAgain, $riderAgain] = $this->requestFor(101, 102, 'rider-ab-again');
        $this->accept($host, $trip, $riderAgain);
        $this->assertSame([0, 0], $this->free($trip));
    }

    public function test_expired_foreign_and_catalog_changed_references_are_denied(): void
    {
        [$host, $car] = $this->host();
        $trip = $this->publish($host, $car);
        [$expired, $rider] = $this->search(101, 102, 'expired');
        DB::table('mobility_match_references')->where('id', $expired)->update(['expires_at' => now('UTC')->subSecond()]);
        $this->requestMatch($rider, $expired, 'expired-key')->assertStatus(409)->assertJsonPath('error.code', 'MATCH_REFERENCE_EXPIRED');

        [$foreign, $owner] = $this->search(101, 102, 'foreign-owner');
        $other = $this->member('foreign-other');
        $this->requestMatch($other, $foreign, 'foreign-key')->assertStatus(404)->assertJsonPath('error.code', 'MATCH_NOT_FOUND');

        [$changed, $changedRider] = $this->search(101, 102, 'catalog-change');
        DB::table('mobility_pickup_points')->where('id', 101)->update(['version' => 2]);
        $this->requestMatch($changedRider, $changed, 'catalog-key')->assertStatus(409)->assertJsonPath('error.code', 'MATCH_ROUTE_CHANGED');
        $this->assertNotNull($trip);
        $this->assertNotNull($owner);
    }

    public function test_exact_key_replays_a_committed_request_after_reference_expiry(): void
    {
        [$host, $car] = $this->host();
        $trip = $this->publish($host, $car);
        [$match, $rider] = $this->search(101, 102, 'replay');
        $first = $this->requestMatch($rider, $match, 'replay-key')->assertCreated();
        DB::table('mobility_match_references')->where('id', $match)->update(['expires_at' => now('UTC')->subSecond()]);
        $this->requestMatch($rider, $match, 'replay-key')->assertOk()->assertJsonPath('data.existing', true)
            ->assertJsonPath('data.booking_id', $first->json('data.booking_id'))->assertJsonPath('data.trip_id', $trip);
    }

    public function test_autoaccept_and_direct_repository_keep_interval_authority(): void
    {
        [$host, $car] = $this->host();
        $host->forceFill(['autoaccept_requests' => true])->save();
        $trip = $this->publish($host, $car);
        [$match, $rider] = $this->search(102, 103, 'autoaccept');
        $response = $this->requestMatch($rider, $match, 'autoaccept-key')->assertCreated();
        $booking = Passenger::findOrFail($response->json('data.booking_id'));
        $this->assertSame(Passenger::STATE_ACCEPTED, (int) $booking->request_state);
        $interval = DB::table('mobility_booking_intervals')->where('booking_id', $booking->id)->first();
        $this->assertSame(1, (int) $interval->from_index);
        $this->assertSame(2, (int) $interval->till_index);

        [$directHost, $directCar] = $this->host();
        $directTrip = $this->publish($directHost, $directCar);
        $direct = $this->member('direct-repository');
        $repository = app(\STS\Repository\PassengersRepository::class);
        $directBooking = $repository->newRequest($directTrip, $direct, []);
        $this->assertNotNull($directBooking);
        $directBooking = $repository->acceptRequest($directTrip, $direct->id, $directHost, []);
        $this->assertNotNull($directBooking);
        $this->expectException(PaymentException::class);
        try {
            app(\STS\Mobility\Payments\PaymentService::class)->snapshotFare(Trip::findOrFail($directTrip), $directBooking, 1);
        } catch (PaymentException $error) {
            $this->assertSame('FARE_MISMATCH', $error->codeName);
            throw $error;
        }
    }

    public function test_direct_model_creation_cannot_bypass_managed_booking_context(): void
    {
        [$host, $car] = $this->host();
        $trip = $this->publish($host, $car);
        $rider = $this->member('model-bypass');
        $before = DB::table('trip_passengers')->where('trip_id', $trip)->count();
        $blocked = false;
        try {
            Passenger::create(['trip_id' => $trip, 'user_id' => $rider->id, 'request_state' => Passenger::STATE_PENDING, 'passenger_type' => Passenger::TYPE_PASAJERO]);
        } catch (\Throwable $error) {
            $blocked = true;
        }
        $this->assertTrue($blocked, 'A managed booking requires the server interval context.');
        $this->assertSame($before, DB::table('trip_passengers')->where('trip_id', $trip)->count());
    }

    public function test_corrupt_interval_is_conservatively_counted_as_full_route_and_pay_cannot_promote(): void
    {
        [$host, $car] = $this->host();
        $trip = $this->publish($host, $car);
        [$ref, $rider] = $this->requestFor(101, 102, 'corrupt');
        $this->accept($host, $trip, $rider);
        $booking = Passenger::where('trip_id', $trip)->where('user_id', $rider->id)->firstOrFail();
        DB::table('mobility_booking_intervals')->where('booking_id', $booking->id)->update(['route_hash' => str_repeat('0', 64)]);
        $this->assertSame([0, 0], $this->free($trip));
        DB::table('trip_passengers')->where('id', $booking->id)->update(['request_state' => Passenger::STATE_WAITING_PAYMENT]);
        try {
            app(\STS\Repository\PassengersRepository::class)->payRequest($trip, $rider->id, $rider, []);
            $this->fail('Legacy payment bypass');
        } catch (\STS\Mobility\Segments\SegmentException $error) {
            $this->assertSame('LEGACY_PAYMENT_PROMOTION_DISABLED', $error->machineCode);
        }
        $this->assertDatabaseHas('trip_passengers', ['id' => $booking->id, 'request_state' => Passenger::STATE_WAITING_PAYMENT]);
    }

    public function test_trusted_interval_fare_reaches_financial_snapshot_and_cash_rechecks_it(): void
    {
        [$host, $car] = $this->host();
        $trip = $this->publish($host, $car);
        [$ref, $rider] = $this->requestFor(101, 102, 'fare');
        $this->accept($host, $trip, $rider);
        $booking = Passenger::where('trip_id', $trip)->where('user_id', $rider->id)->firstOrFail();
        $fare = app(\STS\Mobility\Payments\PaymentService::class)->snapshotFare(Trip::findOrFail($trip), $booking, 151, 15);
        $this->assertSame(151, (int) $fare->gross_amount);
        $this->actingAsApiUser($host)->postJson('/api/mobility/trips/'.$trip.'/transitions', ['action' => 'start'], ['Idempotency-Key' => 'cash-start-key'])->assertOk();
        $this->actingAsApiUser($host)->postJson('/api/mobility/trips/'.$trip.'/transitions', ['action' => 'board', 'passenger_id' => $rider->id], ['Idempotency-Key' => 'cash-board-key'])->assertOk();
        DB::table('mobility_fare_snapshots')->where('id', $fare->id)->update(['gross_amount' => 999]);
        $this->actingAsApiUser($rider)->postJson('/api/mobility/trips/'.$trip.'/bookings/'.$booking->id.'/cash-receipts', ['action' => 'rider_paid'], ['Idempotency-Key' => 'wrong-cash-fare-key'])->assertStatus(422)->assertJsonPath('error.code', 'CASH_FARE_MISMATCH');
        $this->assertDatabaseMissing('mobility_cash_receipts', ['booking_id' => $booking->id]);
    }

    public function test_whole_trip_cancel_frees_both_intervals_and_retains_history_and_recovery_blocks(): void
    {
        [$host, $car] = $this->host();
        $trip = $this->publish($host, $car);
        [$ab, $a] = $this->requestFor(101, 102, 'cancel-a');
        $this->accept($host, $trip, $a);
        [$bc, $b] = $this->requestFor(102, 103, 'cancel-b');
        $this->accept($host, $trip, $b);
        $this->actingAsApiUser($host)->postJson('/api/mobility/trips/'.$trip.'/transitions', ['action' => 'cancel'], ['Idempotency-Key' => 'cancel-segment-trip'])->assertOk();
        $this->assertSame([1, 1], $this->free($trip));
        $this->assertSame(2, DB::table('mobility_booking_intervals')->where('trip_id', $trip)->count());
        $this->assertSame(2, DB::table('mobility_payment_blocks')->where('trip_id', $trip)->where('reason', 'trip_cancelled')->count());
        $this->assertSame(0, DB::table('mobility_financial_postings')->count());
        $this->actingAsApiUser($host)->deleteJson('/api/trips/'.$trip)->assertStatus(409);
    }

    public function test_multi_stop_recurrence_keeps_all_legs_in_initial_and_fresh_later_dates(): void
    {
        [$host, $car] = $this->host();
        $quote = $this->actingAsApiUser($host)->postJson('/api/mobility/routes/quote', ['stop_ids' => [101, 102, 103], 'vehicle_type' => 'car'])->assertCreated()->json('data.id');
        $created = $this->actingAsApiUser($host)->postJson('/api/mobility/recurring-programs', ['quote_id' => $quote, 'car_id' => $car->id, 'weekly_schedule' => 127, 'departure_time' => '10:00', 'date_from' => '2026-10-21', 'date_to' => '2026-11-10', 'total_seats' => 1], ['Idempotency-Key' => 'multi-recurrence-key'])->assertCreated()->assertJsonPath('data.program.stop_ids', [101, 102, 103]);
        $id = $created->json('data.program.id');
        $this->actingAsApiUser($host)->postJson('/api/mobility/recurring-programs/'.$id.'/generate', ['expected_version' => 1, 'until' => '2026-11-10'], ['Idempotency-Key' => 'multi-recurrence-gen'])->assertOk();
        $rows = DB::table('mobility_recurring_occurrences')->where('program_id', $id)->get();
        $this->assertCount(21, $rows);
        foreach ($rows as $row) {
            $snapshot = json_decode(DB::table('mobility_route_quotes')->where('id', $row->quote_id)->value('snapshot'), true);
            $this->assertCount(3, $snapshot['pickup_points']);
            $this->assertCount(2, $snapshot['legs']);
            $this->assertSame(2, DB::table('trip_routes')->where('trip_id', $row->trip_id)->count());
        }
    }

    public function test_overlapping_accepted_driver_obligation_blocks_second_trip_until_cancelled(): void
    {
        [$host, $car] = $this->host();
        $first = $this->publish($host, $car);
        $second = $this->publish($host, $car, 1);
        $riderA = $this->member('overlap-a'); $riderB = $this->member('overlap-b');
        foreach ([[$first, $riderA], [$second, $riderB]] as [$trip, $rider]) $this->actingAsApiUser($rider)->postJson('/api/trips/'.$trip.'/requests')->assertOk();
        $this->accept($host, $first, $riderA); // The other empty publication does not reserve a driver.
        $this->actingAsApiUser($host)->postJson('/api/trips/'.$second.'/requests/'.$riderB->id.'/accept')->assertStatus(409)->assertJsonPath('error.code', 'DRIVER_ROUTE_OVERLAP');
        $this->assertDatabaseHas('trip_passengers', ['trip_id' => $second, 'user_id' => $riderB->id, 'request_state' => Passenger::STATE_PENDING]);
        $this->actingAsApiUser($riderA)->postJson('/api/trips/'.$first.'/requests/'.$riderA->id.'/cancel')->assertOk();
        $this->accept($host, $second, $riderB);
    }

    public function test_driver_reservation_windows_allow_exact_buffered_adjacency(): void
    {
        [$host, $car] = $this->host();
        config(['mobility_routing.driver_reservation_buffer_seconds' => 30]);
        $first = $this->publish($host, $car);
        $second = $this->publish($host, $car, 5); // 240s route + 30s on each side = 300s.
        foreach ([$first, $second] as $index => $trip) {
            $rider = $this->member('adjacent-'.$index);
            $this->actingAsApiUser($rider)->postJson('/api/trips/'.$trip.'/requests')->assertOk();
            $this->accept($host, $trip, $rider);
        }
    }

    public function test_private_approach_time_and_overdue_accepted_obligations_are_not_omitted(): void
    {
        [$host, $car] = $this->host();
        $trip = $this->publish($host, $car);
        $rider = $this->member('approach-obligation');
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$trip.'/requests')->assertOk();
        $this->accept($host, $trip, $rider);
        $departure = CarbonImmutable::parse(Trip::findOrFail($trip)->trip_date, 'Asia/Kolkata');
        foreach ([[$departure->subMinutes(20), 'DRIVER_ROUTE_OVERLAP'], [$departure->addMinute(), 'DRIVER_ACTIVE_TRIP']] as [$clock, $expected]) {
            CarbonImmutable::setTestNow($clock); \Illuminate\Support\Carbon::setTestNow($clock);
            try {
                DB::transaction(function () use ($host): void {
                    \STS\Mobility\Admission\DriverReservationPolicy::fence($host->id);
                    \STS\Mobility\Admission\DriverReservationPolicy::immediate($host->id, ['private' => true, 'snapshot' => ['duration_seconds' => 60], 'private_snapshot' => ['driver_path' => ['duration_seconds' => 1800]]], CarbonImmutable::now('UTC'));
                });
                $this->fail('An accepted driver obligation must block this new admission.');
            } catch (\STS\Mobility\Segments\SegmentException $error) { $this->assertSame($expected, $error->machineCode); }
        }
    }

    private function requestFor(int $pickup, int $dropoff, string $label): array
    {
        [$match, $rider] = $this->search($pickup, $dropoff, $label);
        $this->requestMatch($rider, $match, 'request-'.$label)->assertCreated();

        return [$match, $rider];
    }

    private function requestMatch(User $rider, string $match, string $key)
    {
        return $this->actingAsApiUser($rider)->postJson('/api/mobility/pool-matches/'.$match.'/requests', [], ['Idempotency-Key' => $key]);
    }

    private function search(int $pickup, int $dropoff, string $label): array
    {
        $rider = $this->member($label);
        $response = $this->actingAsApiUser($rider)->postJson('/api/mobility/pool-matches', [
            'service_date' => now('Asia/Kolkata')->addDays(2)->format('Y-m-d'),
            'pickup_point_id' => $pickup, 'dropoff_point_id' => $dropoff,
            'from_time' => '00:00', 'to_time' => '23:59', 'preferred_time' => '12:00', 'vehicle_type' => 'car',
        ])->assertOk()->assertJsonCount(1, 'data');

        return [$response->json('data.0.match_id'), $rider];
    }

    private function accept(User $host, int $trip, User $rider): void
    {
        $this->actingAsApiUser($host)->postJson('/api/trips/'.$trip.'/requests/'.$rider->id.'/accept')->assertOk();
        $this->assertDatabaseHas('trip_passengers', ['trip_id' => $trip, 'user_id' => $rider->id, 'request_state' => Passenger::STATE_ACCEPTED]);
    }

    private function free(int $trip): array
    {
        return \STS\Mobility\Segments\SegmentInventory::free(Trip::findOrFail($trip), \STS\Mobility\Segments\RouteTerms::forTrip($trip));
    }

    private function publish(User $host, Car $car, int $minutesLater = 0): int
    {
        $quote = $this->actingAsApiUser($host)->postJson('/api/mobility/routes/quote', ['stop_ids' => [101, 102, 103], 'vehicle_type' => 'car'])->assertCreated()
            ->assertJsonPath('data.route_schema', 'ordered-legs-v1')->assertJsonPath('data.points.0.json_address.id', 501)->json('data.id');
        $response = $this->actingAsApiUser($host)->postJson('/api/mobility/pool-offers', [
            'quote_id' => $quote, 'car_id' => $car->id, 'trip_date' => now('Asia/Kolkata')->addDays(2)->addMinutes($minutesLater)->format('Y-m-d\TH:i'), 'total_seats' => 1,
        ], ['Idempotency-Key' => 'publish-'.uniqid()])->assertCreated();
        $trip = (int) $response->json('data.id');
        $this->assertSame(3, DB::table('trips_points')->where('trip_id', $trip)->count());
        $this->assertSame(2, DB::table('trip_routes')->where('trip_id', $trip)->count());

        return $trip;
    }

    private function host(): array
    {
        $host = $this->member('host');
        $car = Car::factory()->withCatalog()->create(['user_id' => $host->id]);
        DB::table('mobility_host_applications')->insert(['user_id' => $host->id, 'car_id' => $car->id, 'vehicle_type' => 'car', 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$host, $car];
    }

    private function member(string $label): User
    {
        $user = User::factory()->create(['active' => true, 'banned' => false, 'identity_validated' => true, 'nro_doc' => 'SYNTHETIC-ID', 'mobile_phone' => '0000000000', 'autoaccept_requests' => false]);
        DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    private function bindProvider(): void
    {
        $this->app->bind(RouteProvider::class, fn (): RouteProvider => new class implements RouteProvider
        {
            public function route(array $pickup, array $dropoff, string $vehicleType): array
            {
                return ['distance_meters' => 1501, 'duration_seconds' => 120, 'provider' => 'segment-fake', 'profile' => 'car'];
            }
        });
        foreach ([[501, 'Stop A'], [502, 'Stop B'], [503, 'Stop C']] as [$node, $name]) {
            DB::table('nodes_geo')->insert(['id' => $node, 'name' => $name, 'lat' => 13.0 + ($node - 500) / 100, 'lng' => 77.5 + ($node - 500) / 100, 'type' => 'synthetic']);
        }
        foreach ([[101, 501, 'Stop A'], [102, 502, 'Stop B'], [103, 503, 'Stop C']] as [$point, $node, $name]) {
            DB::table('mobility_pickup_points')->insert(['id' => $point, 'college_code' => 'CAMPUS-BLR', 'canonical_node_id' => $node, 'version' => 1, 'name' => $name, 'lat' => 13.0 + ($node - 500) / 100, 'lng' => 77.5 + ($node - 500) / 100, 'vehicle_modes' => json_encode(['car']), 'active' => true, 'verified_at' => now(), 'synthetic_only' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
