<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Payments\PaymentException;
use STS\Mobility\Payments\PaymentService;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

class MobilityPaymentViewsTest extends TestCase
{
    public function test_rider_sees_only_own_financial_history_and_no_provider_references(): void
    {
        [$trip, $bookings] = $this->fixture(2);
        foreach ($bookings as $booking) {
            app(PaymentService::class)->createPaymentAttempt($trip, $booking, 'fixture', 'test', 'private-order-'.$booking->id);
        }
        $rider = User::findOrFail($bookings[0]->user_id);
        $response = $this->actingAsApiUser($rider)->getJson($this->path($trip))->assertOk()
            ->assertJsonPath('audience', 'rider')->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.booking_id', $bookings[0]->id)->assertJsonPath('bookings.0.collection_state', 'pending')
            ->assertJsonPath('bookings.0.provider_collections.0.environment', 'test')->assertJsonPath('live_provider_dispatch_enabled', false);
        $this->assertArrayNotHasKey('rider_id', $response->json('bookings.0'));
        $this->assertArrayNotHasKey('allocated_driver_amount', $response->json('bookings.0'));
        $this->assertStringNotContainsString('private-order-', $response->getContent());
        $this->assertStringNotContainsString('beneficiary_reference', $response->getContent());
        $bookings[0]->update(['request_state' => Passenger::STATE_CANCELED]);
        $this->actingAsApiUser($rider)->getJson($this->path($trip))->assertOk()->assertJsonCount(1, 'bookings');
    }

    public function test_driver_view_is_paginated_and_never_claims_a_settled_balance(): void
    {
        [$trip, $bookings] = $this->fixture(3);
        $first = $this->actingAsApiUser($trip->user)->getJson($this->path($trip).'?page_size=2')->assertOk()
            ->assertJsonPath('audience', 'driver')->assertJsonCount(2, 'bookings')->assertJsonPath('pagination.has_more', true)
            ->assertJsonPath('bookings.0.quoted_driver_amount', 9000)->assertJsonPath('bookings.0.allocated_driver_amount', 0)
            ->assertJsonPath('bookings.0.driver_payout_status', 'not_allocated')->assertJsonPath('bookings.0.settlement_status', 'not_verified');
        $after = $first->json('pagination.next_after');
        $this->actingAsApiUser($trip->user)->getJson($this->path($trip).'?page_size=2&after='.$after)->assertOk()
            ->assertJsonCount(1, 'bookings')->assertJsonPath('pagination.has_more', false)->assertJsonPath('bookings.0.booking_id', $bookings[2]->id);
        $this->actingAsApiUser($trip->user)->getJson($this->path($trip).'?page_size=101')->assertStatus(422);
        $this->actingAsApiUser($trip->user)->getJson($this->path($trip).'?after=-1')->assertStatus(422);
    }

    public function test_operator_audit_requires_explicit_admin_route_and_keeps_block_reason_private(): void
    {
        [$trip, $bookings] = $this->fixture();
        $booking = $bookings[0];
        app(PaymentService::class)->createPaymentAttempt($trip, $booking, 'fixture', 'test', 'private-order');
        app(PaymentService::class)->blockRefundOrDispute($trip, $booking, 'operator_review');
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAsApiUser($admin)->getJson($this->path($trip))->assertForbidden();
        $this->actingAsApiUser($admin)->getJson($this->operatorPath($trip))->assertOk()
            ->assertJsonPath('audience', 'operator')->assertJsonPath('bookings.0.block_reasons.0', 'operator_review')
            ->assertJsonPath('bookings.0.provider_collections.0.order_reference', 'private-order');
        $rider = User::findOrFail($booking->user_id);
        $view = $this->actingAsApiUser($rider)->getJson($this->path($trip))->assertOk()->assertJsonPath('bookings.0.payment_blocked', true);
        $this->assertArrayNotHasKey('block_reasons', $view->json('bookings.0'));
        $this->actingAsApiUser($rider)->getJson($this->operatorPath($trip))->assertForbidden();
        $this->actingAsApiUser(User::factory()->create())->getJson($this->path($trip))->assertForbidden();
    }

    public function test_blocked_or_missing_commands_are_never_presented_as_paid_out(): void
    {
        [$trip, $bookings] = $this->fixture();
        $booking = $bookings[0];
        $service = app(PaymentService::class);
        $attempt = $service->createPaymentAttempt($trip, $booking, 'fixture', 'test', 'order-view');
        $service->ingestCapturedPayment(['provider' => 'fixture', 'environment' => 'test', 'event_id' => 'event-view', 'order_reference' => 'order-view', 'payment_reference' => 'pay-view', 'status' => 'captured', 'amount' => 10000, 'currency' => 'INR']);
        $allocation = DB::table('mobility_payout_allocations')->insertGetId(['trip_id' => $trip->id, 'booking_id' => $booking->id,
            'payment_attempt_id' => $attempt->id, 'driver_id' => $trip->user_id, 'beneficiary_reference' => 'private-beneficiary', 'amount' => 9000, 'status' => 'eligible']);
        $this->actingAsApiUser($trip->user)->getJson($this->path($trip))->assertOk()->assertJsonPath('bookings.0.driver_payout_status', 'reconciliation_required');
        DB::table('mobility_payout_outbox')->insert(['allocation_id' => $allocation, 'idempotency_key' => 'outbox-view', 'status' => 'pending']);
        $this->actingAsApiUser($trip->user)->getJson($this->path($trip))->assertOk()->assertJsonPath('bookings.0.driver_payout_status', 'queued');
        $service->blockRefundOrDispute($trip, $booking, 'dispute');
        $response = $this->actingAsApiUser($trip->user)->getJson($this->path($trip))->assertOk()
            ->assertJsonPath('bookings.0.driver_payout_status', 'blocked')->assertJsonPath('bookings.0.settlement_status', 'not_verified');
        $this->assertStringNotContainsString('private-beneficiary', $response->getContent());
    }

    public function test_nonstartable_legacy_trip_cannot_open_collection(): void
    {
        [$trip, $bookings] = $this->fixture();
        foreach ([Trip::STATE_AWAITING_PAYMENT, Trip::STATE_PENDING_PAYMENT, Trip::STATE_PAYMENT_FAILED] as $state) {
            $trip->state = $state;
            $trip->save();
            try {
                app(PaymentService::class)->createPaymentAttempt($trip, $bookings[0], 'fixture', 'test', 'order-'.$state);
                $this->fail('Nonstartable trip opened collection');
            } catch (PaymentException $e) {
                $this->assertSame('COLLECTION_CLOSED', $e->codeName);
            }
        }
        $this->assertDatabaseCount('mobility_payment_attempts', 0);
    }

    private function fixture(int $count = 1): array
    {
        $trip = Trip::factory()->create(['user_id' => User::factory()->create()->id]);
        $bookings = [];
        for ($i = 0; $i < $count; $i++) {
            $booking = Passenger::factory()->aceptado()->create(['trip_id' => $trip->id, 'user_id' => User::factory()->create()->id]);
            app(PaymentService::class)->snapshotFare($trip, $booking, 10000, 1000);
            $bookings[] = $booking;
        }

        return [$trip, $bookings];
    }

    private function path(Trip $trip): string
    {
        return '/api/mobility/trips/'.$trip->id.'/payments';
    }

    private function operatorPath(Trip $trip): string
    {
        return '/api/mobility/operator/trips/'.$trip->id.'/payments';
    }
}
