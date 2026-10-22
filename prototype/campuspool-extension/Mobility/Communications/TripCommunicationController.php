<?php

namespace STS\Mobility\Communications;

use Illuminate\Http\Request;

final class TripCommunicationController
{
    public function show(Request $r, int $trip, TripCommunicationService $s)
    {
        $v = $r->validate(['after' => 'sometimes|integer|min:0|prohibits:before', 'before' => 'sometimes|integer|min:1']);
        return $this->json($s->show($trip, $r->user(), isset($v['after']) ? (int) $v['after'] : null, isset($v['before']) ? (int) $v['before'] : null));
    }
    public function send(Request $r, int $trip, TripCommunicationService $s)
    {
        if (is_string($r->input('text'))) $r->merge(['text' => trim($r->input('text'))]);
        $v = $r->validate(['text' => 'required|string|min:1|max:800']);
        return $this->json($s->send($trip, $r->user(), $v['text'], (string) $r->header('Idempotency-Key', '')));
    }
    public function read(Request $r, int $trip, TripCommunicationService $s)
    {
        $v = $r->validate(['through_id' => 'required|integer|min:1']);
        return $this->json($s->read($trip, $r->user(), (int) $v['through_id']));
    }
    public function block(Request $r, int $trip, TripCommunicationService $s)
    {
        $v = $r->validate(['target_user_id' => 'required|integer|min:1', 'blocked' => 'required|boolean']);
        return $this->json($s->block($trip, $r->user(), (int) $v['target_user_id'], (bool) $v['blocked'], (string) $r->header('Idempotency-Key', '')));
    }
    public function notifications(Request $r, TripCommunicationService $s) { return $this->json($s->notifications($r->user())); }
    private function json(array $data) { return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store, max-age=0'); }
}
