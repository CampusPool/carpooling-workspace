<?php

namespace STS\Mobility\Tracking;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TrackingController
{
    public function session(Request $request, int $trip, TrackingService $service): JsonResponse
    {
        try { $this->fields($request, ['consent']); $key = $this->key($request); $body = $request->validate(['consent' => 'required|boolean']); return $this->privateResponse(response()->json(['data' => $service->startSession($trip, $request->user(), $key, (bool) $body['consent'])])); } catch (TrackingException $e) { return $this->error($e); }
    }

    public function location(Request $request, int $trip, TrackingService $service): JsonResponse
    {
        try { $this->fields($request, ['session_id', 'sequence', 'lat', 'lng', 'accuracy_meters', 'recorded_at']); $key = $this->key($request); $body = $request->validate(['session_id' => 'required|uuid', 'sequence' => 'required|integer|min:1|max:2147483647', 'lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180', 'accuracy_meters' => 'required|numeric|gt:0|lte:500', 'recorded_at' => 'required|date_format:Y-m-d\\TH:i:s.v\\Z']); return $this->privateResponse(response()->json(['data' => $service->ingest($trip, $request->user(), $body['session_id'], (int) $body['sequence'], (float) $body['lat'], (float) $body['lng'], (float) $body['accuracy_meters'], CarbonImmutable::createFromFormat('Y-m-d\\TH:i:s.v\\Z', $body['recorded_at'], 'UTC'), $key)])); } catch (TrackingException $e) { return $this->error($e); }
    }

    public function show(Request $request, int $trip, TrackingService $service): JsonResponse
    { try { return $this->privateResponse(response()->json(['data' => $service->show($trip, $request->user())])); } catch (TrackingException $e) { return $this->error($e); } }

    public function stop(Request $request, int $trip, TrackingService $service): JsonResponse
    {
        try {
            $this->fields($request, ['session_id']);
            $body = $request->validate(['session_id' => 'nullable|uuid']);
            return $this->privateResponse(response()->json([
                'data' => $service->stop($trip, $request->user(), $this->key($request), $body['session_id'] ?? null),
            ]));
        } catch (TrackingException $e) {
            return $this->error($e);
        }
    }

    private function fields(Request $request, array $allowed): void
    { if (array_diff(array_keys($request->all()), $allowed)) throw new TrackingException('UNKNOWN_FIELDS', 422); }

    private function key(Request $request): string
    { $key = (string) $request->header('Idempotency-Key', ''); if ($key === '' || strlen($key) > 100 || ! preg_match('/^[\\x21-\\x7E]+$/D', $key)) throw new TrackingException('INVALID_IDEMPOTENCY_KEY', 422); return $key; }
    private function privateResponse(JsonResponse $response): JsonResponse
    { $response->setPrivate(); $response->headers->set('Cache-Control', 'private, no-store, max-age=0'); $response->headers->set('X-Content-Type-Options', 'nosniff'); return $response; }
    private function error(TrackingException $e): JsonResponse
    { return $this->privateResponse(response()->json(['error' => ['code' => $e->errorCode]], $e->httpStatus)); }
}
