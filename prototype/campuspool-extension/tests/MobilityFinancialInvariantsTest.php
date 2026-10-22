<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Payments\PaymentException;
use STS\Mobility\Payments\PaymentService;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

class MobilityFinancialInvariantsTest extends TestCase
{
    public function test_fare_is_immutable_and_does_not_coerce_fractional_or_boolean_minor_units(): void
    {
        [$trip, $booking] = $this->booking();
        $service = app(PaymentService::class);
        foreach ([100.9, true, '100', PHP_INT_MAX] as $amount) {
            $this->reject(fn () => $service->snapshotFare($trip, $booking, $amount), 'INVALID_FARE');
        }
        $fare = $service->snapshotFare($trip, $booking, 10000, 1000);
        $this->assertSame($fare->id, $service->snapshotFare($trip, $booking, 10000, 1000)->id);
        $this->reject(fn () => $service->snapshotFare($trip, $booking, 10001, 1000), 'FARE_IMMUTABLE');
    }

    public function test_orders_are_case_sensitive_and_capture_environment_is_bound(): void
    {
        [$trip, $booking] = $this->booking();
        $service = app(PaymentService::class);
        $service->snapshotFare($trip, $booking, 10000);
        $a = $service->createPaymentAttempt($trip, $booking, 'fixture', 'test', 'Order-A');
        $b = $service->createPaymentAttempt($trip, $booking, 'fixture', 'test', 'order-a');
        $this->assertNotSame($a->id, $b->id);
        $this->reject(fn () => $service->ingestCapturedPayment($this->capture('Order-A', ['environment' => 'live'])), 'CAPTURE_MISMATCH');
        $this->reject(fn () => $service->ingestCapturedPayment($this->capture('Order-A', ['currency' => 'USD'])), 'CAPTURE_UNVERIFIED');
        $this->assertDatabaseCount('mobility_financial_postings', 0);
    }

