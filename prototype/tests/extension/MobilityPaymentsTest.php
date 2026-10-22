<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Payments\PaymentException;
use STS\Mobility\Payments\PaymentService;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

class MobilityPaymentsTest extends TestCase
{
    public function test_capture_requires_server_bound_order_and_is_idempotent(): void
    {
        [$trip, $booking] = $this->booking();
        $service = app(PaymentService::class);
        $service->snapshotFare($trip, $booking, 10000, 1000);
        $service->createPaymentAttempt($trip, $booking, 'test-provider', 'test', 'order-1');

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('does not match');
        $service->ingestCapturedPayment(['provider' => 'test-provider', 'environment' => 'test', 'event_id' => 'evt-1', 'payment_reference' => 'pay-1', 'order_reference' => 'order-1', 'status' => 'captured', 'amount' => 9999, 'currency' => 'INR']);
    }

    public function test_duplicate_capture_event_does_not_duplicate_postings_and_conflicting_event_is_rejected(): void
    {
        [$trip, $booking] = $this->booking();
        $service = app(PaymentService::class);
        $service->snapshotFare($trip, $booking, 10000, 1000);
        $service->createPaymentAttempt($trip, $booking, 'test-provider', 'test', 'order-2');
        $capture = ['provider' => 'test-provider', 'environment' => 'test', 'event_id' => 'evt-2', 'payment_reference' => 'pay-2', 'order_reference' => 'order-2', 'status' => 'captured', 'amount' => 10000, 'currency' => 'INR'];
        $service->ingestCapturedPayment($capture);
        $capture['event_id'] = 'evt-2b';
        $service->ingestCapturedPayment($capture);
        $capture['event_id'] = 'evt-2';
        $service->ingestCapturedPayment($capture);
        $this->assertSame(2, DB::table('mobility_financial_postings')->where('entry_group', 'capture:'.DB::table('mobility_payment_attempts')->value('id'))->count());
        $capture['payment_reference'] = 'different';
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('rebound');
        $service->ingestCapturedPayment($capture);
    }

    public function test_fractional_and_boolean_amounts_are_rejected(): void
    {
        [$trip, $booking] = $this->booking();
        $service = app(PaymentService::class);
        $service->snapshotFare($trip, $booking, 10000);
        $service->createPaymentAttempt($trip, $booking, 'test-provider', 'test', 'order-types');
        foreach ([10000.9, true, '10000'] as $amount) {
            try {
                $service->ingestCapturedPayment(['provider' => 'test-provider', 'environment' => 'test', 'event_id' => 'evt-'.md5(serialize($amount)), 'payment_reference' => 'pay-'.md5(serialize($amount)), 'order_reference' => 'order-types', 'status' => 'captured', 'amount' => $amount, 'currency' => 'INR']);
                $this->fail('Expected a strict amount rejection.');
            } catch (PaymentException $exception) {
                $this->assertSame('CAPTURE_UNVERIFIED', $exception->codeName);
            }
        }
    }

    public function test_release_requires_rider_dropoff_and_verified_beneficiary(): void
    {
        [$trip, $booking] = $this->paidCompletedBooking();
        $service = app(PaymentService::class);
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('drop-off');
        $service->registerBeneficiary($trip->user_id, 'test-provider', 'test', 'beneficiary-1', true, true);
        $service->enqueueRelease($trip, $booking);
    }

    public function test_release_creates_one_outbox_entry_and_cash_is_rejected(): void
    {
        [$trip, $booking] = $this->paidCompletedBooking();
        $service = app(PaymentService::class);
        DB::table('mobility_dropoff_confirmations')->insert(['trip_id' => $trip->id, 'booking_id' => $booking->id, 'user_id' => $booking->user_id, 'idempotency_key' => 'drop-1', 'confirmed_after_event_id' => DB::table('mobility_trip_events')->where('trip_id', $trip->id)->value('id'), 'confirmed_at' => now(), 'response_body' => '{}']);
        $service->registerBeneficiary($trip->user_id, 'test-provider', 'test', 'beneficiary-2', true, true);
        $first = $service->enqueueRelease($trip, $booking);
        $second = $service->enqueueRelease($trip, $booking);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('mobility_payout_outbox')->where('allocation_id', $first->allocation_id)->count());
        $this->expectException(PaymentException::class);
        $service->createPaymentAttempt($trip, $booking, 'cash', 'test', 'cash-1');
    }

    private function booking(): array
    {
        $owner = User::factory()->create();
        $rider = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $owner->id]);
        $booking = Passenger::factory()->create(['trip_id' => $trip->id, 'user_id' => $rider->id, 'passenger_type' => Passenger::TYPE_PASAJERO, 'request_state' => Passenger::STATE_ACCEPTED]);

        return [$trip, $booking];
    }

    private function paidCompletedBooking(): array
    {
        [$trip, $booking] = $this->booking();
        $service = app(PaymentService::class);
        $service->snapshotFare($trip, $booking, 10000, 1000);
        $service->createPaymentAttempt($trip, $booking, 'test-provider', 'test', 'order-'.$booking->id);
        $service->ingestCapturedPayment(['provider' => 'test-provider', 'environment' => 'test', 'event_id' => 'evt-'.$booking->id, 'payment_reference' => 'pay-'.$booking->id, 'order_reference' => 'order-'.$booking->id, 'status' => 'captured', 'amount' => 10000, 'currency' => 'INR']);
        DB::table('mobility_trip_lifecycles')->insert(['trip_id' => $trip->id, 'state' => 'completed', 'version' => 3, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mobility_trip_participants')->insert(['trip_id' => $trip->id, 'booking_id' => $booking->id, 'user_id' => $booking->user_id, 'state' => 'alighted', 'created_at' => now(), 'updated_at' => now()]);
        $eventId = DB::table('mobility_trip_events')->insertGetId(['trip_id' => $trip->id, 'actor_id' => $trip->user_id, 'action' => 'alight', 'passenger_id' => $booking->user_id, 'idempotency_key' => 'alight-'.$booking->id, 'request_fingerprint' => hash('sha256', 'alight'), 'version' => 2, 'response_status' => 200, 'response_body' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return [$trip, $booking, $eventId];
    }
}
