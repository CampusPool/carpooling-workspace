<?php

namespace STS\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\Trip;

/** Records authenticated cash acknowledgements; it never creates money or ledger entries. */
final class CashReceiptService
{
    public function record(Trip $trip, Passenger $booking, object $actor, mixed $action, string $key): array
    {
        if (! is_string($action) || ! in_array($action, ['rider_paid', 'driver_received', 'dispute'], true)) {
            throw new CashReceiptException('Unsupported cash receipt action.', 'INVALID_CASH_ACTION');
        }
        if (! preg_match('/^[\x21-\x7E]{1,100}$/D', $key)) {
            throw new CashReceiptException('A printable Idempotency-Key is required.', 'INVALID_IDEMPOTENCY_KEY');
        }

        return DB::transaction(function () use ($trip, $booking, $actor, $action, $key): array {
            $lockedTrip = Trip::query()->whereKey($trip->getKey())->lockForUpdate()->firstOrFail();
            $lockedBooking = Passenger::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            $actorId = (int) $actor->getAuthIdentifier();
            $this->assertMapping($lockedTrip, $lockedBooking);
            $isDriver = $actorId === (int) $lockedTrip->user_id;
            $isRider = $actorId === (int) $lockedBooking->user_id;
            if (! $isDriver && ! $isRider) {
                throw new CashReceiptException('Only the trip driver or exact accepted rider may acknowledge cash.', 'CASH_ACTOR_FORBIDDEN');
            }
            if ($action === 'rider_paid' && ! $isRider) {
                throw new CashReceiptException('Only the accepted rider may confirm payment.', 'CASH_RIDER_REQUIRED');
            }
            if ($action === 'driver_received' && ! $isDriver) {
                throw new CashReceiptException('Only the trip driver may confirm receipt.', 'CASH_DRIVER_REQUIRED');
            }
            $fingerprint = hash('sha256', json_encode(['action' => $action], JSON_THROW_ON_ERROR));
            $event = DB::table('mobility_cash_receipt_events')->where([
                'trip_id' => $lockedTrip->id, 'booking_id' => $lockedBooking->id,
                'actor_id' => $actorId, 'idempotency_key' => $key,
            ])->first();
            if ($event) {
                if (! hash_equals($event->request_fingerprint, $fingerprint)) {
                    throw new CashReceiptException('Idempotency key was reused for another action.', 'IDEMPOTENCY_CONFLICT');
                }

                return json_decode($event->response_body, true, 512, JSON_THROW_ON_ERROR);
            }

            $receipt = DB::table('mobility_cash_receipts')->where('booking_id', $lockedBooking->id)->lockForUpdate()->first();
            if ($receipt) {
                $this->assertReceiptFacts($receipt, $lockedTrip, $lockedBooking);
            }
            if ($action === 'dispute') {
                if (! $receipt) {
                    throw new CashReceiptException('An existing cash receipt is required to dispute.', 'CASH_RECEIPT_REQUIRED');
                }
                if (! $receipt->disputed_at) {
                    DB::table('mobility_cash_receipts')->where('id', $receipt->id)->update(['disputed_at' => now()->utc(), 'updated_at' => now()]);
                    $receipt = DB::table('mobility_cash_receipts')->where('id', $receipt->id)->first();
                } else {
                    throw new CashReceiptException('This cash receipt is already disputed.', 'CASH_ACTION_ALREADY_RECORDED');
                }
            } else {
                if ($receipt) {
                    if ($receipt->disputed_at) {
                        throw new CashReceiptException('A disputed cash receipt cannot be acknowledged again.', 'CASH_RECEIPT_DISPUTED');
                    }
                    $already = $action === 'rider_paid' ? $receipt->rider_confirmed_at : $receipt->driver_confirmed_at;
                    if ($already) {
                        throw new CashReceiptException('This cash acknowledgement is already recorded.', 'CASH_ACTION_ALREADY_RECORDED');
                    }
                } else {
                    $this->assertFirstReceiptReady($lockedTrip, $lockedBooking);
                    $fare = DB::table('mobility_fare_snapshots')->where('booking_id', $lockedBooking->id)->lockForUpdate()->first();
                    if (! $fare) {
                        throw new CashReceiptException('A trusted fare snapshot is required.', 'CASH_FARE_REQUIRED');
                    }
                    $terms = \STS\Mobility\Segments\RouteTerms::forTrip((int) $lockedTrip->id);
                    if ($terms !== null && (int) $fare->gross_amount !== app(\STS\Mobility\Segments\SegmentBookingService::class)->interval($lockedBooking, $terms)['fare_minor']) {
                        throw new CashReceiptException('Fare does not match the booked interval.', 'CASH_FARE_MISMATCH');
                    }
                    $dispatchFare = \STS\Mobility\Dispatch\DispatchTripPolicy::fare($lockedTrip, $lockedBooking);
                    if ($dispatchFare !== null && (int) $fare->gross_amount !== $dispatchFare) {
                        throw new CashReceiptException('Fare must match the trusted dispatch assignment.', 'CASH_FARE_MISMATCH');
                    }
                    $id = DB::table('mobility_cash_receipts')->insertGetId([
                        'trip_id' => $lockedTrip->id, 'booking_id' => $lockedBooking->id,
                        'rider_id' => $lockedBooking->user_id, 'driver_id' => $lockedTrip->user_id,
                        'currency' => $fare->currency, 'gross_amount' => $fare->gross_amount,
                        'commission_amount' => $fare->commission_amount, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $receipt = DB::table('mobility_cash_receipts')->where('id', $id)->lockForUpdate()->first();
                }
                $column = $action === 'rider_paid' ? 'rider_confirmed_at' : 'driver_confirmed_at';
                if (! $receipt->{$column}) {
                    DB::table('mobility_cash_receipts')->where('id', $receipt->id)->update([$column => now()->utc(), 'updated_at' => now()]);
                    $receipt = DB::table('mobility_cash_receipts')->where('id', $receipt->id)->first();
                }
            }
            $receipt ??= DB::table('mobility_cash_receipts')->where('booking_id', $lockedBooking->id)->first();
            $body = $this->response($receipt);
            DB::table('mobility_cash_receipt_events')->insert([
                'trip_id' => $lockedTrip->id, 'booking_id' => $lockedBooking->id, 'actor_id' => $actorId,
                'action' => $action, 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
                'response_body' => json_encode($body, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $body;
        });
    }

    private function assertMapping(Trip $trip, Passenger $booking): void
    {
        if ((int) $booking->trip_id !== (int) $trip->id || (int) $booking->passenger_type !== Passenger::TYPE_PASAJERO) {
            throw new CashReceiptException('Booking does not belong to this trip.', 'BOOKING_MISMATCH');
        }
        if ((int) $trip->user_id === (int) $booking->user_id) {
            throw new CashReceiptException('Cash acknowledgements require distinct rider and driver identities.', 'CASH_DISTINCT_PARTIES_REQUIRED');
        }
    }

    private function assertFirstReceiptReady(Trip $trip, Passenger $booking): void
    {
        if ((int) $booking->request_state !== Passenger::STATE_ACCEPTED) {
            throw new CashReceiptException('Only an accepted booking may use cash.', 'ACCEPTED_BOOKING_REQUIRED');
        }
        $state = DB::table('mobility_trip_lifecycles')->where('trip_id', $trip->id)->value('state');
        $participant = DB::table('mobility_trip_participants')->where(['trip_id' => $trip->id, 'booking_id' => $booking->id, 'user_id' => $booking->user_id])->first();
        if ($trip->state === Trip::STATE_CANCELED || ! in_array($state, ['in_progress', 'completed'], true) || ! in_array($participant?->state, ['boarded', 'alighted'], true) || ($state === 'completed' && $participant?->state !== 'alighted')) {
            throw new CashReceiptException('Trip and participant are not ready for a cash receipt.', 'CASH_NOT_READY');
        }
        if (DB::table('mobility_payment_attempts')->where('booking_id', $booking->id)->exists()) {
            throw new CashReceiptException('A provider payment attempt already exists for this booking.', 'CASH_PROVIDER_CONFLICT');
        }
    }

    private function assertReceiptFacts(object $receipt, Trip $trip, Passenger $booking): void
    {
        if ((int) $receipt->trip_id !== (int) $trip->id || (int) $receipt->booking_id !== (int) $booking->id || (int) $receipt->rider_id !== (int) $booking->user_id || (int) $receipt->driver_id !== (int) $trip->user_id) {
            throw new CashReceiptException('Cash receipt mapping is inconsistent.', 'CASH_RECEIPT_MISMATCH');
        }
    }

    private function response(object $receipt): array
    {
        return ['receipt_id' => (int) $receipt->id, 'trip_id' => (int) $receipt->trip_id, 'booking_id' => (int) $receipt->booking_id,
            'rider_id' => (int) $receipt->rider_id, 'driver_id' => (int) $receipt->driver_id, 'currency' => $receipt->currency,
            'gross_amount' => (int) $receipt->gross_amount, 'commission_amount' => (int) $receipt->commission_amount,
            'rider_confirmed' => (bool) $receipt->rider_confirmed_at, 'driver_confirmed' => (bool) $receipt->driver_confirmed_at,
            'status' => $receipt->disputed_at ? 'disputed' : ($receipt->rider_confirmed_at && $receipt->driver_confirmed_at ? 'confirmed' : 'pending'),
            'disputed_at' => $receipt->disputed_at];
    }
}
