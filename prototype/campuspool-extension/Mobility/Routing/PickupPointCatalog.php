<?php

namespace STS\Mobility\Routing;

use Illuminate\Support\Facades\DB;

final class PickupPointCatalog
{
    public function page(string $college, int $size = 30, ?int $after = null, ?string $vehicle = null): array
    {
        if ($size < 1 || $size > 50 || ($after !== null && $after < 1) || ($vehicle !== null && ! in_array($vehicle, ['bike', 'car'], true))) {
            throw new RoutingException('INVALID_CATALOG_PAGE');
        }
        $q = DB::table('mobility_pickup_points')->where('college_code', $college)->where('active', true)
            ->whereNotNull('verified_at')->whereBetween('lat', [12.70, 13.30])->whereBetween('lng', [77.30, 77.90])->orderBy('id');
        if (! app()->environment('testing')) {
            $q->where('synthetic_only', false);
        }
        if ($vehicle !== null) {
            $q->whereJsonContains('vehicle_modes', $vehicle);
        }
        if ($after !== null) {
            $q->where('id', '>', $after);
        }
        $rows = $q->limit($size + 1)->get();
        $hasMore = $rows->count() > $size;
        $rows = $rows->take($size)->values();

        return ['points' => $rows->map(fn ($row) => $this->normalize($row))->all(), 'next_cursor' => $hasMore ? (int) $rows->last()->id : null];
    }

    public function find(string $college, int $id, string $vehicle): ?object
    {
        $row = DB::table('mobility_pickup_points')->where('id', $id)->where('college_code', $college)->where('active', true)->whereNotNull('verified_at')->first();

        return $row && (! $row->synthetic_only || app()->environment('testing')) && $this->inEnvelope($row) && in_array($vehicle, $this->modes($row), true) ? $row : null;
    }

    public function normalize(object $row): array
    {
        $modes = $this->modes($row);

        return ['id' => (int) $row->id, 'version' => (int) $row->version, 'name' => (string) $row->name, 'lat' => (float) $row->lat, 'lng' => (float) $row->lng, 'supported_modes' => $modes, 'synthetic_only' => (bool) $row->synthetic_only];
    }

    public function snapshot(object $row): array
    {
        $point = $this->normalize($row);

        return ['id' => $point['id'], 'version' => $point['version'], 'canonical_node_id' => (int) $row->canonical_node_id];
    }

    private function modes(object $row): array
    {
        $modes = json_decode((string) $row->vehicle_modes, true);

        return is_array($modes) ? array_values(array_intersect(['bike', 'car'], $modes)) : [];
    }

    private function inEnvelope(object $row): bool
    {
        return is_numeric($row->lat) && is_numeric($row->lng) && (float) $row->lat >= 12.70 && (float) $row->lat <= 13.30 && (float) $row->lng >= 77.30 && (float) $row->lng <= 77.90;
    }
}
