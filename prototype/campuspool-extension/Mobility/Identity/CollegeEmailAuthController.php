<?php

namespace STS\Mobility\Identity;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CollegeEmailAuthController
{
    public function __construct(private CollegeEmailAuth $auth) {}
    public function config(): JsonResponse { return $this->json(['domains' => $this->auth->domains(), 'delivery_available' => $this->auth->deliveryAvailable(), 'demo_available' => \STS\Mobility\Commute\CommuteService::demoEnabled()]); }
    public function requestCode(Request $request): JsonResponse
    {
        $email = $this->auth->normalizeEmail($request->input('email'));
        if (! $email) return $this->error('COLLEGE_EMAIL_INVALID', 422);
        try { return $this->json($this->auth->request($email, $request->ip()), 201); } catch (CollegeEmailAuthException $e) { return $this->error($e->machineCode, $e->httpStatus, $e->meta); }
    }
    public function verify(Request $request): JsonResponse
    {
        $challenge = $request->input('challenge_id'); $code = $request->input('code');
        if (! is_string($challenge) || ! preg_match('/^[0-9a-f-]{36}$/iD', $challenge) || ! is_string($code) || ! preg_match('/^[0-9]{6}$/D', $code)) return $this->error('COLLEGE_OTP_INPUT_INVALID', 422);
        try { $this->auth->consumeRate([['verify-ip:'.($request->ip()?:'unknown'),30]],60); return $this->json($this->auth->verify($challenge, $code)); } catch (CollegeEmailAuthException $e) { return $this->error($e->machineCode, $e->httpStatus, $e->meta); }
    }
    private function json(array $body, int $status = 200): JsonResponse { return response()->json($body, $status)->header('Cache-Control', 'no-store, max-age=0')->header('Pragma', 'no-cache'); }
    private function error(string $code, int $status, array $meta = []): JsonResponse { return $this->json(['error' => array_merge(['code' => $code], $meta)], $status); }
}
