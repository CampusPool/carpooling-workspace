<?php

namespace STS\Mobility;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only bounded cross-trip cash reconciliation facts for administrators. */
final class OperatorCashQueueController
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! (bool) $actor->is_admin) {
            return response()->json(['error' => ['code' => 'OPERATOR_FORBIDDEN']], 403);
        }

        $input = $request->validate([
            'after' => ['sometimes', 'integer', 'min:0'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $after = (int) ($input['after'] ?? 0);
        $pageSize = (int) ($input['page_size'] ?? 50);

        // Keep this as one bounded statement: provider history is represented
        // only by a correlated existence check, never loaded into the queue.
        $rows = DB::table('mobility_cash_receipts as cash')
            ->leftJoin('trip_passengers as booking', 'booking.id', '=', 'cash.booking_id')
            ->leftJoin('trips as trip', 'trip.id', '=', 'cash.trip_id')
            ->where('cash.id', '>', $after)
            ->where(function ($query): void {
                $query->whereNotNull('cash.disputed_at')
                    ->orWhereExists(function ($provider): void {
                        $provider->select(DB::raw('1'))
                            ->from('mobility_payment_attempts as attempt')
                            ->whereColumn('attempt.booking_id', 'cash.booking_id');
                    });
            })
            ->orderBy('cash.id')
            ->limit($pageSize + 1)
            ->select([
                'cash.id as receipt_id',
                'cash.trip_id',
                'cash.booking_id',
                'cash.rider_id',
                'cash.driver_id',
                'cash.currency',
                'cash.gross_amount',
                'cash.commission_amount',
                'cash.rider_confirmed_at',
                'cash.driver_confirmed_at',
                'cash.disputed_at',
                'booking.request_state as booking_state',
                'trip.state as trip_state',
            ])
            ->selectRaw('EXISTS (SELECT 1 FROM mobility_payment_attempts provider_attempt WHERE provider_attempt.booking_id = cash.booking_id) AS has_provider_attempt')
            ->get();

        $hasMore = $rows->count() > $pageSize;
        $delivered = $rows->take($pageSize)->values();
        $data = $delivered->map(static function (object $row): array {
            $providerAttempt = (int) $row->has_provider_attempt === 1;
            $disputed = $row->disputed_at !== null;

            return [
                'receipt_id' => (int) $row->receipt_id,
                'trip_id' => (int) $row->trip_id,
                'booking_id' => (int) $row->booking_id,
                'rider_id' => (int) $row->rider_id,
                'driver_id' => (int) $row->driver_id,
                'currency' => $row->currency,
                'gross_amount' => (int) $row->gross_amount,
                'commission_amount' => (int) $row->commission_amount,
                'rider_acknowledged' => $row->rider_confirmed_at !== null,
                'driver_acknowledged' => $row->driver_confirmed_at !== null,
                'has_provider_attempt' => $providerAttempt,
                'disputed_at' => $row->disputed_at,
                'reconciliation_state' => $providerAttempt ? 'channel_conflict' : 'cash_disputed',
                'cash_state' => $disputed ? 'disputed' : (($row->rider_confirmed_at !== null && $row->driver_confirmed_at !== null) ? 'confirmed' : 'awaiting_confirmation'),
                'booking_state' => $row->booking_state === null ? null : (int) $row->booking_state,
                'trip_state' => $row->trip_state,
                'cash_commission_status' => 'not_settled',
                'settlement_status' => $providerAttempt ? 'not_verified' : 'not_applicable_cash',
            ];
        })->all();

        return response()->json([
            'audience' => 'operator',
            'live_provider_dispatch_enabled' => false,
            'data' => $data,
            'pagination' => [
                'page_size' => $pageSize,
                'has_more' => $hasMore,
                'next_after' => $hasMore ? (int) $delivered->last()->receipt_id : null,
            ],
        ]);
    }
}
