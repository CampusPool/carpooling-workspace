<?php

namespace STS\Mobility\Segments;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Trust\CohortPolicy;
use STS\Mobility\Trust\DriverOnboardingPolicy;
use STS\Models\Car;
use STS\Models\Trip;

final class RouteTerms
{
    public static function forTrip(int $tripId, bool $currentRead = false): ?array
    {
        $query = DB::table('mobility_pool_publications as publication')->join('mobility_route_quotes as quote', 'quote.id', '=', 'publication.quote_id')
            ->where('publication.trip_id', $tripId)->select('quote.*', 'publication.trip_id', 'publication.user_id as host_id');
        $row = $currentRead ? $query->lock('for share nowait')->first() : $query->first();
        if (! $row) {
            return null;
        }
        $snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $points = $snapshot['points'] ?? [];
        $stops = $snapshot['pickup_points'] ?? [];
        if ((int) $row->consumed_trip_id !== $tripId || (int) $row->user_id !== (int) $row->host_id || ! is_array($points) || ! array_is_list($points) || count($points) < 2 || count($points) > 8 || ! is_array($stops) || ! array_is_list($stops) || count($stops) !== count($points)
            || count(array_unique(array_column($stops, 'id'))) !== count($stops) || count(array_unique(array_column($stops, 'canonical_node_id'))) !== count($stops)
            || ($snapshot['currency'] ?? null) !== 'INR' || ! in_array($snapshot['vehicle_type'] ?? null, ['car', 'bike'], true)) {
            throw new SegmentException('MATCH_ROUTE_CHANGED');
        }
        $legs = $snapshot['legs'] ?? null;
        if ($legs === null && count($points) === 2) {
            $legs = [['from_index' => 0, 'to_index' => 1, 'distance_meters' => $snapshot['distance_meters'] ?? null, 'duration_seconds' => $snapshot['duration_seconds'] ?? null, 'seat_price_minor' => $snapshot['seat_price_minor'] ?? null, 'provider' => $snapshot['provider'] ?? null, 'profile' => $snapshot['profile'] ?? null]];
        }
        if (! is_array($legs) || ! array_is_list($legs) || count($legs) !== count($points) - 1) {
            throw new SegmentException('MATCH_ROUTE_CHANGED');
        }
        foreach ($legs as $index => $leg) {
            if (($leg['from_index'] ?? null) !== $index || ($leg['to_index'] ?? null) !== $index + 1 || ! is_int($leg['seat_price_minor'] ?? null) || $leg['seat_price_minor'] < 0 || ! is_int($leg['distance_meters'] ?? null) || $leg['distance_meters'] < 1 || ! is_int($leg['duration_seconds'] ?? null) || $leg['duration_seconds'] < 1 || ($leg['provider'] ?? null) !== ($snapshot['provider'] ?? null) || ($leg['profile'] ?? null) !== ($snapshot['profile'] ?? null)) {
                throw new SegmentException('MATCH_ROUTE_CHANGED');
            }
        }
        foreach (['seat_price_minor', 'distance_meters', 'duration_seconds'] as $field) {
            if (! is_int($snapshot[$field] ?? null) || array_sum(array_column($legs, $field)) !== $snapshot[$field]) {
                throw new SegmentException('MATCH_ROUTE_CHANGED');
            }
        }

        return ['quote_id' => (string) $row->id, 'college_code' => $row->college_code, 'host_id' => (int) $row->host_id, 'hash' => hash('sha256', (string) $row->snapshot), 'snapshot' => $snapshot, 'points' => $points, 'stops' => $stops, 'legs' => $legs];
    }

