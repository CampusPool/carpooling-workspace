<?php

namespace STS\Mobility\Routing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Trust\CohortPolicy;

final class RouteQuoteService
{
    public function __construct(private readonly PickupPointCatalog $catalog, private readonly RouteProvider $provider) {}

    public function quote(int $userId, array $input): array
    {
        if (array_diff(array_keys($input), ['pickup_point_id', 'dropoff_point_id', 'stop_ids', 'vehicle_type'])) {
            throw new RoutingException('UNKNOWN_FIELDS');
        }
        $college = CohortPolicy::college();
        if (! $college) {
            throw new RoutingException('COHORT_NOT_CONFIGURED');
        }
        if (! CohortPolicy::approved($userId)) {
            throw new RoutingException('MEMBERSHIP_REQUIRED');
        }
        $vehicle = $input['vehicle_type'] ?? null;
        if (! is_string($vehicle) || ! in_array($vehicle, ['bike', 'car'], true)) {
            throw new RoutingException('INVALID_VEHICLE_TYPE');
        }
        $hasAliases = array_key_exists('pickup_point_id', $input) || array_key_exists('dropoff_point_id', $input);
        $hasStops = array_key_exists('stop_ids', $input);
        if ($hasAliases === $hasStops) {
            throw new RoutingException('INVALID_POINTS');
        }
        $ordered = $hasStops;
        if ($hasStops) {
            if (! is_array($input['stop_ids']) || ! array_is_list($input['stop_ids']) || count($input['stop_ids']) < 2 || count($input['stop_ids']) > 8) {
                throw new RoutingException('INVALID_POINTS');
            }
            $ids = array_map(fn (mixed $id): ?int => $this->positiveId($id), $input['stop_ids']);
            if (in_array(null, $ids, true) || count(array_unique($ids)) !== count($ids)) {
                throw new RoutingException('INVALID_POINTS');
            }
        } else {
            $pickupId = $this->positiveId($input['pickup_point_id'] ?? null);
            $dropoffId = $this->positiveId($input['dropoff_point_id'] ?? null);
            if ($pickupId === null || $dropoffId === null || $pickupId === $dropoffId) {
                throw new RoutingException('INVALID_POINTS');
            }
            $ids = [$pickupId, $dropoffId];
        }
        $rows = [];
        foreach ($ids as $id) {
            $row = $this->catalog->find($college, $id, $vehicle);
            if (! $row) {
                throw new RoutingException('PICKUP_POINT_UNAVAILABLE');
            }
            $rows[] = $row;
        }
        $nodeIds = array_map(fn (object $row): int => (int) $row->canonical_node_id, $rows);
        if (count(array_unique($nodeIds)) !== count($nodeIds)) {
            throw new RoutingException('INVALID_POINTS');
        }
        $points = array_map(fn (object $row): array => $this->canonical($row), $rows);
        $pricingVersion = config('mobility_routing.pricing_version');
        $rate = config('mobility_routing.'.$vehicle.'_minor_per_km');
        $synthetic = config('mobility_routing.synthetic_only', false);
        $expectedVersions = [
            'car' => config('mobility_routing.car_expected_data_version'),
            'bike' => config('mobility_routing.bike_expected_data_version'),
        ];
        if (! is_string($pricingVersion) || ! preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $pricingVersion)
            || (! is_int($rate) && ! (is_string($rate) && ctype_digit($rate)))
            || filter_var($rate, FILTER_VALIDATE_INT) === false || (int) $rate < 0 || (int) $rate > 100000
            || ! is_bool($synthetic) || ($synthetic && ! app()->environment('testing'))) {
            throw new RoutingException('ROUTING_MISCONFIGURED');
        }
        // Synthetic catalog coordinates cannot be promoted into live evidence by config.
        $synthetic = $synthetic || collect($rows)->contains(fn (object $row): bool => (bool) $row->synthetic_only);
        if (! $synthetic) {
            foreach (['car', 'bike'] as $mode) {
                if (! RouteDataVersion::valid($expectedVersions[$mode] ?? null, $mode)) {
                    throw new RoutingException('ROUTING_MISCONFIGURED');
                }
            }
            if ((string) $expectedVersions['car'] === (string) $expectedVersions['bike']) {
                throw new RoutingException('ROUTING_MISCONFIGURED');
            }
        }
        $legs = [];
        $distance = 0;
        $duration = 0;
        $providerName = null;
        $profile = null;
        $dataVersion = null;
        $missingDataVersion = false;
        foreach (array_keys(array_slice($points, 0, -1)) as $index) {
            $route = $this->provider->route($points[$index], $points[$index + 1], $vehicle);
            foreach (['distance_meters' => 100000, 'duration_seconds' => 86400] as $field => $limit) {
                if (! is_int($route[$field] ?? null) || $route[$field] < 1 || $route[$field] > $limit) {
                    throw new RoutingException('ROUTE_INVALID');
                }
            }
            if (! is_string($route['provider'] ?? null) || $route['provider'] === '' || ! is_string($route['profile'] ?? null) || $route['profile'] === '') {
                throw new RoutingException('ROUTE_INVALID');
            }
            $routeVersion = $route['data_version'] ?? null;
            if ($routeVersion === null) {
                $missingDataVersion = true;
                if (! $synthetic) {
                    throw new RoutingException('ROUTE_GRAPH_MISMATCH');
                }
            } elseif (! RouteDataVersion::valid($routeVersion, $vehicle) || (! $synthetic && ! hash_equals((string) $expectedVersions[$vehicle], (string) $routeVersion))) {
                throw new RoutingException('ROUTE_GRAPH_MISMATCH');
            }
            if ($dataVersion !== null && $routeVersion !== $dataVersion) {
                throw new RoutingException('ROUTE_GRAPH_MISMATCH');
            }
            $dataVersion ??= $routeVersion;
            if ($missingDataVersion && $dataVersion !== null) {
                throw new RoutingException('ROUTE_GRAPH_MISMATCH');
            }
            if (($providerName !== null && $providerName !== $route['provider']) || ($profile !== null && $profile !== $route['profile'])) {
                throw new RoutingException('ROUTE_INVALID');
            }
            $providerName ??= $route['provider'];
            $profile ??= $route['profile'];
            $amount = intdiv($route['distance_meters'] * (int) $rate + 999, 1000);
            $legs[] = ['from_index' => $index, 'to_index' => $index + 1, 'distance_meters' => $route['distance_meters'], 'duration_seconds' => $route['duration_seconds'], 'seat_price_minor' => $amount, 'provider' => $route['provider'], 'profile' => $route['profile'], 'data_version' => $routeVersion];
            $distance += $route['distance_meters'];
            $duration += $route['duration_seconds'];
        }
        if ($distance > 100000 || $duration > 86400 || array_sum(array_column($legs, 'seat_price_minor')) > 100000000) {
            throw new RoutingException('ROUTE_INVALID');
        }
        $amount = array_sum(array_column($legs, 'seat_price_minor'));
        $id = (string) Str::uuid();
        $expires = \Carbon\CarbonImmutable::now('UTC')->addMinutes(5);
        $snapshot = [
            'vehicle_type' => $vehicle, 'provider' => $providerName, 'profile' => $profile, 'data_version' => $dataVersion,
            'points' => $points, 'distance_meters' => $distance, 'duration_seconds' => $duration,
            'currency' => 'INR', 'seat_price_minor' => $amount, 'pricing_version' => $pricingVersion, 'synthetic_only' => $synthetic,
            'pickup_points' => array_map(fn (object $row): array => $this->catalog->snapshot($row), $rows),
        ];
        if ($ordered) {
            $snapshot['route_schema'] = 'ordered-legs-v1';
            $snapshot['legs'] = $legs;
        }
        $pointIds = $ids;
        DB::transaction(function () use ($id, $userId, $college, $snapshot, $expires, $vehicle, $pointIds): void {
            $actor = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $membership = DB::table('mobility_memberships')->where('user_id', $userId)->lockForUpdate()->first();
            if (! $actor || ! $actor->active || $actor->banned || ! $membership || $membership->status !== 'approved' || $membership->college_code !== $college) {
                throw new RoutingException('MEMBERSHIP_CHANGED');
            }
            $rows = DB::table('mobility_pickup_points')->whereIn('id', $pointIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $nodeIds = $rows->pluck('canonical_node_id')->unique()->sort()->values()->all();
            $nodes = DB::table('nodes_geo')->whereIn('id', $nodeIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($pointIds as $index => $pointId) {
                $row = $rows->get($pointId);
                $node = $row ? $nodes->get((int) $row->canonical_node_id) : null;
                $point = $snapshot['points'][$index];
                $original = $snapshot['pickup_points'][$index];
                $modes = $row ? json_decode($row->vehicle_modes, true) : null;
                if (! $row || ! $node || ! $row->active || ! $row->verified_at || $row->college_code !== $college
                    || ! is_array($modes) || ! in_array($vehicle, $modes, true) || (int) $row->version !== $original['version']
                    || (int) $row->canonical_node_id !== $original['canonical_node_id']
                    || (string) $row->name !== $point['address'] || (string) $node->name !== $point['address']
                    || (float) $row->lat !== $point['lat'] || (float) $row->lng !== $point['lng']
                    || abs((float) $node->lat - $point['lat']) > 0.0000001 || abs((float) $node->lng - $point['lng']) > 0.0000001
                    || ((bool) $row->synthetic_only && ! $snapshot['synthetic_only'])) {
                    throw new RoutingException('CATALOG_CHANGED');
                }
            }
            DB::table('mobility_route_quotes')->insert(['id' => $id, 'user_id' => $userId, 'college_code' => $college, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);
        });
        unset($snapshot['pickup_points']);

        return ['id' => $id, 'expires_at' => $expires->toISOString()] + $snapshot;
    }

    private function positiveId(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id !== false && $id > 0 ? $id : null;
    }

    private function canonical(object $row): array
    {
        $node = DB::table('nodes_geo')->where('id', $row->canonical_node_id)->first();
        if (! $node || (string) $node->name !== (string) $row->name
            || ! is_numeric($node->lat) || ! is_numeric($node->lng)
            || abs((float) $node->lat - (float) $row->lat) > 0.0000001 || abs((float) $node->lng - (float) $row->lng) > 0.0000001) {
            throw new RoutingException('CANONICAL_NODE_MISMATCH');
        }

        return ['address' => (string) $row->name, 'json_address' => ['id' => (int) $row->canonical_node_id, 'name' => (string) $row->name], 'lat' => (float) $row->lat, 'lng' => (float) $row->lng];
    }
}
