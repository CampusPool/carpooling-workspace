<?php

namespace STS\Mobility\Recurrence;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use STS\Mobility\Routing\OfferException;

final class ProgramController
{
    public function __construct(private readonly ProgramService $service) {}

    public function index(Request $r): JsonResponse
    {
        return $this->respond(function () use ($r): array {
            ProgramInputs::fields($r->query(), [], ['page_size', 'after']);
            $size = ProgramInputs::integer($r->query('page_size', 20), 1, 50, 'PROGRAM_PAGE_INVALID');
            $after = $r->query('after');
            if ($after !== null) {
                $after = ProgramInputs::uuid($after);
            }

            return ['body' => $this->service->list($r->user(), $after, $size)];
        });
    }

    public function store(Request $r): JsonResponse
    {
        return $this->respond(fn () => $this->service->create($r->user(), $r->all(), (string) $r->header('Idempotency-Key', '')));
    }

    public function show(Request $r, string $program): JsonResponse
    {
        return $this->respond(function () use ($r, $program): array {
            ProgramInputs::fields($r->query(), [], ['page_size', 'after_date']);
            $size = ProgramInputs::integer($r->query('page_size', 30), 1, 50, 'PROGRAM_PAGE_INVALID');
            $after = $r->query('after_date');
            if ($after !== null) {
                $after = ProgramInputs::date($after)->format('Y-m-d');
            }

            return ['data' => $this->service->show($r->user(), $program, $after, $size)];
        });
    }

    public function generate(Request $r, string $program): JsonResponse
    {
        return $this->respond(function () use ($r, $program): array {
            $input = $r->all();
            ProgramInputs::fields($input, ['expected_version'], ['until']);
            $version = ProgramInputs::integer($input['expected_version'], 1, PHP_INT_MAX, 'PROGRAM_VERSION_INVALID');
            $until = array_key_exists('until', $input) ? ProgramInputs::date($input['until'], 'GENERATION_RANGE_INVALID')->format('Y-m-d') : null;

            return $this->service->generate($r->user(), $program, (string) $r->header('Idempotency-Key', ''), $until, $version);
        });
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            $result = $operation();

            return response()->json($result['body'] ?? ['data' => $result['data']], $result['status'] ?? 200);
        } catch (ProgramException $e) {
            return response()->json(['error' => ['code' => $e->machineCode]], $e->httpStatus);
        } catch (OfferException $e) {
            return response()->json(['error' => ['code' => $e->getCodeName()]], $e->status());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => ['code' => 'PROGRAM_UNAVAILABLE']], 500);
        }
    }
}
