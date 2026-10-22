<?php

namespace STS\Mobility\Routing;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OfferController
{
    public function publish(Request $request, OfferService $service): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (! preg_match('/^[\x21-\x7e]{8,128}$/', $key)) {
            return response()->json(['error' => ['code' => 'IDEMPOTENCY_KEY_INVALID']], 422);
        }

        $payload = $request->all();
        $allowed = ['quote_id', 'car_id', 'trip_date', 'total_seats', 'description'];
        if (array_diff(array_keys($payload), $allowed) !== [] || array_diff(['quote_id', 'car_id', 'trip_date', 'total_seats'], array_keys($payload)) !== []) {
            return response()->json(['error' => ['code' => 'OFFER_INPUT_INVALID']], 422);
        }
        if (! is_string($payload['quote_id'] ?? null) || ! preg_match('/^[0-9a-f-]{36}$/i', $payload['quote_id'])) {
            return response()->json(['error' => ['code' => 'OFFER_INPUT_INVALID']], 422);
        }
        if ((! is_int($payload['car_id'] ?? null) && ! (is_string($payload['car_id'] ?? null) && ctype_digit($payload['car_id']))) || filter_var($payload['car_id'], FILTER_VALIDATE_INT) === false || (int) $payload['car_id'] < 1) {
            return response()->json(['error' => ['code' => 'OFFER_INPUT_INVALID']], 422);
        }
        if ((! is_int($payload['total_seats'] ?? null) && ! (is_string($payload['total_seats'] ?? null) && ctype_digit($payload['total_seats']))) || filter_var($payload['total_seats'], FILTER_VALIDATE_INT) === false || (int) $payload['total_seats'] < 1 || (int) $payload['total_seats'] > 5) {
            return response()->json(['error' => ['code' => 'OFFER_INPUT_INVALID']], 422);
        }
        if (isset($payload['description']) && (! is_string($payload['description']) || mb_strlen($payload['description']) > 600)) {
            return response()->json(['error' => ['code' => 'OFFER_INPUT_INVALID']], 422);
        }
        if (! is_string($payload['trip_date'])) {
            return response()->json(['error' => ['code' => 'DEPARTURE_INVALID']], 422);
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i', (string) ($payload['trip_date'] ?? ''), 'Asia/Kolkata');
        } catch (\Throwable) {
            $date = false;
        }
        if (! $date || $date->format('Y-m-d\\TH:i') !== ($payload['trip_date'] ?? '')) {
            return response()->json(['error' => ['code' => 'DEPARTURE_INVALID']], 422);
        }

        try {
            $result = $service->publish($request->user(), $payload, $key, $date);
        } catch (OfferException $error) {
            return response()->json(['error' => ['code' => $error->getCodeName()]], $error->status());
        } catch (\Throwable $error) {
            report($error);

            return response()->json(['error' => ['code' => 'OFFER_UNAVAILABLE']], 500);
        }

        return response()->json(['data' => $result['data']], $result['status']);
    }
}
