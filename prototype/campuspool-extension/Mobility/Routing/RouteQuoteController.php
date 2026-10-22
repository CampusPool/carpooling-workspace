<?php

namespace STS\Mobility\Routing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use STS\Mobility\Trust\CohortPolicy;

final class RouteQuoteController
{
    public function __construct(private readonly PickupPointCatalog $catalog, private readonly RouteQuoteService $quotes) {}

    public function catalog(Request $request): JsonResponse
    {
        if ($error = $this->admission($request)) {
            return $error;
        }
        $size = $request->query('page_size', 30);
        $after = $request->query('after');
        $vehicle = $request->query('vehicle_type');
        foreach ([$size, ...($after === null ? [] : [$after])] as $value) {
            if ((! is_int($value) && ! (is_string($value) && ctype_digit($value))) || filter_var($value, FILTER_VALIDATE_INT) === false) {
                return $this->error('INVALID_CATALOG_PAGE', 422);
            }
        }
        if ($vehicle !== null && (! is_string($vehicle) || ! in_array($vehicle, ['bike', 'car'], true))) {
            return $this->error('INVALID_CATALOG_PAGE', 422);
        }
        try {
            $page = $this->catalog->page(CohortPolicy::college(), (int) $size, $after === null ? null : (int) $after, $vehicle);

            return response()->json(['data' => $page['points'], 'next_cursor' => $page['next_cursor']]);
        } catch (RoutingException $e) {
            return $this->error($e->machineCode, 422);
        }
    }

    public function quote(Request $request): JsonResponse
    {
        if ($error = $this->admission($request)) {
            return $error;
        }
        $payload = $request->all();
        if (array_diff(array_keys($payload), ['pickup_point_id', 'dropoff_point_id', 'stop_ids', 'vehicle_type'])) {
            return $this->error('UNKNOWN_FIELDS', 422);
        }
        try {
            return response()->json(['data' => $this->quotes->quote((int) $request->user()->id, $payload)], 201);
        } catch (RoutingException $e) {
            $status = match ($e->machineCode) {
                'ROUTING_MISCONFIGURED', 'COHORT_NOT_CONFIGURED', 'CANONICAL_NODE_MISMATCH' => 503,
                'MEMBERSHIP_REQUIRED', 'MEMBERSHIP_CHANGED' => 403,
                'ROUTE_PROVIDER_UNAVAILABLE' => 502,
                default => 422,
            };

            return $this->error($e->machineCode, $status);
        }
    }

    private function admission(Request $request): ?JsonResponse
    {
        if (! CohortPolicy::college()) {
            return $this->error('COHORT_NOT_CONFIGURED', 503);
        }
        if (! CohortPolicy::activeUser($request->user())) {
            return $this->error('ACCOUNT_RESTRICTED', 403);
        }

        return CohortPolicy::approved((int) $request->user()->id) ? null : $this->error('MEMBERSHIP_REQUIRED', 403);
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code]], $status);
    }
}
