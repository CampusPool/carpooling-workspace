<?php

namespace STS\Mobility\Recurrence;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProgramControlController
{
    public function __construct(private readonly ProgramControlService $service) {}

    public function control(Request $request, string $program): JsonResponse
    {
        if (! $request->user()) {
            return $this->error('AUTHENTICATION_REQUIRED', 401);
        }
        $body = $this->body($request, ['expected_version', 'action', 'reason'], []);
        if ($body === null) {
            return $this->error('PROGRAM_INPUT_INVALID', 422);
        }
        if (! is_string($body['action']) || ! is_string($body['reason'])) {
            return $this->error('PROGRAM_INPUT_INVALID', 422);
        }
        try {
            $data = $this->service->control($request->user(), $program, (string) $request->header('Idempotency-Key'), ProgramInputs::integer($body['expected_version'], 1, PHP_INT_MAX), $body['action'], $body['reason']);

            return response()->json(['data' => $data]);
        } catch (ProgramException $e) {
            return $this->error($e->machineCode, $e->httpStatus);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('PROGRAM_UNAVAILABLE', 500);
        }
    }

    public function edit(Request $request, string $program): JsonResponse
    {
        if (! $request->user()) {
            return $this->error('AUTHENTICATION_REQUIRED', 401);
        }
        $body = $this->body($request, ['expected_version', 'effective_from', 'reason'], ['weekly_schedule', 'departure_time', 'total_seats', 'date_to', 'quote_id']);
        if ($body === null) {
            return $this->error('PROGRAM_INPUT_INVALID', 422);
        }
        $changes = array_diff_key($body, array_flip(['expected_version', 'effective_from', 'reason']));
        if (! is_string($body['effective_from']) || ! is_string($body['reason'])) {
            return $this->error('PROGRAM_INPUT_INVALID', 422);
        }
        try {
            $data = $this->service->edit($request->user(), $program, (string) $request->header('Idempotency-Key'), ProgramInputs::integer($body['expected_version'], 1, PHP_INT_MAX), $body['effective_from'], $changes, $body['reason']);

            return response()->json(['data' => $data]);
        } catch (ProgramException $e) {
            return $this->error($e->machineCode, $e->httpStatus);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('PROGRAM_UNAVAILABLE', 500);
        }
    }

    public function exception(Request $request, string $program): JsonResponse
    {
        if (! $request->user()) {
            return $this->error('AUTHENTICATION_REQUIRED', 401);
        }
        $body = $this->body($request, ['expected_version', 'service_date', 'action', 'reason'], []);
        if ($body === null) {
            return $this->error('PROGRAM_INPUT_INVALID', 422);
        }
        if (! is_string($body['service_date']) || ! is_string($body['action']) || ! is_string($body['reason'])) {
            return $this->error('PROGRAM_INPUT_INVALID', 422);
        }
        try {
            $data = $this->service->exception($request->user(), $program, (string) $request->header('Idempotency-Key'), ProgramInputs::integer($body['expected_version'], 1, PHP_INT_MAX), $body['service_date'], $body['action'], $body['reason']);

            return response()->json(['data' => $data]);
        } catch (ProgramException $e) {
            return $this->error($e->machineCode, $e->httpStatus);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('PROGRAM_UNAVAILABLE', 500);
        }
    }

    private function body(Request $request, array $required, array $optional): ?array
    {
        $body = $request->all();
        if (array_diff(array_keys($body), array_merge($required, $optional)) !== [] || array_diff($required, array_keys($body)) !== []) {
            return null;
        }

        return $body;
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code]], $status);
    }
}
