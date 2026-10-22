<?php

namespace STS\Mobility\Journeys;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class PrivateScheduledAdmissionController
{
    public function discover(Request $r, PrivateScheduledAdmissionService $s)
    {
        $v = $r->validate(['quote_id' => 'required|uuid']);

        return response()->json(['data' => $s->discover($r->user(), $v['quote_id'])])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function bookings(Request $r, PrivateScheduledAdmissionService $s)
    {
        return response()->json(['data' => $s->bookings($r->user())])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function action(Request $r, int $trip, int $rider, string $action, PrivateScheduledAdmissionService $s)
    {
        return response()->json(['data' => $s->action($r->user(), $trip, $rider, $action, (string) $r->header('Idempotency-Key', ''))])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function publish(Request $r, PrivateScheduledAdmissionService $s)
    {
        $v = $r->validate(['quote_id' => 'required|uuid', 'trip_date' => 'required|date', 'total_seats' => 'required|integer|min:1|max:5']);

        return response()->json(['data' => $s->publish($r->user(), $v['quote_id'], (string) $r->header('Idempotency-Key', ''), CarbonImmutable::parse($v['trip_date'], 'Asia/Kolkata'), (int) $v['total_seats'])], 201)->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function request(Request $r, int $trip, PrivateScheduledAdmissionService $s)
    {
        $v = $r->validate(['quote_id' => 'required|uuid', 'expected_quote_hash' => 'required|string|size:64', 'expected_departure_at' => 'required|date', 'expected_interval_hash' => 'required|string|size:64']);

        return response()->json(['data' => $s->request($trip, $r->user(), $v['quote_id'], (string) $r->header('Idempotency-Key', ''), $v['expected_quote_hash'], $v['expected_departure_at'], $v['expected_interval_hash'])], 201)->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