    /** Shared NOWAIT eligibility locks avoid a Trip -> owner blocking inversion. */
    public static function current(Trip $trip, array $terms, ?int $riderId = null): string
    {
        $read = static fn ($query) => $riderId === null ? $query->get() : $query->lock('for share nowait')->get();
        try {
            $ids = array_values(array_unique(array_filter([(int) $trip->user_id, $riderId])));
            sort($ids);
            $users = $read(\STS\Models\User::query()->whereIn('id', $ids)->orderBy('id'))->keyBy('id');
            $members = $read(DB::table('mobility_memberships')->whereIn('user_id', $ids)->orderBy('user_id'))->keyBy('user_id');
            $car = $read(Car::query()->whereKey($trip->car_id))->first();
            $approval = $read(DB::table('mobility_host_applications')->where('user_id', $trip->user_id)->where('car_id', $trip->car_id))->first();
            foreach ($ids as $id) {
                $user = $users->get($id);
                $member = $members->get($id);
                if (! CohortPolicy::activeUser($user) || ! $member || $member->status !== 'approved' || $member->college_code !== CohortPolicy::college()) {
                    throw new SegmentException('MEMBERSHIP_REQUIRED', 403);
                }
            }
            if ($terms['host_id'] !== (int) $trip->user_id || $terms['college_code'] !== CohortPolicy::college() || ! $car || (int) $car->user_id !== (int) $trip->user_id || ! $car->isComplete() || ! $approval || ! DriverOnboardingPolicy::approvedApplication($approval) || $approval->vehicle_type !== $terms['snapshot']['vehicle_type'] || ! hash_equals($approval->vehicle_fingerprint, CohortPolicy::vehicleFingerprint($car))) {
                throw new SegmentException('MATCH_VEHICLE_CHANGED');
            }
            $rows = $read(DB::table('mobility_pickup_points')->whereIn('id', array_column($terms['stops'], 'id'))->orderBy('id'))->keyBy('id');
            $nodes = $read(DB::table('nodes_geo')->whereIn('id', array_column($terms['stops'], 'canonical_node_id'))->orderBy('id'))->keyBy('id');
            foreach ($terms['stops'] as $i => $stop) {
                $row = $rows->get($stop['id']);
                $point = $terms['points'][$i];
                $node = $nodes->get($stop['canonical_node_id']);
                $modes = $row ? json_decode($row->vehicle_modes, true) : null;
                if (! $row || ! $node || ! $row->active || ! $row->verified_at || $row->college_code !== $terms['college_code'] || (int) $row->version !== (int) $stop['version'] || (int) $row->canonical_node_id !== (int) $stop['canonical_node_id'] || ! is_array($modes) || ! in_array($terms['snapshot']['vehicle_type'], $modes, true)
                    || (string) $row->name !== (string) ($point['address'] ?? '') || (string) $node->name !== (string) ($point['address'] ?? '') || abs((float) $row->lat - (float) $point['lat']) > .0000001 || abs((float) $row->lng - (float) $point['lng']) > .0000001 || abs((float) $node->lat - (float) $point['lat']) > .0000001 || abs((float) $node->lng - (float) $point['lng']) > .0000001
                    || ((bool) $row->synthetic_only && ! $terms['snapshot']['synthetic_only']) || ($terms['snapshot']['synthetic_only'] && ! app()->environment('testing'))) {
                    throw new SegmentException('MATCH_ROUTE_CHANGED');
                }
            }
        } catch (\Illuminate\Database\QueryException $error) {
            if (($error->errorInfo[1] ?? null) === 3572) {
                throw new SegmentException('MATCH_ELIGIBILITY_BUSY');
            }
            throw $error;
        }
        $canonical = DB::table('trips_points')->where('trip_id', $trip->id)->orderBy('id')->get();
        if ($canonical->count() !== count($terms['points'])) {
            throw new SegmentException('MATCH_ROUTE_CHANGED');
        }
        foreach ($canonical as $index => $point) {
            if ((string) $point->address !== $terms['points'][$index]['address'] || abs((float) $point->lat - $terms['points'][$index]['lat']) > .0000001 || abs((float) $point->lng - $terms['points'][$index]['lng']) > .0000001) {
                throw new SegmentException('MATCH_ROUTE_CHANGED');
            }
        }
        if ((int) $trip->seat_price_cents !== $terms['snapshot']['seat_price_minor']) {
            throw new SegmentException('MATCH_ROUTE_CHANGED');
        }

        return CohortPolicy::vehicleFingerprint($car);
    }

    public static function fare(array $terms, int $from, int $till): int
    {
        if ($from < 0 || $from >= $till || $till >= count($terms['stops'])) {
            throw new SegmentException('MATCH_ROUTE_CHANGED');
        }

        return array_sum(array_column(array_slice($terms['legs'], $from, $till - $from), 'seat_price_minor'));
    }
}
