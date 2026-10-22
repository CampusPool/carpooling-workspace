<?php

namespace STS\Mobility\Pickup;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PickupSelectionController
{
    public function __construct(private readonly PickupSelectionService $service) {}
    public function store(Request $r, string $id): JsonResponse { return $this->call(fn () => $this->service->select($r->user(), $id, $r->all(), (string) $r->header('Idempotency-Key', ''))); }
    public function show(Request $r, string $id): JsonResponse { return $this->call(fn () => $this->service->show($r->user(), $id)); }
    public function confirm(Request $r, string $id): JsonResponse { return $this->call(fn () => $this->service->action($r->user(), $id, 'confirm', $r->all(), (string) $r->header('Idempotency-Key', ''))); }
    public function decline(Request $r, string $id): JsonResponse { return $this->call(fn () => $this->service->action($r->user(), $id, 'decline', $r->all(), (string) $r->header('Idempotency-Key', ''))); }
    public function revoke(Request $r, string $id): JsonResponse { return $this->call(fn () => $this->service->action($r->user(), $id, 'revoke', $r->all(), (string) $r->header('Idempotency-Key', ''))); }
    private function call(callable $operation): JsonResponse { try { $result = $operation(); return response()->json(['data' => $result['data'] ?? $result], (int) ($result['status'] ?? 200))->header('Cache-Control', 'private, no-store, max-age=0'); } catch (PickupException $e) { return response()->json(['error' => ['code' => $e->machineCode]], $e->httpStatus)->header('Cache-Control', 'private, no-store, max-age=0'); } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::error('pickup_selection_failed', ['code' => 'PICKUP_UNAVAILABLE']); return response()->json(['error' => ['code' => 'PICKUP_UNAVAILABLE']], 500)->header('Cache-Control', 'private, no-store, max-age=0'); } }
}