    public function test_pending_checkout_is_not_returned_after_start(): void
    {
        [$trip, $booking] = $this->booking();
        $service = app(PaymentService::class);
        $service->snapshotFare($trip, $booking, 10000);
        $service->createPaymentAttempt($trip, $booking, 'fixture', 'test', 'order-pending');
        $this->actingAsApiUser($trip->user)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'start'], ['Idempotency-Key' => 'start'])->assertOk();
        $this->reject(fn () => $service->createPaymentAttempt($trip, $booking, 'fixture', 'test', 'order-pending'), 'COLLECTION_CLOSED');
        $this->assertDatabaseCount('mobility_payment_attempts', 1);
    }

    public function test_capture_reordering_and_new_event_identity_cannot_duplicate_or_rebind_money(): void
    {
        [$trip, $booking] = $this->booking();
        $service = app(PaymentService::class);
        $service->snapshotFare($trip, $booking, 10000);
        $attempt = $service->createPaymentAttempt($trip, $booking, 'fixture', 'test', 'order-A');
        $facts = $this->capture('order-A');
        $service->ingestCapturedPayment($facts);
        $service->ingestCapturedPayment(array_reverse($facts, true));
        $service->ingestCapturedPayment(array_replace($facts, ['event_id' => 'evt-A-new']));
        $this->assertDatabaseCount('mobility_provider_events', 2);
        $this->assertDatabaseCount('mobility_financial_postings', 2);
        $this->assertSame($attempt->id, $service->createPaymentAttempt($trip, $booking, 'fixture', 'test', 'order-A')->id);
        $this->reject(fn () => $service->ingestCapturedPayment(array_replace($facts, ['payment_reference' => 'pay-other'])), 'PAYMENT_REFERENCE_CONFLICT');
        $this->reject(fn () => $service->createPaymentAttempt($trip, $booking, 'fixture', 'test', 'another-order'), 'PAYMENT_ALREADY_CAPTURED');
        $this->assertSame('pay-order-A', DB::table('mobility_payment_attempts')->where('id', $attempt->id)->value('payment_reference'));
    }

    public function test_late_capture_is_recorded_and_blocked_without_restoring_cancelled_booking(): void
    {
        [$trip, $booking] = $this->booking();
        $service = app(PaymentService::class);
        $service->snapshotFare($trip, $booking, 10000);
        $service->createPaymentAttempt($trip, $booking, 'fixture', 'test', 'order-late');
        $this->actingAsApiUser($trip->user)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'cancel'], ['Idempotency-Key' => 'cancel'])->assertOk();
        $service->ingestCapturedPayment($this->capture('order-late'));
        $this->assertDatabaseHas('mobility_payment_attempts', ['booking_id' => $booking->id, 'status' => 'captured']);
        $this->assertDatabaseHas('mobility_payment_blocks', ['booking_id' => $booking->id, 'reason' => 'late_capture', 'active' => true]);
        $this->assertSame(Passenger::STATE_CANCELED, $booking->fresh()->request_state);
        $this->assertDatabaseCount('mobility_financial_postings', 2);
        $this->assertDatabaseCount('mobility_payout_outbox', 0);
    }

    public function test_two_passenger_release_conserves_separate_funds_and_freezes_beneficiary(): void
    {
        [$trip, $a] = $this->booking();
        $b = Passenger::factory()->aceptado()->create(['trip_id' => $trip->id, 'user_id' => User::factory()->create()->id]);
        $service = app(PaymentService::class);
        foreach ([$a, $b] as $booking) {
            $service->snapshotFare($trip, $booking, 10000, 1000);
            $service->createPaymentAttempt($trip, $booking, 'fixture', 'test', 'order-'.$booking->id);
            $service->ingestCapturedPayment($this->capture('order-'.$booking->id));
        }
        $this->complete($trip, [$a, $b]);
        $service->registerBeneficiary($trip->user_id, 'fixture', 'test', 'beneficiary-original', true, true);
        foreach ([$a, $b] as $booking) {
            $first = $service->enqueueRelease($trip, $booking);
            $this->assertSame($first->id, $service->enqueueRelease($trip, $booking)->id);
            $this->assertSame(0, (int) DB::table('mobility_financial_postings')->where('booking_id', $booking->id)->selectRaw("SUM(CASE WHEN side='debit' THEN amount ELSE -CAST(amount AS SIGNED) END) as balance")->value('balance'));
            $this->assertSame(10000, (int) DB::table('mobility_financial_postings')->where('booking_id', $booking->id)->where('entry_group', 'release:'.$first->allocation_id)->where('side', 'credit')->sum('amount'));
        }
        $service->registerBeneficiary($trip->user_id, 'fixture', 'test', 'beneficiary-new', true, true);
        $this->assertSame(['beneficiary-original'], DB::table('mobility_payout_allocations')->distinct()->pluck('beneficiary_reference')->all());
        $this->assertDatabaseCount('mobility_payout_outbox', 2);
    }

    public function test_refund_or_dispute_blocks_a_queued_payout_and_preserves_audit_postings(): void
    {
        [$trip, $booking] = $this->paid();
        $this->complete($trip, [$booking]);
        $service = app(PaymentService::class);
        $service->registerBeneficiary($trip->user_id, 'fixture', 'test', 'beneficiary', true, true);
        $outbox = $service->enqueueRelease($trip, $booking);
        $postings = DB::table('mobility_financial_postings')->count();
        $service->blockRefundOrDispute($trip, $booking, 'refund');
        $service->blockRefundOrDispute($trip, $booking, 'refund');
        $this->assertDatabaseHas('mobility_payout_outbox', ['id' => $outbox->id, 'status' => 'blocked']);
        $this->assertDatabaseHas('mobility_payout_allocations', ['id' => $outbox->allocation_id, 'status' => 'blocked']);
        $this->reject(fn () => $service->enqueueRelease($trip, $booking), 'PAYOUT_BLOCKED');
        $this->assertSame($postings, DB::table('mobility_financial_postings')->count());
    }

    public function test_missing_outbox_requires_reconciliation_without_reposting(): void
    {
        [$trip, $booking] = $this->paid();
        $this->complete($trip, [$booking]);
        $service = app(PaymentService::class);
        $service->registerBeneficiary($trip->user_id, 'fixture', 'test', 'beneficiary', true, true);
        $outbox = $service->enqueueRelease($trip, $booking);
        DB::table('mobility_payout_outbox')->where('id', $outbox->id)->delete();
        $count = DB::table('mobility_financial_postings')->count();
        $this->reject(fn () => $service->enqueueRelease($trip, $booking), 'PAYOUT_RECONCILIATION_REQUIRED');
        $this->assertSame($count, DB::table('mobility_financial_postings')->count());
        $this->assertDatabaseCount('mobility_payout_allocations', 1);
    }

    public function test_matching_proof_and_provider_capability_are_required_and_cash_cannot_enter_provider_path(): void
    {
        [$trip, $booking] = $this->paid();
        $service = app(PaymentService::class);
        $this->complete($trip, [$booking]);
        $this->reject(fn () => $service->enqueueRelease($trip, $booking), 'PAYOUT_NOT_READY');
        $service->registerBeneficiary($trip->user_id, 'fixture', 'test', 'beneficiary', false, true);
        $this->reject(fn () => $service->enqueueRelease($trip, $booking), 'PAYOUT_NOT_READY');
        $service->registerBeneficiary($trip->user_id, 'fixture', 'test', 'beneficiary', true, true);
        DB::table('mobility_dropoff_confirmations')->where('booking_id', $booking->id)->update(['user_id' => $trip->user_id]);
        $this->reject(fn () => $service->enqueueRelease($trip, $booking), 'PAYOUT_EARLY');
        foreach (['cash', 'CASH', 'Cash'] as $provider) {
            $this->reject(fn () => $service->createPaymentAttempt($trip, $booking, $provider, 'test', 'cash-order'), 'INVALID_PAYMENT_ATTEMPT');
        }
        $this->assertDatabaseCount('mobility_payout_outbox', 0);
    }

    private function complete(Trip $trip, array $bookings): void
    {
        $owner = $trip->user;
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'start'], ['Idempotency-Key' => 'start'])->assertOk();
        foreach ($bookings as $booking) {
            foreach (['board', 'alight'] as $action) {
                $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => $action, 'passenger_id' => $booking->user_id], ['Idempotency-Key' => $action.'-'.$booking->id])->assertOk();
            }
            $this->actingAsApiUser(User::findOrFail($booking->user_id))->postJson('/api/mobility/trips/'.$trip->id.'/dropoff-confirmation', [], ['Idempotency-Key' => 'drop-'.$booking->id])->assertOk();
        }
        $this->actingAsApiUser($owner)->postJson('/api/mobility/trips/'.$trip->id.'/transitions', ['action' => 'complete'], ['Idempotency-Key' => 'complete'])->assertOk();
    }

    private function booking(): array
    {
        $trip = Trip::factory()->create(['user_id' => User::factory()->create()->id]);
        $booking = Passenger::factory()->aceptado()->create(['trip_id' => $trip->id, 'user_id' => User::factory()->create()->id]);

        return [$trip, $booking];
    }

    private function paid(): array
    {
        [$trip, $booking] = $this->booking();
        $service = app(PaymentService::class);
        $service->snapshotFare($trip, $booking, 10000, 1000);
        $service->createPaymentAttempt($trip, $booking, 'fixture', 'test', 'order-paid');
        $service->ingestCapturedPayment($this->capture('order-paid'));

        return [$trip, $booking];
    }

    private function capture(string $order, array $overrides = []): array
    {
        return array_replace(['provider' => 'fixture', 'environment' => 'test', 'event_id' => 'evt-'.$order,
            'order_reference' => $order, 'payment_reference' => 'pay-'.$order, 'status' => 'captured',
            'amount' => 10000, 'currency' => 'INR'], $overrides);
    }

    private function reject(callable $call, string $code): void
    {
        try {
            $call();
            $this->fail('Expected rejection: '.$code);
        } catch (PaymentException $e) {
            $this->assertSame($code, $e->codeName);
        }
    }
}
