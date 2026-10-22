<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Tracking\TrackingService;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\TripLiveShare;
use STS\Models\User;
use Tests\TestCase;

final class MobilityTrackingHttpTest extends TestCase
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

    public function test_first_session_creates_private_share_and_returns_no_legacy_token(): void
    {
        [$driver, $trip] = $this->fixture();
        $response = $this->actingAsApiUser($driver)->postJson($this->path($trip, 'session'), ['consent' => true], ['Idempotency-Key' => 'session-first'])->assertOk();
        $this->assertNotEmpty($response->json('data.session_id'));
        $this->assertArrayNotHasKey('share_token', $response->json('data'));
        $share = TripLiveShare::query()->where('trip_id', $trip->id)->firstOrFail();
        $this->assertNotEmpty($share->share_token);
        $this->assertTrue((bool) $share->is_active);
        $this->assertNull($share->lat);
        $this->assertNotNull($share->started_at);
    }

    public function test_reconnect_fences_old_session_and_clears_previous_coordinate(): void
    {
        [$driver, $trip] = $this->fixture();
        $first = $this->start($driver, $trip, 'session-a');
        $this->location($driver, $trip, $first, 1, now('UTC')->subSecond(), 'location-a');
        $second = $this->start($driver, $trip, 'session-b');
        $share = TripLiveShare::query()->where('trip_id', $trip->id)->firstOrFail();
        $this->assertNotSame($first, $second);
        $this->assertNull($share->lat);
        $this->assertNull($share->lng);
        $this->assertNull($share->recorded_at);
        $this->assertSame(0, (int) $share->last_sequence);
        $this->actingAsApiUser($driver)->postJson($this->path($trip, 'locations'), ['session_id' => $first, 'sequence' => 2, 'lat' => 12.9, 'lng' => 77.5, 'accuracy_meters' => 5, 'recorded_at' => now('UTC')->format('Y-m-d\TH:i:s.v\Z')], ['Idempotency-Key' => 'old-session'])->assertStatus(409)->assertJsonPath('error.code', 'TRACKING_SESSION_STALE');
        $this->actingAsApiUser($driver)->postJson($this->path($trip, 'session'), ['consent' => true], ['Idempotency-Key' => 'session-a'])->assertOk()->assertJsonPath('data.active', false);
    }

    public function test_late_stop_from_old_session_does_not_clear_new_session_coordinates(): void
    {
        [$driver, $trip] = $this->fixture();
        $old = $this->start($driver, $trip, 'stop-race-old');
        $this->location($driver, $trip, $old, 1, now('UTC')->subSeconds(2), 'stop-race-old-location');
        $current = $this->start($driver, $trip, 'stop-race-current');
        $this->location($driver, $trip, $current, 1, now('UTC')->subSecond(), 'stop-race-current-location');

        $this->actingAsApiUser($driver)
            ->postJson($this->path($trip, 'stop'), ['session_id' => $old], ['Idempotency-Key' => 'stop-race-old-stop'])
            ->assertOk()
            ->assertJsonPath('data.stopped', false)
            ->assertJsonPath('data.noop', true)
            ->assertJsonPath('data.detail', 'STALE_SESSION');
        $this->actingAsApiUser($driver)->getJson($this->path($trip))->assertOk()
            ->assertJsonPath('data.location.sequence', 1)
            ->assertJsonPath('data.stale', false);
    }

    public function test_stop_key_is_bound_to_optional_session_id(): void
    {
        [$driver, $trip] = $this->fixture();
        $first = $this->start($driver, $trip, 'stop-key-first-session');
        $second = $this->start($driver, $trip, 'stop-key-second-session');
        $this->actingAsApiUser($driver)->postJson($this->path($trip, 'stop'), ['session_id' => $first], ['Idempotency-Key' => 'same-stop-key'])->assertOk();
        $this->actingAsApiUser($driver)->postJson($this->path($trip, 'stop'), ['session_id' => $second], ['Idempotency-Key' => 'same-stop-key'])
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
    }

    public function test_exact_session_stop_replay_is_idempotent(): void
    {
        [$driver, $trip] = $this->fixture();
        $session = $this->start($driver, $trip, 'stop-replay-session');
        $first = $this->actingAsApiUser($driver)->postJson($this->path($trip, 'stop'), ['session_id' => $session], ['Idempotency-Key' => 'stop-replay-key'])->assertOk();
        $this->actingAsApiUser($driver)->postJson($this->path($trip, 'stop'), ['session_id' => $session], ['Idempotency-Key' => 'stop-replay-key'])
            ->assertOk()->assertExactJson($first->json());
    }

    public function test_expired_session_hides_coordinates_rejects_ingest_and_requires_new_consent(): void
    {
        [$driver, $trip] = $this->fixture();
        $session = $this->start($driver, $trip, 'lease-session');
        $this->location($driver, $trip, $session, 1, now('UTC')->subSecond(), 'lease-location');
        $expired = now('UTC')->subSeconds(TrackingService::SESSION_LEASE_SECONDS + 1);
        DB::table('trip_live_shares')->where('trip_id', $trip->id)->update([
            'updated_at' => $expired,
            'sample_recorded_at' => $expired,
        ]);

        $this->actingAsApiUser($driver)->getJson($this->path($trip))->assertOk()
            ->assertJsonPath('data.location', null)
            ->assertJsonPath('data.stale', true);
        $this->actingAsApiUser($driver)->postJson($this->path($trip, 'locations'), [
            'session_id' => $session, 'sequence' => 2, 'lat' => 12.9, 'lng' => 77.5,
            'accuracy_meters' => 5, 'recorded_at' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
        ], ['Idempotency-Key' => 'lease-expired-location'])
            ->assertStatus(409)->assertJsonPath('error.code', 'TRACKING_SESSION_STALE');

        $fresh = $this->start($driver, $trip, 'lease-restart');
        $this->assertNotSame($session, $fresh);
        $this->location($driver, $trip, $fresh, 1, now('UTC')->subSecond(), 'lease-fresh-location');
    }

    public function test_replaying_expired_session_start_is_not_reported_active(): void
    {
        [$driver, $trip] = $this->fixture();
        $this->start($driver, $trip, 'lease-replay');
        $expired = now('UTC')->subSeconds(TrackingService::SESSION_LEASE_SECONDS + 1);
        DB::table('trip_live_shares')->where('trip_id', $trip->id)->update([
            'started_at' => $expired,
            'updated_at' => $expired,
        ]);
        $this->actingAsApiUser($driver)->postJson($this->path($trip, 'session'), ['consent' => true], ['Idempotency-Key' => 'lease-replay'])
            ->assertOk()->assertJsonPath('data.active', false);
    }

    public function test_timestamp_must_increase_even_when_sequence_increases(): void
    {
        [$driver, $trip] = $this->fixture();
        $session = $this->start($driver, $trip, 'session-time');
        $recorded = now('UTC')->subSeconds(5);
        $this->location($driver, $trip, $session, 1, $recorded, 'location-time-a');
        $this->locationResponse($driver, $trip, $session, 2, $recorded, 'location-time-b')->assertStatus(409)->assertJsonPath('error.code', 'TIMESTAMP_OUT_OF_ORDER');
    }

    public function test_wrong_actor_college_and_outsider_are_forbidden_before_terminal_state(): void
    {
        [$driver, $trip] = $this->fixture();
        $wrongCollege = $this->member('OTHER-COLLEGE');
        $outsider = $this->member();
        $trip->update(['state' => Trip::STATE_CANCELED]);
        $this->actingAsApiUser($wrongCollege)->postJson($this->path($trip, 'session'), ['consent' => true], ['Idempotency-Key' => 'wrong-college'])->assertStatus(403);
        $this->actingAsApiUser($outsider)->getJson($this->path($trip))->assertStatus(403)->assertJsonPath('error.code', 'TRACKING_FORBIDDEN');
        $this->actingAsApiUser($driver)->getJson($this->path($trip))->assertStatus(410)->assertJsonPath('error.code', 'TRACKING_STOPPED');
    }

    public function test_explicit_stop_clears_precise_data_and_is_idempotent_after_terminal_state(): void
    {
        [$driver, $trip] = $this->fixture();
        $session = $this->start($driver, $trip, 'session-stop');
        $this->location($driver, $trip, $session, 1, now('UTC')->subSecond(), 'location-stop');
        $trip->update(['state' => Trip::STATE_CANCELED]);
        DB::table('mobility_memberships')->where('user_id', $driver->id)->update(['status' => 'suspended']);
        $first = $this->actingAsApiUser($driver)->postJson($this->path($trip, 'stop'), [], ['Idempotency-Key' => 'stop-terminal'])->assertOk();
        $this->actingAsApiUser($driver)->postJson($this->path($trip, 'stop'), [], ['Idempotency-Key' => 'stop-terminal'])->assertOk()->assertExactJson($first->json());
        $share = TripLiveShare::query()->where('trip_id', $trip->id)->firstOrFail();
        $this->assertFalse((bool) $share->is_active);
        $this->assertNull($share->lat);
        $this->assertNull($share->lng);
        $this->assertNull($share->recorded_at);
        $this->assertNull($share->driver_session_id);
    }

    public function test_distinct_millisecond_samples_are_ordered_without_losing_precision(): void
    {
        [$driver, $trip] = $this->fixture(); $session = $this->start($driver, $trip, 'millis-session');
        $first = now('UTC')->subSeconds(3)->setMicrosecond(100000);
        $second = $first->copy()->setMicrosecond(200000);
        $this->location($driver, $trip, $session, 1, $first, 'millis-first');
        $this->location($driver, $trip, $session, 2, $second, 'millis-second');
        $this->locationResponse($driver, $trip, $session, 3, $first, 'millis-older')->assertStatus(409)->assertJsonPath('error.code', 'TIMESTAMP_OUT_OF_ORDER');
        $response = $this->actingAsApiUser($driver)->getJson($this->path($trip))->assertOk();
        $this->assertSame(2, $response->json('data.location.sequence'));
        $this->assertStringContainsString('.200', $response->json('data.location.recorded_at'));
    }

    public function test_only_current_accepted_rider_reads_fresh_location_and_stale_is_suppressed(): void
    {
        [$driver, $trip] = $this->fixture(); $rider = $this->member();
        $booking = Passenger::create(['trip_id' => $trip->id, 'user_id' => $rider->id, 'request_state' => Passenger::STATE_PENDING, 'passenger_type' => Passenger::TYPE_PASAJERO]);
        $session = $this->start($driver, $trip, 'reader-session');
        $this->location($driver, $trip, $session, 1, now('UTC')->subSeconds(3), 'reader-fix');
        $this->actingAsApiUser($rider)->getJson($this->path($trip))->assertForbidden();
        $booking->request_state = Passenger::STATE_ACCEPTED; $booking->save();
        $this->actingAsApiUser($rider)->getJson($this->path($trip))->assertOk()->assertJsonPath('data.stale', false);
        $booking->request_state = Passenger::STATE_CANCELED; $booking->save();
        $this->actingAsApiUser($rider)->getJson($this->path($trip))->assertForbidden();
        DB::table('trip_live_shares')->where('trip_id', $trip->id)->update(['sample_recorded_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.v')]);
        $this->actingAsApiUser($driver)->getJson($this->path($trip))->assertOk()->assertJsonPath('data.location', null)->assertJsonPath('data.stale', true);
    }

    public function test_expired_planned_trip_cannot_start_tracking_and_public_links_are_disabled(): void
    {
        [$driver, $trip] = $this->fixture(); $trip->update(['trip_date' => now()->subDays(2)]);
        $this->actingAsApiUser($driver)->postJson($this->path($trip, 'session'), ['consent' => true], ['Idempotency-Key' => 'expired-session'])->assertStatus(409);
        $trip->update(['trip_date' => now()->addHour()]); $this->start($driver, $trip, 'private-only');
        $token = DB::table('trip_live_shares')->where('trip_id', $trip->id)->value('share_token');
        $this->getJson('/api/live/'.$token)->assertNotFound();
    }

    private function fixture(): array
    {
        $driver = $this->member();
        return [$driver, Trip::factory()->create(['user_id' => $driver->id])];
    }

    private function member(string $college = 'CAMPUS-BLR'): User
    {
        $user = User::factory()->create(['active' => true, 'banned' => false]);
        DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => $college, 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return $user;
    }

    private function path(Trip $trip, string $suffix = ''): string
    { return '/api/mobility/trips/'.$trip->id.'/tracking'.($suffix === '' ? '' : '/'.$suffix); }

    private function start(User $driver, Trip $trip, string $key): string
    { return $this->actingAsApiUser($driver)->postJson($this->path($trip, 'session'), ['consent' => true], ['Idempotency-Key' => $key])->assertOk()->json('data.session_id'); }

    private function location(User $driver, Trip $trip, string $session, int $sequence, $recordedAt, string $key): void
    { $this->locationResponse($driver, $trip, $session, $sequence, $recordedAt, $key)->assertOk(); }

    private function locationResponse(User $driver, Trip $trip, string $session, int $sequence, $recordedAt, string $key)
    { return $this->actingAsApiUser($driver)->postJson($this->path($trip, 'locations'), ['session_id' => $session, 'sequence' => $sequence, 'lat' => 12.9716, 'lng' => 77.5946, 'accuracy_meters' => 5, 'recorded_at' => $recordedAt->format('Y-m-d\TH:i:s.v\Z')], ['Idempotency-Key' => $key]); }
}
