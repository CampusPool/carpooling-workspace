<?php

namespace STS\Mobility\Pilot;

use STS\Models\RouteCache;

/**
 * TEST-ONLY synthetic route fixtures. Not surveyed stops, road routes or fare quotes.
 */
final class PilotDemoRoutes
{
    public static function catalog(): array
    {
        return [
            'demo-a-b' => ['from' => 'Synthetic Stop A', 'to' => 'Synthetic Stop B', 'distance' => 3.5, 'minutes' => 15],
            'demo-b-a' => ['from' => 'Synthetic Stop B', 'to' => 'Synthetic Stop A', 'distance' => 3.5, 'minutes' => 15],
        ];
    }

    public static function points(string $route): ?array
    {
        if (! array_key_exists($route, self::catalog())) {
            return null;
        }
        $a = ['address' => 'Synthetic Stop A', 'json_address' => ['name' => 'Synthetic Stop A', 'state' => 'Test'], 'lat' => 10.0, 'lng' => 20.0];
        $b = ['address' => 'Synthetic Stop B', 'json_address' => ['name' => 'Synthetic Stop B', 'state' => 'Test'], 'lat' => 10.0, 'lng' => 20.03];
        return $route === 'demo-a-b' ? [$a, $b] : [$b, $a];
    }

    public static function hasSeededRoute(string $route): bool
    {
        $points = self::points($route);
        if ($points === null) {
            return false;
        }
        $cache = RouteCache::query()->where('hashed_points', hash('sha256', json_encode($points)))
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->first();
        return $cache !== null && ($cache->route_data['status'] ?? false) === true
            && ($cache->route_data['message'] ?? '') === 'SYNTHETIC_TEST_ROUTE_ONLY';
    }

    /** Only called by the explicitly gated local CLI seed or tests. */
    public static function seed(): void
    {
        foreach (self::catalog() as $id => $route) {
            $points = self::points($id);
            RouteCache::query()->updateOrCreate(
                ['hashed_points' => hash('sha256', json_encode($points))],
                ['points' => $points, 'route_data' => [
                    'status' => true,
                    'data' => [
                        'distance' => (int) ($route['distance'] * 1000),
                        'duration' => $route['minutes'] * 60,
                        'recommended_trip_price_cents' => 0,
                    ],
                    'message' => 'SYNTHETIC_TEST_ROUTE_ONLY',
                ], 'expires_at' => now()->addDays(30)]
            );
        }
    }
}
