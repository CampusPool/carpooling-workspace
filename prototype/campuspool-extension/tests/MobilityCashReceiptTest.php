<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Mobility\CashReceiptException;
use STS\Mobility\CashReceiptService;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

class MobilityCashReceiptTest extends TestCase
{
    public function test_rider_and_driver_acknowledgements_confirm_one_receipt_without_postings(): void
    {
        [$trip, $booking, $rider] = $this->readyBooking();
        $service = app(CashReceiptService::class);
        $service->record($trip, $booking, $rider, 'rider_paid', 'cash-rider-1');
        $body = $service->record($trip, $booking, $trip->user, 'driver_received', 'cash-driver-1');
        $this->assertSame('confirmed', $body['status']);
        $this->assertSame(1, DB::table('mobility_cash_receipts')->where('booking_id', $booking->id)->count());
        $this->assertSame(0, DB::table('mobility_financial_postings')->where('booking_id', $booking->id)->count());
    }

    public function test_spoof_and_cross_trip_are_rejected(): void
    {
        [$trip, $booking, $rider] = $this->readyBooking();
        try {
            app(CashReceiptService::class)->record($trip, $booking, User::factory()->create(), 'rider_paid', 'cash-spoof');
            $this->fail('Unrelated users must be rejected.');
        } catch (CashReceiptException $e) {
            $this->assertSame('CASH_ACTOR_FORBIDDEN', $e->errorCode);
        }
        $otherTrip = Trip::factory()->create(['user_id' => $trip->user_id]);
        try {
            app(CashReceiptService::class)->record($otherTrip, $booking, $rider, 'rider_paid', 'cash-cross');
            $this->fail('Cross-trip bookings must be rejected.');
        } catch (CashReceiptException $e) {
            $this->assertSame('BOOKING_MISMATCH', $e->errorCode);
        }
    }

    public function test_provider_attempt_and_client_amount_cannot_be_used_for_cash(): void
    {
        [$trip, $booking, $rider] = $this->readyBooking();
        DB::table('mobility_payment_attempts')->insert(['trip_id' => $trip->id, 'booking_id' => $booking->id, 'provider' => 'test', 'environment' => 'test', 'order_reference' => 'order-cash', 'amount' => 10000, 'currency' => 'INR', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        try {
            app(CashReceiptService::class)->record($trip, $booking, $rider, 'rider_paid', 'cash-provider');
            $this->fail('Provider attempts must block cash.');
        } catch (CashReceiptException $e) {
            $this->assertSame('CASH_PROVIDER_CONFLICT', $e->errorCode);
        }
    }

    public function test_idempotency_replays_and_dispute_is_terminal(): void
    {
        [$trip, $booking, $rider] = $this->readyBooking();
        $service = app(CashReceiptService::class);
        $first = $service->record($trip, $booking, $rider, 'rider_paid', 'cash-replay');
        $this->assertReceiptSame($first, $service->record($trip, $booking, $rider, 'rider_paid', 'cash-replay'));
        try {
            $service->record($trip, $booking, $rider, 'dispute', 'cash-replay');
            $this->fail('A key reused for another action must conflict.');
        } catch (CashReceiptException $e) {
            $this->assertSame('IDEMPOTENCY_CONFLICT', $e->errorCode);
        }
        $disputed = $service->record($trip, $booking, $rider, 'dispute', 'cash-dispute');
        $this->assertSame('disputed', $disputed['status']);
        $this->assertReceiptSame($disputed, $service->record($trip, $booking, $rider, 'dispute', 'cash-dispute'));
        try {
            $service->record($trip, $booking, $rider, 'dispute', 'cash-dispute-2');
            $this->fail('A second dispute action must not append an event.');
        } catch (CashReceiptException $e) {
            $this->assertSame('CASH_ACTION_ALREADY_RECORDED', $e->errorCode);
        }
    }

    private function assertReceiptSame(array $expected, array $actual): void
    {
        // MySQL JSON objects reorder keys; values and their types must replay.
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    private function readyBooking(): array
    {
        $owner = User::factory()->create();
        $rider = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $owner->id]);
        $booking = Passenger::factory()->create(['trip_id' => $trip->id, 'user_id' => $rider->id, 'passenger_type' => Passenger::TYPE_PASAJERO, 'request_state' => Passenger::STATE_ACCEPTED]);
        app(\STS\Mobility\Payments\PaymentService::class)->snapshotFare($trip, $booking, 10000, 1000);
        DB::table('mobility_trip_lifecycles')->insert(['trip_id' => $trip->id, 'state' => 'in_progress', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mobility_trip_participants')->insert(['trip_id' => $trip->id, 'booking_id' => $booking->id, 'user_id' => $rider->id, 'state' => 'boarded', 'created_at' => now(), 'updated_at' => now()]);

        return [$trip, $booking, $rider];
    }
}
