<?php

namespace STS\Mobility\Pilot;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use STS\Services\Logic\TripsManager;

/** Testing-only entrypoint; production trip creation stays the vendor API. */
final class PilotOfferController
{
    private function enabled(): void
    {
        if (! app()->environment('testing') || ! filter_var(getenv('MOBILITY_PILOT_UI_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            abort(404);
        }
    }

    public function routes(): JsonResponse
    {
        $this->enabled();
        $routes = [];
        foreach (PilotDemoRoutes::catalog() as $id => $route) {
            $routes[] = ['id' => $id, 'from' => $route['from'], 'to' => $route['to']];
        }

        return response()->json(['data' => $routes, 'synthetic_only' => true, 'timezone' => 'Asia/Kolkata']);
    }

    public function publish(Request $request, TripsManager $manager): JsonResponse
    {
        $this->enabled();
        $validated = $request->validate([
            'route_id' => 'required|string|in:demo-a-b,demo-b-a',
            'trip_date' => 'required|string|date_format:Y-m-d\\TH:i',
            'total_seats' => 'required|integer|min:1|max:5',
            'description' => 'nullable|string|max:600',
        ]);
        $routeId = $validated['route_id'];
        $timezone = 'Asia/Kolkata';
        $departure = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i', $validated['trip_date'], $timezone);
        if (! $departure || $departure->format('Y-m-d\\TH:i') !== $validated['trip_date']
            || $departure->lessThanOrEqualTo(now($timezone)->addMinutes(15))
            || $departure->greaterThan(now($timezone)->addDays(30))) {
            return response()->json(['error' => ['code' => 'PILOT_INVALID_DEPARTURE']], 422);
        }
        if (! PilotDemoRoutes::hasSeededRoute($routeId)) {
            return response()->json(['error' => ['code' => 'PILOT_ROUTE_NOT_SEEDED']], 503);
        }
        $route = PilotDemoRoutes::catalog()[$routeId];
        $trip = $manager->create($request->user(), [
            'is_passenger' => 0,
            // Supplied only by the server's cohort guard after exact approval.
            ...($request->attributes->has('mobility.approved_car_id')
                ? ['car_id' => $request->attributes->get('mobility.approved_car_id')] : []),
            'from_town' => $route['from'],
            'to_town' => $route['to'],
            'punto_partida' => $route['from'],
            'punto_llegada' => $route['to'],
            'trip_date' => $departure->format('Y-m-d H:i:s'),
            'total_seats' => (int) $validated['total_seats'],
            'friendship_type_id' => 2,
            'estimated_time' => sprintf('00:%02d', $route['minutes']),
            'distance' => $route['distance'],
            'co2' => 0,
            'description' => (string) ($validated['description'] ?? ''),
            'points' => PilotDemoRoutes::points($routeId),
        ]);
        if (! $trip) {
            return response()->json([
                'error' => ['code' => 'PILOT_TRIP_NOT_CREATED', 'validation' => $manager->getErrors()->toArray()],
            ], 422);
        }

        return response()->json([
            'data' => ['id' => (int) $trip->id, 'existing' => (bool) ($trip->existing ?? false)],
            'synthetic_only' => true,
        ], ($trip->existing ?? false) ? 200 : 201);
    }
}
