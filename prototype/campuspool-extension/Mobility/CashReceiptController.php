<?php

namespace STS\Mobility;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use STS\Models\Passenger;
use STS\Models\Trip;

final class CashReceiptController
{
    public function store(Request $request, Trip $trip, Passenger $booking, CashReceiptService $service): JsonResponse
    {
        $action = $request->input('action');
        if (! is_string($action) || ! in_array($action, ['rider_paid', 'driver_received', 'dispute'], true)) {
            return response()->json(['error' => ['code' => 'INVALID_CASH_ACTION']], 422);
        }
        try {
            return response()->json($service->record($trip, $booking, $request->user(), $action, (string) $request->header('Idempotency-Key', '')));
        } catch (CashReceiptException $e) {
            $status = in_array($e->errorCode, ['CASH_ACTOR_FORBIDDEN', 'CASH_RIDER_REQUIRED', 'CASH_DRIVER_REQUIRED'], true) ? 403 : (in_array($e->errorCode, ['IDEMPOTENCY_CONFLICT', 'CASH_PROVIDER_CONFLICT', 'CASH_ACTION_ALREADY_RECORDED'], true) ? 409 : 422);

            return response()->json(['error' => ['code' => $e->errorCode]], $status);
        }
    }
}
