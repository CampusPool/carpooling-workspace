<?php

namespace STS\Mobility\Dispatch;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use STS\Mobility\Trust\CohortPolicy;
use STS\Mobility\Trust\DriverOnboardingPolicy;
use STS\Mobility\Pickup\PickupException;
use STS\Models\Car;
use STS\Models\User;

final class DispatchEligibility
{
    public static function member(int $id): void
    {
        try {
            $user = User::query()->whereKey($id)->lock('for share nowait')->first();
            $membership = DB::table('mobility_memberships')->where('user_id', $id)->lock('for share nowait')->first();
            self::checkMember($user, $membership, CohortPolicy::college());
        } catch (QueryException $error) {
            self::busyOrThrow($error);
        }
    }

    public static function driver(int $driverId, int $carId, ?string $vehicleType = null, ?int $pickupId = null): array
    {
        try {
            $user = User::query()->whereKey($driverId)->lock('for share nowait')->first();
            $membership = DB::table('mobility_memberships')->where('user_id', $driverId)->lock('for share nowait')->first();
            self::checkMember($user, $membership, CohortPolicy::college());
            $car = Car::query()->whereKey($carId)->where('user_id', $driverId)->lock('for share nowait')->first();
            $approval = DB::table('mobility_host_applications')->where('user_id', $driverId)->where('car_id', $carId)->where('status', 'approved')->lock('for share nowait')->first();
            if (! $car || ! $approval || ! DriverOnboardingPolicy::approvedApplication($approval) || ! $car->isComplete() || ! hash_equals((string) $approval->vehicle_fingerprint, CohortPolicy::vehicleFingerprint($car))) {
                self::fail('HOST_APPROVAL_REQUIRED', 403);
            }
        } catch (QueryException $error) {
            self::busyOrThrow($error);
        }
        if ($vehicleType !== null && $approval->vehicle_type !== $vehicleType) {
            self::fail('DRIVER_VEHICLE_CHANGED');
        }
        if ($pickupId !== null && ! self::catalogPoint($pickupId, (string) $approval->vehicle_type, true)) {
            self::fail('PICKUP_POINT_UNAVAILABLE');
        }

        return ['fingerprint' => (string) $approval->vehicle_fingerprint, 'vehicle_type' => (string) $approval->vehicle_type, 'car' => $car, 'user' => User::find($driverId)];
    }

    public static function current(array $terms, int $driverId, int $carId, bool $newAdmission = true): string
    {
        try {
            $ids = array_values(array_unique([(int) ($terms['rider_id'] ?? 0), $driverId]));
            sort($ids);
            $users = User::query()->whereIn('id', $ids)->orderBy('id')->lock('for share nowait')->get()->keyBy('id');
            if ($newAdmission && \STS\Mobility\Communications\PairBlockPolicy::blocked((int) ($terms['rider_id'] ?? 0), $driverId)) self::fail('PARTICIPANT_BLOCKED', 403);
            $members = DB::table('mobility_memberships')->whereIn('user_id', $ids)->orderBy('user_id')->lock('for share nowait')->get()->keyBy('user_id');
            $car = Car::query()->whereKey($carId)->where('user_id', $driverId)->lock('for share nowait')->first();
            $approval = DB::table('mobility_host_applications')->where('user_id', $driverId)->where('car_id', $carId)->lock('for share nowait')->first();
            foreach ($ids as $id) {
                self::checkMember($users->get($id), $members->get($id), $terms['college_code'] ?? CohortPolicy::college());
            }
            if (! $car || ! $car->isComplete() || ! $approval || ! DriverOnboardingPolicy::approvedApplication($approval) || $approval->vehicle_type !== ($terms['vehicle_type'] ?? null)
                || ! hash_equals((string) $approval->vehicle_fingerprint, CohortPolicy::vehicleFingerprint($car))) {
                self::fail('DRIVER_VEHICLE_CHANGED');
            }
            $stops = $terms['snapshot']['pickup_points'] ?? [];
            $pointRows = DB::table('mobility_pickup_points')->whereIn('id', array_map(fn (array $s): int => (int) $s['id'], $stops))->orderBy('id')->lock('for share nowait')->get()->keyBy('id');
            $nodeIds = array_values(array_unique(array_map(fn (array $s): int => (int) $s['canonical_node_id'], $stops)));
            sort($nodeIds);
            $nodes = DB::table('nodes_geo')->whereIn('id', $nodeIds)->orderBy('id')->lock('for share nowait')->get()->keyBy('id');
            foreach ($stops as $index => $stop) {
                $point = $terms['points'][$index];
                $row = $pointRows->get((int) $stop['id']);
                $node = $nodes->get((int) $stop['canonical_node_id']);
                $modes = $row ? json_decode((string) $row->vehicle_modes, true) : null;
                if (! $row || ! $node || ! $row->active || ! $row->verified_at || $row->college_code !== $terms['college_code']
                    || (int) $row->version !== (int) $stop['version'] || (int) $row->canonical_node_id !== (int) $stop['canonical_node_id']
                    || ! is_array($modes) || ! in_array($terms['vehicle_type'], $modes, true)
                    || ((bool) $row->synthetic_only && ! $terms['snapshot']['synthetic_only'])
                    || ((bool) $terms['snapshot']['synthetic_only'] && ! app()->environment('testing'))
                    || (string) $row->name !== (string) ($point['address'] ?? '') || (string) $node->name !== (string) ($point['address'] ?? '')
                    || abs((float) $row->lat - (float) $point['lat']) > .0000001 || abs((float) $row->lng - (float) $point['lng']) > .0000001
                    || abs((float) $node->lat - (float) $point['lat']) > .0000001 || abs((float) $node->lng - (float) $point['lng']) > .0000001) {
                    self::fail('MATCH_ROUTE_CHANGED');
                }
            }
        } catch (QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 3572) {
                self::fail('DISPATCH_ELIGIBILITY_BUSY');
            }
            throw $error;
        }

