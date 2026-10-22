<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Payments\PaymentException;
use STS\Mobility\Payments\PaymentService;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

class MobilityCashHttpTest extends TestCase
{
    public function test_cash_both_parties_and_dispute_are_durable_without_creating_provider_money(): void
    {
        [$trip, $booking, $rider] = $this->fixture();
        $this->cash($trip, $booking, $rider, 'rider_paid', 'early')->assertStatus(422)->assertJsonPath('error.code', 'CASH_NOT_READY');
        $this->startAndBoard($trip, $rider);
        $first = $this->cash($trip, $booking, $rider, 'rider_paid', 'rider-1', ['gross_amount' => 1, 'currency' => 'USD'])->assertOk()
            ->assertJsonPath('status', 'pending')->assertJsonPath('gross_amount', 10000)->assertJsonPath('currency', 'INR');
        $this->cash($trip, $booking, $trip->user, 'driver_received', 'driver-1')->assertOk()->assertJsonPath('status', 'confirmed');
        $this->cash($trip, $booking, $rider, 'rider_paid', 'rider-1')->assertOk()->assertExactJson($first->json());
        $this->cash($trip, $booking, $rider, 'rider_paid', 'rider-2')->assertStatus(409)->assertJsonPath('error.code', 'CASH_ACTION_ALREADY_RECORDED');
        $this->cash($trip, $booking, $rider, 'dispute', 'rider-1')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $dispute = $this->cash($trip, $booking, $rider, 'dispute', 'dispute-1')->assertOk()->assertJsonPath('status', 'disputed');
        $this->assertNotNull($dispute->json('disputed_at'));
        $this->cash($trip, $booking, $rider, 'dispute', 'dispute-1')->assertOk()->assertExactJson($dispute->json());
        $this->cash($trip, $booking, $trip->user, 'dispute', 'dispute-2')->assertStatus(409);
        $this->assertDatabaseCount('mobility_cash_receipts', 1);
        $this->assertDatabaseCount('mobility_cash_receipt_events', 3);
        foreach (['mobility_payment_attempts', 'mobility_financial_postings', 'mobility_payout_allocations', 'mobility_payout_outbox'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->actingAsApiUser($rider)->getJson('/api/mobility/trips/'.$trip->id.'/payments')->assertOk()
            ->assertJsonPath('bookings.0.collection_state', 'cash_disputed')->assertJsonPath('bookings.0.driver_payout_status', 'not_applicable_cash')
            ->assertJsonPath('bookings.0.settlement_status', 'not_applicable_cash');
    }

    public function test_cash_cannot_be_spoofed_by_driver_outsider_or_unrelated_admin(): void
    {
        [$trip, $booking, $rider] = $this->fixture();
        $this->startAndBoard($trip, $rider);
        $this->cash($trip, $booking, $trip->user, 'rider_paid', 'spoof', ['user_id' => $rider->id])->assertForbidden();
        $this->cash($trip, $booking, $rider, 'driver_received', 'spoof')->assertForbidden();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        foreach ([$admin, User::factory()->create()] as $actor) {
            $this->cash($trip, $booking, $actor, 'rider_paid', 'outsider')->assertForbidden();
        }
        $otherTrip = Trip::factory()->create(['user_id' => $trip->user_id]);
        $this->cash($otherTrip, $booking, $rider, 'rider_paid', 'cross-trip')->assertStatus(422)->assertJsonPath('error.code', 'BOOKING_MISMATCH');
        $this->actingAsApiUser($rider)->postJson($this->path($trip, $booking), ['action' => ['rider_paid']], ['Idempotency-Key' => 'array'])->assertStatus(422);
        $this->actingAsApiUser($rider)->postJson($this->path($trip, $booking), ['action' => 'rider_paid'])->assertStatus(422);
        $this->assertDatabaseCount('mobility_cash_receipts', 0);
    }

    public function test_provider_attempts_block_cash_and_cash_blocks_checkout_and_payout(): void
    {
        [$trip, $booking, $rider] = $this->fixture();
        $service = app(PaymentService::class);
        $service->createPaymentAttempt($trip, $booking, 'fixture', 'test', 'order-cash-conflict');
        $this->startAndBoard($trip, $rider);
        $this->cash($trip, $booking, $rider, 'rider_paid', 'pending-conflict')->assertStatus(409)->assertJsonPath('error.code', 'CASH_PROVIDER_CONFLICT');
        $service->ingestCapturedPayment(['provider' => 'fixture', 'environment' => 'test', 'event_id' => 'capture-conflict', 'order_reference' => 'order-cash-conflict', 'payment_reference' => 'pay-conflict', 'status' => 'captured', 'amount' => 10000, 'currency' => 'INR']);
        $this->cash($trip, $booking, $rider, 'rider_paid', 'captured-conflict')->assertStatus(409);
        $this->assertDatabaseCount('mobility_cash_receipts', 0);

        [$trip, $booking, $rider] = $this->fixture();
        $this->startAndBoard($trip, $rider);
        $this->cash($trip, $booking, $rider, 'rider_paid', 'cash-selected')->assertOk();
        foreach ([fn () => $service->createPaymentAttempt($trip, $booking, 'fixture', 'test', 'order-reverse'), fn () => $service->enqueueRelease($trip, $booking)] as $call) {
            try {
                $call();
                $this->fail('Cash entered a provider path');
            } catch (PaymentException $e) {
                $this->assertSame('PAYMENT_METHOD_CONFLICT', $e->codeName);
            }
        }
        $this->assertSame(0, DB::table('mobility_payment_attempts')->where('booking_id', $booking->id)->count());
    }

    public function test_a_driver_booking_cannot_fabricate_both_sides_of_cash_receipt(): void
    {
        [$trip, $booking] = $this->fixture();
        $booking->update(['user_id' => $trip->user_id]);
        $this->cash($trip, $booking, $trip->user, 'rider_paid', 'self')->assertStatus(422)->assertJsonPath('error.code', 'CASH_DISTINCT_PARTIES_REQUIRED');
        $this->assertDatabaseCount('mobility_cash_receipts', 0);
    }

    private function fixture(): array
    {
        $trip = Trip::factory()->create(['user_id' => User::factory()->create()->id]);
        $rider = User::factory()->create();
        $booking = Passenger::factory()->aceptado()->create(['trip_id' => $trip->id, 'user_id' => $rider->id]);
        app(PaymentService::class)->snapshotFare($trip, $booking, 10000, 1000);

        return [$trip, $booking, $rider];
    }

    private function startAndBoard(Trip $trip, User $rider): void
    {
        foreach (['start', 'board'] as $action) {
            $this->actingAsApiUser($trip->user)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => $action, 'passenger_id' => $rider->id], ['Idempotency-Key' => $action])->assertOk();
        }
    }

    private function path(Trip $trip, Passenger $booking): string
    {
        return '/api/mobility/trips/'.$trip->id.'/bookings/'.$booking->id.'/cash-receipts';
    }

    private function cash(Trip $trip, Passenger $booking, User $actor, string $action, string $key, array $extra = [])
    {
        return $this->actingAsApiUser($actor)->postJson($this->path($trip, $booking), array_replace($extra, ['action' => $action]), ['Idempotency-Key' => $key]);
    }
}
