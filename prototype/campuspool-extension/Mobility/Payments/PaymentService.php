<?php

namespace STS\Mobility\Payments;

use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\Trip;

/**
 * Provider-neutral financial boundary. Callers may enter a capture only after
 * a trusted adapter has verified a signed captured event or authenticated provider object.
 * This class never calls a provider and never treats a client callback as proof.
 */
final class PaymentService
{
    public function snapshotFare(Trip $trip, Passenger $booking, mixed $gross, mixed $commission = 0, string $currency = 'INR'): object
    {
        if (! is_int($gross) || ! is_int($commission) || $gross <= 0 || $gross > intdiv(PHP_INT_MAX, 4) || $commission < 0 || $commission > $gross || $currency !== 'INR') {
            throw new PaymentException('Fare must be positive INR and commission must be within the fare.', 'INVALID_FARE');
        }

        return DB::transaction(function () use ($trip, $booking, $gross, $commission): object {
            $lockedTrip = Trip::query()->whereKey($trip->getKey())->lockForUpdate()->firstOrFail();
            $lockedBooking = Passenger::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            $this->assertBooking($lockedTrip, $lockedBooking);
            $terms = \STS\Mobility\Segments\RouteTerms::forTrip((int) $lockedTrip->id);
            if ($terms !== null) {
                $interval = app(\STS\Mobility\Segments\SegmentBookingService::class)->interval($lockedBooking, $terms);
                if ($gross !== $interval['fare_minor']) {
                    throw new PaymentException('Fare must match the trusted booked interval.', 'FARE_MISMATCH');
                }
            }
            $dispatchFare = \STS\Mobility\Dispatch\DispatchTripPolicy::fare($lockedTrip, $lockedBooking);
            if ($dispatchFare !== null && $gross !== $dispatchFare) {
                throw new PaymentException('Fare must match the trusted dispatch assignment.', 'FARE_MISMATCH');
            }
            $existing = DB::table('mobility_fare_snapshots')->where('booking_id', $lockedBooking->id)->first();
            if ($existing) {
                if ((int) $existing->gross_amount !== $gross || (int) $existing->commission_amount !== $commission) {
                    throw new PaymentException('A booking fare snapshot is immutable.', 'FARE_IMMUTABLE');
                }

                return $existing;
            }
            $this->assertCollectionOpen($lockedTrip);
            $id = DB::table('mobility_fare_snapshots')->insertGetId([
                'trip_id' => $lockedTrip->id, 'booking_id' => $lockedBooking->id,
                'currency' => 'INR', 'gross_amount' => $gross,
                'commission_amount' => $commission, 'driver_amount' => $gross - $commission,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('mobility_fare_snapshots')->where('id', $id)->first();
        });
    }

    public function createPaymentAttempt(Trip $trip, Passenger $booking, string $provider, string $environment, string $orderReference): object
    {
        $this->assertReference($provider, 64);
        $this->assertReference($orderReference, 160);
        if (! in_array($environment, ['test', 'live'], true) || strtolower($provider) === 'cash') {
            throw new PaymentException('A provider payment attempt requires a non-cash provider and environment.', 'INVALID_PAYMENT_ATTEMPT');
        }

        return DB::transaction(function () use ($trip, $booking, $provider, $environment, $orderReference): object {
            $lockedTrip = Trip::query()->whereKey($trip->getKey())->lockForUpdate()->firstOrFail();
            $lockedBooking = Passenger::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            $this->assertBooking($lockedTrip, $lockedBooking);
            if (DB::table('mobility_cash_receipts')->where('booking_id', $lockedBooking->id)->exists()) {
                throw new PaymentException('A cash receipt already selects the collection channel.', 'PAYMENT_METHOD_CONFLICT');
            }
            $fare = DB::table('mobility_fare_snapshots')->where('booking_id', $lockedBooking->id)->lockForUpdate()->first();
            if (! $fare) {
                throw new PaymentException('Create a fare snapshot before payment.', 'FARE_REQUIRED');
            }
            $existing = DB::table('mobility_payment_attempts')->where([
                'provider' => $provider, 'environment' => $environment, 'order_reference' => $orderReference,
            ])->first();
            if ($existing) {
                if ((int) $existing->amount !== (int) $fare->gross_amount || (int) $existing->booking_id !== (int) $lockedBooking->id) {
                    throw new PaymentException('Provider order is bound to another amount or booking.', 'ORDER_CONFLICT');
                }
                if ($existing->status !== 'captured') {
                    $this->assertCollectionOpen($lockedTrip);
                }

                return $existing;
            }
            if (DB::table('mobility_payment_attempts')->where('booking_id', $lockedBooking->id)->where('status', 'captured')->exists()) {
                throw new PaymentException('A booking has already been captured.', 'PAYMENT_ALREADY_CAPTURED');
            }
            $this->assertCollectionOpen($lockedTrip);
            $id = DB::table('mobility_payment_attempts')->insertGetId([
                'trip_id' => $lockedTrip->id, 'booking_id' => $lockedBooking->id,
                'provider' => $provider, 'environment' => $environment,
                'order_reference' => $orderReference, 'amount' => $fare->gross_amount,
                'currency' => 'INR', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('mobility_payment_attempts')->where('id', $id)->first();
        });
    }

    /** Trusted adapter entry: signature verification or authenticated provider fetch precedes this call. */
    public function ingestCapturedPayment(array $verified): object
    {
        foreach (['provider', 'environment', 'event_id', 'payment_reference', 'order_reference', 'status', 'amount', 'currency'] as $key) {
            if (! array_key_exists($key, $verified) || $verified[$key] === '') {
                throw new PaymentException('Provider capture is missing verified fields.', 'CAPTURE_INCOMPLETE');
            }
        }
        foreach (['provider' => 64, 'event_id' => 160, 'payment_reference' => 160, 'order_reference' => 160] as $field => $limit) {
            $this->assertReference($verified[$field], $limit);
        }
        if (! is_int($verified['amount']) || $verified['amount'] <= 0 || $verified['amount'] > intdiv(PHP_INT_MAX, 4) || $verified['status'] !== 'captured' || $verified['currency'] !== 'INR' || ! in_array($verified['environment'], ['test', 'live'], true) || strtolower($verified['provider']) === 'cash') {
            throw new PaymentException('Only a verified captured INR payment may be ingested.', 'CAPTURE_UNVERIFIED');
        }

        // Resolve the lock target before the transaction. A plain SELECT inside a
        // REPEATABLE READ transaction would freeze its snapshot before waiting on
        // the trip lock and could hide a concurrent capture's event/ledger rows.
        $candidate = DB::table('mobility_payment_attempts')->where([
            'provider' => $verified['provider'], 'environment' => $verified['environment'], 'order_reference' => $verified['order_reference'],
        ])->first();
        if (! $candidate || $candidate->currency !== $verified['currency'] || (int) $candidate->amount !== $verified['amount']) {
            throw new PaymentException('Capture does not match the server payment attempt.', 'CAPTURE_MISMATCH');
        }

        return DB::transaction(function () use ($verified, $candidate): object {
            $lockedTrip = Trip::query()->whereKey($candidate->trip_id)->lockForUpdate()->firstOrFail();
            $lockedBooking = Passenger::query()->whereKey($candidate->booking_id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedBooking->trip_id !== (int) $lockedTrip->id || (int) $lockedBooking->passenger_type !== Passenger::TYPE_PASAJERO) {
                throw new PaymentException('Payment attempt booking mapping is inconsistent.', 'BOOKING_MISMATCH');
            }
            $attempt = DB::table('mobility_payment_attempts')->where('id', $candidate->id)->lockForUpdate()->first();
            if (! $attempt || (int) $attempt->trip_id !== (int) $lockedTrip->id || (int) $attempt->booking_id !== (int) $lockedBooking->id
                || $attempt->provider !== $verified['provider'] || $attempt->environment !== $verified['environment']
                || $attempt->order_reference !== $verified['order_reference'] || (int) $attempt->amount !== $verified['amount'] || $attempt->currency !== $verified['currency']) {
                throw new PaymentException('Capture mapping changed while acquiring locks.', 'CAPTURE_MISMATCH');
            }
            $otherCaptured = DB::table('mobility_payment_attempts')->where('booking_id', $attempt->booking_id)->where('status', 'captured')->where('id', '<>', $attempt->id)->exists();
            if ($otherCaptured) {
                throw new PaymentException('Another payment attempt for this booking is already captured; reconcile manually.', 'MULTIPLE_CAPTURE_CONFLICT');
            }
            if ($attempt->status === 'captured' && $attempt->payment_reference !== $verified['payment_reference']) {
                throw new PaymentException('A captured order cannot be rebound to another payment.', 'PAYMENT_REFERENCE_CONFLICT');
            }
            $facts = [];
            foreach (['provider', 'environment', 'event_id', 'payment_reference', 'order_reference', 'status', 'amount', 'currency'] as $field) {
                $facts[$field] = $verified[$field];
            }
            $payloadHash = hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
            $event = DB::table('mobility_provider_events')->where([
                'provider' => $verified['provider'], 'environment' => $verified['environment'], 'event_id' => $verified['event_id'],
            ])->first();
            if ($event) {
                if (! hash_equals($event->payload_hash, $payloadHash)) {
                    throw new PaymentException('Provider event identity was reused with a different payload.', 'EVENT_CONFLICT');
                }

                return DB::table('mobility_payment_attempts')->where('id', $attempt->id)->first();
            }
            DB::table('mobility_provider_events')->insert([
                'provider' => $verified['provider'], 'environment' => $verified['environment'],
                'event_id' => $verified['event_id'], 'event_type' => 'payment.captured',
                'payload_hash' => $payloadHash, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($attempt->status === 'captured') {
                return $attempt;
            }
            if (DB::table('mobility_payment_attempts')->where(['provider' => $verified['provider'], 'environment' => $verified['environment'], 'payment_reference' => $verified['payment_reference']])->where('id', '<>', $attempt->id)->exists()) {
                throw new PaymentException('Provider payment belongs to another order.', 'PAYMENT_REFERENCE_CONFLICT');
            }
            DB::table('mobility_payment_attempts')->where('id', $attempt->id)->update([
                'payment_reference' => $verified['payment_reference'], 'status' => 'captured',
                'captured_at' => now(), 'updated_at' => now(),
            ]);
            $this->postBalanced($attempt->trip_id, $attempt->booking_id, 'capture:'.$attempt->id, [
                ['account' => 'provider_clearing', 'side' => 'debit', 'amount' => (int) $attempt->amount],
                ['account' => 'rider_funds', 'side' => 'credit', 'amount' => (int) $attempt->amount],
            ]);
            if ((int) $lockedBooking->request_state !== Passenger::STATE_ACCEPTED || $lockedTrip->state === Trip::STATE_CANCELED || $lockedTrip->expired()) {
                $this->blockRefundOrDispute($lockedTrip, $lockedBooking, 'late_capture');
            }
            if (DB::table('mobility_cash_receipts')->where('booking_id', $lockedBooking->id)->exists()) {
                $this->blockRefundOrDispute($lockedTrip, $lockedBooking, 'collection_channel_conflict');
            }

            return DB::table('mobility_payment_attempts')->where('id', $attempt->id)->first();
        });
    }

    /** @internal Trusted provider adapter assertion only; never bind these arguments to client input. */
    public function registerBeneficiary(int $userId, string $provider, string $environment, string $reference, bool $holdCapable, bool $verified): object
    {
        $this->assertReference($provider, 64);
        $this->assertReference($reference, 160);
        if (! in_array($environment, ['test', 'live'], true) || ! $verified) {
            throw new PaymentException('Beneficiary registration requires trusted provider verification.', 'BENEFICIARY_UNVERIFIED');
        }
        DB::table('mobility_payment_beneficiaries')->updateOrInsert(['user_id' => $userId, 'provider' => $provider, 'environment' => $environment], ['beneficiary_reference' => $reference, 'status' => 'verified', 'hold_capable' => $holdCapable, 'verified_at' => now(), 'updated_at' => now(), 'created_at' => now()]);

        return DB::table('mobility_payment_beneficiaries')->where(['user_id' => $userId, 'provider' => $provider, 'environment' => $environment])->first();
    }

    public function blockRefundOrDispute(Trip $trip, Passenger $booking, string $reason): void
    {
        $this->assertReference($reason, 64);
        DB::transaction(function () use ($trip, $booking, $reason): void {
            $lockedTrip = Trip::query()->whereKey($trip->getKey())->lockForUpdate()->firstOrFail();
            $lockedBooking = Passenger::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $lockedBooking->trip_id !== (int) $lockedTrip->id) {
                throw new PaymentException('Booking does not belong to trip.', 'BOOKING_MISMATCH');
            }
            DB::table('mobility_payment_blocks')->updateOrInsert(['booking_id' => $lockedBooking->id, 'reason' => $reason], ['trip_id' => $lockedTrip->id, 'active' => true, 'updated_at' => now(), 'created_at' => now()]);
            DB::table('mobility_payout_outbox')->whereIn('allocation_id', DB::table('mobility_payout_allocations')->where('booking_id', $lockedBooking->id)->pluck('id'))->whereIn('status', ['pending', 'blocked'])->update(['status' => 'blocked', 'updated_at' => now()]);
            DB::table('mobility_payout_allocations')->where('booking_id', $lockedBooking->id)->whereIn('status', ['eligible', 'blocked'])->update(['status' => 'blocked', 'updated_at' => now()]);
        });
    }

    public function enqueueRelease(Trip $trip, Passenger $booking): object
    {
        return DB::transaction(function () use ($trip, $booking): object {
            $lockedTrip = Trip::query()->whereKey($trip->getKey())->lockForUpdate()->firstOrFail();
            $lockedBooking = Passenger::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            $this->assertBooking($lockedTrip, $lockedBooking);
            if (DB::table('mobility_cash_receipts')->where('booking_id', $lockedBooking->id)->exists()) {
                throw new PaymentException('Cash cannot enter a provider payout allocation.', 'PAYMENT_METHOD_CONFLICT');
            }
            $lifecycle = DB::table('mobility_trip_lifecycles')->where('trip_id', $lockedTrip->id)->first();
            $participant = DB::table('mobility_trip_participants')->where(['trip_id' => $lockedTrip->id, 'booking_id' => $lockedBooking->id, 'user_id' => $lockedBooking->user_id, 'state' => 'alighted'])->exists();
            $dropoff = DB::table('mobility_dropoff_confirmations as proof')->where(['proof.trip_id' => $lockedTrip->id, 'proof.booking_id' => $lockedBooking->id, 'proof.user_id' => $lockedBooking->user_id])->whereExists(function ($q) use ($lockedTrip, $lockedBooking) {
                $q->selectRaw('1')->from('mobility_trip_events as event')->whereColumn('event.id', 'proof.confirmed_after_event_id')->where('event.trip_id', $lockedTrip->id)->where('event.passenger_id', $lockedBooking->user_id)->where('event.action', 'alight');
            })->exists();
            if ($lifecycle?->state !== 'completed' || $lockedTrip->state === Trip::STATE_CANCELED || ! $participant || ! $dropoff) {
                throw new PaymentException('Trip and rider drop-off are not confirmed.', 'PAYOUT_EARLY');
            }
            $payment = DB::table('mobility_payment_attempts')->where('booking_id', $lockedBooking->id)->where('status', 'captured')->lockForUpdate()->first();
            if (! $payment || DB::table('mobility_payment_blocks')->where(['booking_id' => $lockedBooking->id, 'active' => true])->exists()) {
                throw new PaymentException('Captured payment or refund/dispute clearance is missing.', 'PAYOUT_BLOCKED');
            }
            $allocation = DB::table('mobility_payout_allocations')->where('booking_id', $lockedBooking->id)->first();
            if ($allocation) {
                $outbox = DB::table('mobility_payout_outbox')->where('allocation_id', $allocation->id)->first();
                if (! $outbox) {
                    throw new PaymentException('Payout allocation has no durable command; reconcile before proceeding.', 'PAYOUT_RECONCILIATION_REQUIRED');
                }

                return $outbox;
            }
            $beneficiary = DB::table('mobility_payment_beneficiaries')->where(['user_id' => $lockedTrip->user_id, 'provider' => $payment->provider, 'environment' => $payment->environment, 'status' => 'verified', 'hold_capable' => true])->first();
            if (! $beneficiary) {
                throw new PaymentException('Beneficiary or delayed-settlement capability is not verified.', 'PAYOUT_NOT_READY');
            }
            $fare = DB::table('mobility_fare_snapshots')->where('booking_id', $lockedBooking->id)->first();
            $funds = (int) DB::table('mobility_financial_postings')->where('booking_id', $lockedBooking->id)->where('account', 'rider_funds')->selectRaw("COALESCE(SUM(CASE WHEN side = 'credit' THEN amount ELSE -CAST(amount AS SIGNED) END), 0) AS balance")->value('balance');
            if (! $fare || (int) $fare->driver_amount <= 0 || (int) $fare->gross_amount !== (int) $payment->amount || $funds < (int) $fare->gross_amount) {
                throw new PaymentException('No sufficient captured funds are available for payout.', 'PAYOUT_FUNDS');
            }
            $id = DB::table('mobility_payout_allocations')->insertGetId(['trip_id' => $lockedTrip->id, 'booking_id' => $lockedBooking->id, 'payment_attempt_id' => $payment->id, 'driver_id' => $lockedTrip->user_id, 'beneficiary_reference' => $beneficiary->beneficiary_reference, 'amount' => $fare->driver_amount, 'status' => 'eligible', 'created_at' => now(), 'updated_at' => now()]);
            $this->postBalanced($lockedTrip->id, $lockedBooking->id, 'release:'.$id, [['account' => 'rider_funds', 'side' => 'debit', 'amount' => (int) $fare->gross_amount], ['account' => 'driver_payable', 'side' => 'credit', 'amount' => (int) $fare->driver_amount], ['account' => 'platform_fee_pending', 'side' => 'credit', 'amount' => (int) $fare->commission_amount]]);
            DB::table('mobility_payout_outbox')->insert(['allocation_id' => $id, 'idempotency_key' => 'payout:'.$id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('mobility_payout_outbox')->where('allocation_id', $id)->first();
        });
    }

    private function postBalanced(int $tripId, int $bookingId, string $group, array $entries): void
    {
        if (array_sum(array_map(fn ($e) => $e['side'] === 'debit' ? $e['amount'] : -$e['amount'], $entries)) !== 0) {
            throw new PaymentException('Financial posting is unbalanced.', 'LEDGER_UNBALANCED');
        }
        foreach ($entries as $entry) {
            if (! is_int($entry['amount']) || $entry['amount'] < 0) {
                throw new PaymentException('Posting amounts must be nonnegative minor units.', 'LEDGER_AMOUNT');
            }
            if ($entry['amount'] === 0) {
                continue;
            }
            DB::table('mobility_financial_postings')->insert(['trip_id' => $tripId, 'booking_id' => $bookingId, 'entry_group' => $group, 'account' => $entry['account'], 'side' => $entry['side'], 'amount' => $entry['amount'], 'currency' => 'INR', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function assertCollectionOpen(Trip $trip): void
    {
        $state = DB::table('mobility_trip_lifecycles')->where('trip_id', $trip->id)->value('state');
        if (($state !== null && $state !== 'planned') || ! in_array($trip->state, [null, Trip::STATE_READY, Trip::STATE_PAID], true) || $trip->expired()) {
            throw new PaymentException('New collection is closed for this trip.', 'COLLECTION_CLOSED');
        }
    }

    private function assertReference(mixed $value, int $limit): void
    {
        if (! is_string($value) || $value === '' || strlen($value) > $limit || ! preg_match('/^[\x21-\x7E]+$/', $value)) {
            throw new PaymentException('Provider identifiers must be printable ASCII and bounded.', 'INVALID_PROVIDER_REFERENCE');
        }
    }

    private function assertBooking(Trip $trip, Passenger $booking): void
    {
        if ((int) $booking->trip_id !== (int) $trip->id || (int) $booking->passenger_type !== Passenger::TYPE_PASAJERO || (int) $booking->request_state !== Passenger::STATE_ACCEPTED) {
            throw new PaymentException('Only an accepted passenger booking may be paid.', 'BOOKING_NOT_PAYABLE');
        }
    }
}