        return (string) $approval->vehicle_fingerprint;
    }

    /** Validate a private quote without ever touching catalog pickup points or nodes. */
    public static function privateCurrent(array $terms, int $driverId, int $carId): string
    {
        try {
            $ids = array_values(array_unique([(int) ($terms['rider_id'] ?? 0), $driverId]));
            sort($ids);
            $users = User::query()->whereIn('id', $ids)->orderBy('id')->lock('for share nowait')->get()->keyBy('id');
            if (\STS\Mobility\Communications\PairBlockPolicy::blocked((int) ($terms['rider_id'] ?? 0), $driverId)) self::fail('PARTICIPANT_BLOCKED', 403);
            $members = DB::table('mobility_memberships')->whereIn('user_id', $ids)->orderBy('user_id')->lock('for share nowait')->get()->keyBy('user_id');
            foreach ($ids as $id) self::checkMember($users->get($id), $members->get($id), $terms['college_code'] ?? CohortPolicy::college());
            $car = Car::query()->whereKey($carId)->where('user_id', $driverId)->lock('for share nowait')->first();
            $approval = DB::table('mobility_host_applications')->where('user_id', $driverId)->where('car_id', $carId)->lock('for share nowait')->first();
            if (! $car || ! $car->isComplete() || ! $approval || ! DriverOnboardingPolicy::approvedApplication($approval)
                || $approval->vehicle_type !== ($terms['vehicle_type'] ?? null)
                || ! hash_equals((string) $approval->vehicle_fingerprint, CohortPolicy::vehicleFingerprint($car))) {
                self::fail('DRIVER_VEHICLE_CHANGED');
            }
            $private = DB::table('mobility_private_journey_quotes')->where('id', $terms['journey_quote_id'] ?? '')->lock('for share nowait')->first();
            $selection = $private ? DB::table('mobility_pickup_selections')->where('id', $private->selection_id)->lock('for share nowait')->first() : null;
            $plan = $selection ? DB::table('mobility_pickup_plans')->where('id', $selection->plan_id)->lock('for share nowait')->first() : null;
            $intent = $selection ? DB::table('mobility_pickup_intents')->where('id', $selection->intent_id)->lock('for share nowait')->first() : null;
            $preview = $selection ? DB::table('mobility_pickup_previews')->where('id', $selection->preview_id)->lock('for share nowait')->first() : null;
            if (! $private || $private->state !== 'accepted' || (int) $private->driver_id !== $driverId || (int) $private->rider_id !== (int) $terms['rider_id']
                || (int) $private->source_selection_version !== (int) $terms['private_snapshot']['selection_version']
                || ! hash_equals((string) $private->source_terms_hash, (string) ($terms['private_snapshot']['selection_terms_hash'] ?? ''))
                || ! $selection || $selection->state !== 'confirmed' || (int) $selection->version !== (int) $private->source_selection_version
                || ! hash_equals((string) $selection->terms_hash, (string) $private->source_terms_hash)
                || ! $plan || ! $intent || ! $preview
                || (int) $plan->car_id !== $carId
                || (int) $intent->driver_id !== $driverId || (int) $intent->rider_id !== (int) $terms['rider_id']) {
                self::fail('PRIVATE_SOURCE_CHANGED');
            }
            $endpointIds = [(string) $private->origin_endpoint_id, (string) $private->destination_endpoint_id]; sort($endpointIds, SORT_STRING);
            $endpoints = DB::table('mobility_private_endpoints')->whereIn('id', $endpointIds)->orderBy('id')->lock('for share nowait')->get()->keyBy('id');
            foreach ([$private->origin_endpoint_id, $private->destination_endpoint_id] as $endpointId) {
                $endpoint = $endpoints->get($endpointId);
                if (! $endpoint || $endpoint->redacted_at || now('UTC')->gte($endpoint->expires_at)) self::fail('PRIVATE_ENDPOINT_EXPIRED', 410);
            }
            try {
                $rider = User::findOrFail((int) $terms['rider_id']);
                app(\STS\Mobility\Journeys\PrivateJourneyQuoteService::class)->show($rider, (string) $private->id);
                $resolver = app(\STS\Mobility\Journeys\PrivateEndpointResolver::class);
                foreach ([['id' => $private->origin_endpoint_id, 'hash' => $private->origin_endpoint_hash], ['id' => $private->destination_endpoint_id, 'hash' => $private->destination_endpoint_hash]] as $binding) {
                    $resolved = $resolver->resolve($rider, (string) $binding['id']);
                    if (! hash_equals((string) $binding['hash'], (string) $resolved['hash'])) self::fail('PRIVATE_ENDPOINT_INTEGRITY');
                }
            } catch (PickupException $error) {
                self::fail($error->machineCode, $error->httpStatus);
            }
            return (string) $approval->vehicle_fingerprint;
        } catch (QueryException $error) {
            self::busyOrThrow($error);
        }
    }

    /** Post-reservation driver gate. Accepted private endpoint expiry is not a reason to rewrite a canonical trip. */
    public static function privateDriverCurrent(array $terms, int $driverId, int $carId): string
    {
        try {
            $ids = array_values(array_unique([(int) ($terms['rider_id'] ?? 0), $driverId])); sort($ids);
            $users = User::query()->whereIn('id', $ids)->orderBy('id')->lock('for share nowait')->get()->keyBy('id');
            $members = DB::table('mobility_memberships')->whereIn('user_id', $ids)->orderBy('user_id')->lock('for share nowait')->get()->keyBy('user_id');
            foreach ($ids as $id) self::checkMember($users->get($id), $members->get($id), $terms['college_code'] ?? CohortPolicy::college());
            $car = Car::query()->whereKey($carId)->where('user_id', $driverId)->lock('for share nowait')->first();
            $approval = DB::table('mobility_host_applications')->where('user_id', $driverId)->where('car_id', $carId)->lock('for share nowait')->first();
            if (! $car || ! $car->isComplete() || ! $approval || ! DriverOnboardingPolicy::approvedApplication($approval) || $approval->vehicle_type !== ($terms['vehicle_type'] ?? null) || ! hash_equals((string) $approval->vehicle_fingerprint, CohortPolicy::vehicleFingerprint($car))) self::fail('DRIVER_VEHICLE_CHANGED');
            return (string) $approval->vehicle_fingerprint;
        } catch (QueryException $error) { self::busyOrThrow($error); }
    }

    private static function catalogPoint(int $id, string $vehicle, bool $lock = false): bool
    {
        $query = DB::table('mobility_pickup_points')->where('id', $id);
        if ($lock) {
            $query->lock('for share nowait');
        }
        $row = $query->first();
        $modes = $row ? json_decode((string) $row->vehicle_modes, true) : null;

        return $row && $row->active && $row->verified_at && $row->college_code === CohortPolicy::college() && is_array($modes) && in_array($vehicle, $modes, true);
    }

    private static function checkMember(?object $user, ?object $membership, ?string $college): void
    {
        if (! CohortPolicy::activeUser($user) || ! $membership || $membership->status !== 'approved' || $membership->college_code !== $college) {
            self::fail('MEMBERSHIP_REQUIRED', 403);
        }
    }

    private static function busyOrThrow(QueryException $error): never
    {
        if ((int) ($error->errorInfo[1] ?? 0) === 3572) {
            self::fail('DISPATCH_ELIGIBILITY_BUSY');
        }
        throw $error;
    }

    private static function fail(string $code, int $status = 409): never
    {
        if (class_exists(DispatchException::class)) {
            throw new DispatchException($code, $status);
        }
        throw new \RuntimeException($code);
    }
}
