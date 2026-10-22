<?php

namespace STS\Mobility;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\Trip;

class PaymentViewController
{
    public function show(Request $request, Trip $trip): JsonResponse
    {
        return $this->read($request, $trip, false);
    }

    public function operatorShow(Request $request, Trip $trip): JsonResponse
    {
        if (! $request->user()->is_admin) {
            return response()->json(['error' => ['code' => 'OPERATOR_FORBIDDEN']], 403);
        }

        return $this->read($request, $trip, true);
    }

    private function read(Request $request, Trip $trip, bool $operator): JsonResponse
    {
        $input = $request->validate(['after' => 'sometimes|integer|min:0', 'page_size' => 'sometimes|integer|min:1|max:100']);

        return DB::transaction(function () use ($request, $trip, $operator, $input): JsonResponse {
            // All extension writers take this lock first, giving this page a
            // consistent view of capture, cash, blocks, allocation and receipt.
            $lockedTrip = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $actorId = (int) $request->user()->id;
            $driver = (int) $lockedTrip->user_id === $actorId;
            if (! $operator && ! $driver && ! Passenger::query()->where('trip_id', $lockedTrip->id)->where('user_id', $actorId)->where('passenger_type', Passenger::TYPE_PASAJERO)->exists()) {
                return response()->json(['error' => ['code' => 'PAYMENT_VIEW_FORBIDDEN']], 403);
            }
            $audience = $operator ? 'operator' : ($driver ? 'driver' : 'rider');
            $query = Passenger::query()->where('trip_id', $lockedTrip->id)->where('passenger_type', Passenger::TYPE_PASAJERO);
            if ($audience === 'rider') {
                $query->where('user_id', $actorId);
            }
            $size = (int) ($input['page_size'] ?? 50);
            $bookings = $query->where('id', '>', (int) ($input['after'] ?? 0))->orderBy('id')->limit($size + 1)->get();
            $more = $bookings->count() > $size;
            $bookings = $bookings->take($size);
            $ids = $bookings->pluck('id');
            $fares = DB::table('mobility_fare_snapshots')->whereIn('booking_id', $ids)->get()->keyBy('booking_id');
            $attempts = DB::table('mobility_payment_attempts')->whereIn('booking_id', $ids)->orderBy('id')->get()->groupBy('booking_id');
            $blocks = DB::table('mobility_payment_blocks')->whereIn('booking_id', $ids)->where('active', true)->get()->groupBy('booking_id');
            $allocations = DB::table('mobility_payout_allocations')->whereIn('booking_id', $ids)->get()->keyBy('booking_id');
            $outbox = DB::table('mobility_payout_outbox')->whereIn('allocation_id', $allocations->pluck('id'))->get()->keyBy('allocation_id');
            $proofs = DB::table('mobility_dropoff_confirmations')->whereIn('booking_id', $ids)->get()->keyBy('booking_id');
            $cash = DB::table('mobility_cash_receipts')->whereIn('booking_id', $ids)->get()->keyBy('booking_id');
            $rows = $bookings->map(function ($booking) use ($audience, $fares, $attempts, $blocks, $allocations, $outbox, $proofs, $cash): array {
                $id = $booking->id;
                $fare = $fares->get($id);
                $payments = $attempts->get($id, collect());
                $activeBlocks = $blocks->get($id, collect());
                $allocation = $allocations->get($id);
                $command = $allocation ? $outbox->get($allocation->id) : null;
                $receipt = $cash->get($id);
                $cashState = $receipt ? ($receipt->disputed_at ? 'disputed' : ($receipt->rider_confirmed_at && $receipt->driver_confirmed_at ? 'confirmed' : 'awaiting_confirmation')) : null;
                $collectionState = $receipt ? 'cash_'.$cashState : ($payments->contains('status', 'captured') ? 'captured' : ($payments->isEmpty() ? 'not_requested' : 'pending'));
                if ($receipt && ! $payments->isEmpty()) {
                    $collectionState = 'reconciliation_required';
                }
                $releaseState = $allocation ? 'reconciliation_required' : 'not_allocated';
                if ($allocation && $command) {
                    if (! $activeBlocks->isEmpty() || $allocation->status === 'blocked' || $command->status === 'blocked') {
                        $releaseState = 'blocked';
                    } elseif ($allocation->status === 'eligible' && $command->status === 'pending') {
                        $releaseState = 'queued';
                    }
                }
                if ($receipt) {
                    $releaseState = $payments->isEmpty() ? 'not_applicable_cash' : 'reconciliation_required';
                }
                $row = [
                    'booking_id' => (int) $id, 'booking_state' => (int) $booking->request_state,
                    'quote' => $fare ? ['currency' => $fare->currency, 'gross_amount' => (int) $fare->gross_amount] : null,
                    'collection_state' => $collectionState, 'payment_blocked' => ! $activeBlocks->isEmpty(),
                    'provider_collections' => $payments->map(function ($payment) use ($audience): array {
                        $item = ['attempt_id' => (int) $payment->id, 'provider' => $payment->provider, 'environment' => $payment->environment,
                            'status' => $payment->status, 'currency' => $payment->currency, 'amount' => (int) $payment->amount];
                        if ($audience === 'operator') {
                            $item['order_reference'] = $payment->order_reference;
                            $item['payment_reference'] = $payment->payment_reference;
                        }

                        return $item;
                    })->values()->all(),
                    'rider_dropoff_acknowledged' => $proofs->has($id),
                    'cash_receipt' => $receipt ? ['receipt_id' => (int) $receipt->id, 'state' => $cashState,
                        'rider_acknowledged' => $receipt->rider_confirmed_at !== null, 'driver_acknowledged' => $receipt->driver_confirmed_at !== null,
                        'currency' => $receipt->currency, 'gross_amount' => (int) $receipt->gross_amount] : null,
                    'driver_payout_status' => $releaseState, 'settlement_status' => $receipt && $payments->isEmpty() ? 'not_applicable_cash' : 'not_verified',
                ];
                if ($audience !== 'rider') {
                    $row['rider_id'] = (int) $booking->user_id;
                    $row['quoted_driver_amount'] = $fare ? (int) $fare->driver_amount : null;
                    $row['allocated_driver_amount'] = $allocation ? (int) $allocation->amount : 0;
                    $row['platform_commission_amount'] = $fare ? (int) $fare->commission_amount : null;
                    $row['cash_commission_status'] = $receipt ? 'not_settled' : null;
                }
                if ($audience === 'operator') {
                    $row['block_reasons'] = $activeBlocks->pluck('reason')->sort()->values()->all();
                    $row['payout_command_state'] = $command?->status;
                }

                return $row;
            })->values()->all();

            return response()->json(['trip_id' => (int) $lockedTrip->id, 'audience' => $audience,
                'live_provider_dispatch_enabled' => false, 'bookings' => $rows,
                'pagination' => ['page_size' => $size, 'has_more' => $more,
                    'next_after' => $more ? (int) $bookings->last()->id : null]]);
        });
    }
}
