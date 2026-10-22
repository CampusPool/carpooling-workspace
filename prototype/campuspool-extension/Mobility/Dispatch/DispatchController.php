<?php

namespace STS\Mobility\Dispatch;

use Illuminate\Http\Request;

final class DispatchController
{
    public function __construct(private readonly DispatchService $service) {}

    public function availability(Request $request)
    {
        return $this->privateJson($this->service->availability((int) $request->user()->id, $request->all()));
    }

    public function availabilityShow(Request $request)
    {
        return $this->privateJson($this->service->availabilityShow((int) $request->user()->id));
    }

    public function index(Request $request)
    {
        return $this->privateJson(['data'=>$this->service->requests((int)$request->user()->id)]);
    }

    public function create(Request $request)
    {
        $r = $this->service->createRequest((int) $request->user()->id, (string) $request->header('Idempotency-Key'), $request->all());

        return $this->privateJson($r, $r['status'] ?? 200);
    }

    public function show(Request $request, string $id)
    {
        return $this->privateJson($this->service->showRequest((int) $request->user()->id, $id, (bool) $request->user()->is_admin));
    }

    public function offers(Request $request)
    {
        return $this->privateJson(['data' => $this->service->offers((int) $request->user()->id)]);
    }

    public function accept(Request $request, string $id)
    {
        $this->emptyBody($request);

        return $this->privateJson($this->service->accept((int) $request->user()->id, $id, (string) $request->header('Idempotency-Key')));
    }

    public function decline(Request $request, string $id)
    {
        $this->emptyBody($request);

        return $this->privateJson($this->service->decline((int) $request->user()->id, $id, (string) $request->header('Idempotency-Key')));
    }

    public function cancel(Request $request, string $id)
    {
        $this->emptyBody($request);

        return $this->privateJson($this->service->cancel((int) $request->user()->id, $id, (string) $request->header('Idempotency-Key')));
    }

    private function privateJson(array $data, int $status = 200)
    {
        return response()->json($data, $status, ['Cache-Control'=>'private, no-store, max-age=0', 'X-Content-Type-Options'=>'nosniff']);
    }

    private function emptyBody(Request $request): void
    {
        if ($request->all() !== []) {
            throw new DispatchException('UNKNOWN_FIELDS', 422);
        }
    }
}
