<?php

namespace STS\Mobility\Journeys;

use Illuminate\Http\Request;
use STS\Mobility\Pickup\PickupException;

final class PrivateEndpointController
{
    public function store(Request $request, PrivateEndpointResolver $resolver)
    {
        try {
            $data = $resolver->create($request->user(), $request->all(), (string) $request->header('Idempotency-Key', ''));
            return response()->json(['data' => $data], $data['existing'] ? 200 : 201, ['Cache-Control' => 'private, no-store']);
        } catch (PickupException $e) { return $this->error($e); }
    }
    public function show(Request $request, string $id, PrivateEndpointResolver $resolver)
    {
        try { return response()->json(['data' => $resolver->resolve($request->user(), $id)], 200, ['Cache-Control' => 'private, no-store']); }
        catch (PickupException $e) { return $this->error($e); }
    }
    private function error(PickupException $e)
    { return response()->json(['error' => ['code' => $e->machineCode]], $e->httpStatus, ['Cache-Control' => 'private, no-store']); }
}
