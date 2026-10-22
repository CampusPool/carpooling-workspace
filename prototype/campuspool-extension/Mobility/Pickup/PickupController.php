<?php

namespace STS\Mobility\Pickup;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PickupController
{
    public function __construct(private readonly PickupPreviewService $service) {}

    public function planStore(Request $r): JsonResponse { return $this->call(fn () => $this->service->createPlan($r->user(), $r->all(), (string) $r->header('Idempotency-Key', ''))); }
    public function planUpdate(Request $r, string $id): JsonResponse { return $this->call(fn () => $this->service->updatePlan($r->user(), $id, $r->all())); }
    public function planShow(Request $r, string $id): JsonResponse { return $this->call(fn () => $this->service->plan($r->user(), $id)); }
    public function intentStore(Request $r): JsonResponse { return $this->call(fn () => $this->service->createIntent($r->user(), $r->all(), (string) $r->header('Idempotency-Key', ''))); }
    public function intentUpdate(Request $r, string $id): JsonResponse { return $this->call(fn () => $this->service->updateIntent($r->user(), $id, $r->all())); }
    public function intentShow(Request $r, string $id): JsonResponse { return $this->call(fn () => $this->service->intent($r->user(), $id)); }
    public function previewStore(Request $r, string $id): JsonResponse { return $this->call(fn () => $this->service->preview($r->user(), $id, $r->all(), (string) $r->header('Idempotency-Key', ''))); }
    public function previewShow(Request $r, string $id): JsonResponse { return $this->call(fn () => $this->service->showPreview($r->user(), $id)); }

    private function call(callable $operation): JsonResponse
    {
        try {
            $result = $operation();
            $status = (int) ($result['status'] ?? 200);
            $body = ['data' => $result['data'] ?? $result];
            return response()->json($body, $status)->header('Cache-Control', 'private, no-store, max-age=0');
        } catch (PickupException $e) {
            return response()->json(['error' => ['code' => $e->machineCode]], $e->httpStatus)->header('Cache-Control', 'private, no-store, max-age=0');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('pickup_operation_failed', ['code' => 'PICKUP_UNAVAILABLE']);
            return response()->json(['error' => ['code' => 'PICKUP_UNAVAILABLE']], 500)->header('Cache-Control', 'private, no-store, max-age=0');
        }
    }
}
