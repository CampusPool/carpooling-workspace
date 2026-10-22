<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Pilot\PilotDemoRoutes;
use STS\Models\Car;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

final class MobilityPilotOfferHttpTest extends TestCase
{
    private function payload(): array
    {
        return [
            'route_id' => 'demo-a-b',
            'trip_date' => now('Asia/Kolkata')->addDays(3)->format('Y-m-d\\TH:i'),
            'total_seats' => 1,
            'description' => 'Synthetic test only',
        ];
    }

    public function test_demo_routes_and_publish_are_gated_by_flag_and_testing_environment(): void
    {
        $user = User::factory()->create();
        $previous = getenv('MOBILITY_PILOT_UI_ENABLED');
        try {
            putenv('MOBILITY_PILOT_UI_ENABLED=false');
            $this->actingAsApiUser($user)->getJson('/api/mobility/pilot/routes')->assertNotFound();
            $this->actingAsApiUser($user)->postJson('/api/mobility/pilot/offers', $this->payload())->assertNotFound();
        } finally {
            if ($previous === false) {
                putenv('MOBILITY_PILOT_UI_ENABLED');
            } else {
                putenv('MOBILITY_PILOT_UI_ENABLED='.$previous);
            }
        }
    }

    public function test_only_whitelisted_valid_synthetic_dates_are_accepted(): void
    {
        if (! filter_var(getenv('MOBILITY_PILOT_UI_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Only enabled inside the disposable development pilot.');
        }
        $host = User::factory()->create();
        $path = '/api/mobility/pilot/offers';
        $this->actingAsApiUser($host)->getJson('/api/mobility/pilot/routes')
            ->assertOk()->assertJsonPath('synthetic_only', true)->assertJsonCount(2, 'data');
        $payload = $this->payload();
        $payload['route_id'] = 'forged';
        $this->actingAsApiUser($host)->postJson($path, $payload)->assertUnprocessable();
        $payload = $this->payload();
        $payload['trip_date'] = now('Asia/Kolkata')->subDay()->format('Y-m-d\\TH:i');
        $this->actingAsApiUser($host)->postJson($path, $payload)->assertUnprocessable();
        $payload = $this->payload();
        $payload['total_seats'] = 6;
        $this->actingAsApiUser($host)->postJson($path, $payload)->assertUnprocessable();
        $this->assertSame(0, DB::table('trips')->count());
    }

    public function test_seed_is_required_and_unqualified_host_cannot_publish(): void
    {
        if (! filter_var(getenv('MOBILITY_PILOT_UI_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Only enabled inside the disposable development pilot.');
        }
        $host = User::factory()->create([
            'nro_doc' => 'DEMO100', 'mobile_phone' => '0000000000', 'identity_validated' => true,
        ]);
        Car::factory()->withCatalog()->create(['user_id' => $host->id]);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pilot/offers', $this->payload())
            ->assertStatus(503)->assertJsonPath('error.code', 'PILOT_ROUTE_NOT_SEEDED');
        $this->assertSame(0, DB::table('trips')->count());

        PilotDemoRoutes::seed();
        $withoutCar = User::factory()->create(['nro_doc' => 'DEMO101', 'mobile_phone' => '0000000001']);
        $this->actingAsApiUser($withoutCar)->postJson('/api/mobility/pilot/offers', $this->payload())
            ->assertUnprocessable()->assertJsonPath('error.code', 'PILOT_TRIP_NOT_CREATED');
        $this->assertSame(0, DB::table('trips')->count());
    }

    public function test_host_can_publish_new_fixture_trip_and_rider_can_request_then_host_accept(): void
    {
        if (! filter_var(getenv('MOBILITY_PILOT_UI_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Only enabled inside the disposable development pilot.');
        }
        $host = User::factory()->create([
            'nro_doc' => 'SYN123', 'mobile_phone' => '0000000000', 'identity_validated' => true,
        ]);
        $rider = User::factory()->create(['identity_validated' => true]);
        Car::factory()->withCatalog()->create(['user_id' => $host->id]);
        PilotDemoRoutes::seed();
        $data = $this->payload();
        $data['distance'] = 999999;
        $data['from_town'] = 'Untrusted injected town';

        $response = $this->actingAsApiUser($host)->postJson('/api/mobility/pilot/offers', $data)
            ->assertCreated()->assertJsonPath('synthetic_only', true)->assertJsonPath('data.existing', false);
        $id = (int) $response->json('data.id');
        $trip = Trip::findOrFail($id);
        $this->assertSame($host->id, $trip->user_id);
        $this->assertSame('Synthetic Stop A', $trip->from_town);
        $this->assertSame('Synthetic Stop B', $trip->to_town);
        $this->assertSame(3.5, (float) $trip->distance);
        $this->assertSame(1, (int) $trip->total_seats);

        $this->actingAsApiUser($rider)->getJson('/api/trips?page_size=100&is_passenger=false&history=false')
            ->assertOk()->assertSeeText('Synthetic Stop A');
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$id.'/requests', [])->assertOk();
        $this->actingAsApiUser($host)->postJson('/api/trips/'.$id.'/requests/'.$rider->id.'/accept', [])->assertOk();

        $this->assertSame(Passenger::STATE_ACCEPTED, (int) Passenger::query()
            ->where('trip_id', $id)->where('user_id', $rider->id)->firstOrFail()->request_state);
        $this->assertSame(0, (int) $trip->fresh()->seats_available);
        $this->actingAsApiUser($host)->postJson('/api/mobility/pilot/offers', $this->payload())
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.existing', true);
        $this->assertSame(1, DB::table('trips')->where('user_id', $host->id)->count());

        // The rider can reverse an accepted booking; last-seat capacity returns.
        $this->actingAsApiUser($rider)->postJson('/api/trips/'.$id.'/requests/'.$rider->id.'/cancel', [])
            ->assertOk();
        $this->assertSame(Passenger::STATE_CANCELED, (int) Passenger::query()
            ->where('trip_id', $id)->where('user_id', $rider->id)->firstOrFail()->request_state);
        $this->assertSame(1, (int) $trip->fresh()->seats_available);
    }
}
