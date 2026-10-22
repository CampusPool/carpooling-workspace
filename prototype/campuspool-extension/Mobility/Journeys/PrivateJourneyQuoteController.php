<?php

namespace STS\Mobility\Journeys;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use STS\Mobility\Pickup\PickupException;

final class PrivateJourneyQuoteController
{
    public function prepare(Request $r, PrivateJourneyQuoteService $service): JsonResponse { return $this->call(fn () => $service->prepare($r->user(), $r->all(), (string) $r->header('Idempotency-Key', ''))); }
    public function show(Request $r, string $id, PrivateJourneyQuoteService $service): JsonResponse { return $this->call(fn () => $service->show($r->user(), $id)); }
    public function action(Request $r, string $id, string $kind, PrivateJourneyQuoteService $service): JsonResponse { return $this->call(fn () => $service->action($r->user(), $id, $kind, $r->all(), (string) $r->header('Idempotency-Key', ''))); }
    private function call(callable $operation): JsonResponse { try { $result = $operation(); return response()->json(['data' => $result['data'] ?? $result], (int) ($result['status'] ?? 200))->header('Cache-Control', 'private, no-store, max-age=0'); } catch (PickupException $e) { return response()->json(['error' => ['code' => $e->machineCode]], $e->httpStatus)->header('Cache-Control', 'private, no-store, max-age=0'); } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::error('private_quote_failed', ['code' => 'PRIVATE_QUOTE_UNAVAILABLE']); return response()->json(['error' => ['code' => 'PRIVATE_QUOTE_UNAVAILABLE']], 500)->header('Cache-Control', 'private, no-store, max-age=0'); } }
}
