<?php

namespace Tests\Feature\Mobility;

use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

final class MobilityLegacyPaymentGuardTest extends TestCase
{
    public function test_waiting_payment_cannot_be_promoted_through_legacy_http_without_trusted_capture(): void
    {
        $owner = User::factory()->create();
        $rider = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $owner->id, 'total_seats' => 1]);
        $booking = Passenger::factory()->create([
            'trip_id' => $trip->id,
            'user_id' => $rider->id,
            'passenger_type' => Passenger::TYPE_PASAJERO,
            'request_state' => Passenger::STATE_WAITING_PAYMENT,
        ]);
        $path = '/api/trips/'.$trip->id.'/requests/'.$rider->id.'/pay';

        $this->actingAsApiUser($rider)->postJson($path)
            ->assertStatus(409)->assertJsonPath('error.code', 'LEGACY_PAYMENT_PROMOTION_DISABLED');
        $this->assertSame(Passenger::STATE_WAITING_PAYMENT, $booking->fresh()->request_state);

        $outsider = User::factory()->create();
        $this->actingAsApiUser($outsider)->postJson($path)
            ->assertStatus(409)->assertJsonPath('error.code', 'LEGACY_PAYMENT_PROMOTION_DISABLED');
        $this->assertSame(Passenger::STATE_WAITING_PAYMENT, $booking->fresh()->request_state);
    }
}
