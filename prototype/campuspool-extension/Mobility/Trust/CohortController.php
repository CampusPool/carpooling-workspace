<?php

namespace STS\Mobility\Trust;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use STS\Models\Car;

final class CohortController
{
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! CohortPolicy::activeUser($user)) {
            return $this->error('ACCOUNT_RESTRICTED', 403);
        }
        $membership = CohortPolicy::membership((int) $user->id);
        $hosts = DB::table('mobility_host_applications')->where('user_id', $user->id)->orderBy('id')->get()->map(fn ($row) => [
            'id' => (int) $row->id, 'car_id' => (int) $row->car_id, 'vehicle_type' => $row->vehicle_type,
            'status' => $row->status, 'version' => (int) $row->version,
        ])->values()->all();

        return response()->json(['college_code' => CohortPolicy::college(), 'membership' => $membership ? [
            'college_code' => $membership->college_code, 'affiliation' => $membership->affiliation,
            'status' => $membership->status, 'version' => (int) $membership->version,
        ] : null, 'host_applications' => $hosts, 'capabilities' => [
            'discover' => CohortPolicy::approved((int) $user->id), 'request' => CohortPolicy::approved((int) $user->id),
            'publish' => CohortPolicy::approved((int) $user->id) && CohortPolicy::hostApproved((int) $user->id),
        ]]);
    }

    public function apply(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! CohortPolicy::activeUser($user)) {
            return $this->error('ACCOUNT_RESTRICTED', 403);
        }
        $collegeInput = $request->input('college_code');
        $affiliationInput = $request->input('affiliation');
        if (! is_string($collegeInput) || ! is_string($affiliationInput)) {
            return $this->error('APPLICATION_INPUT_INVALID', 422);
        }
        $college = trim($collegeInput);
        $affiliation = $affiliationInput;
        if ($college === '' || strlen($college) > 64 || $college !== CohortPolicy::college()) {
            return $this->error('COLLEGE_CODE_INVALID', 422);
        }
        if (! in_array($affiliation, ['student', 'staff'], true)) {
            return $this->error('AFFILIATION_INVALID', 422);
        }
        $result = DB::transaction(function () use ($user, $college, $affiliation): object {
            $locked = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            if (! CohortPolicy::activeUser($locked)) {
                return (object) ['error' => 'ACCOUNT_RESTRICTED'];
            }
            $existing = DB::table('mobility_memberships')->where('user_id', $locked->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->college_code !== $college || $existing->affiliation !== $affiliation) {
                    return (object) ['error' => 'MEMBERSHIP_CONFLICT'];
                }

                return (object) ['id' => $existing->id, 'status' => $existing->status, 'version' => $existing->version, 'existing' => true];
            }
            $id = DB::table('mobility_memberships')->insertGetId(['user_id' => $user->id, 'college_code' => $college, 'affiliation' => $affiliation, 'status' => 'pending', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('mobility_membership_audits')->insert(['membership_id' => $id, 'user_id' => $user->id, 'actor_id' => $user->id, 'previous_status' => null, 'new_status' => 'pending', 'version' => 1, 'reason' => null, 'created_at' => now(), 'updated_at' => now()]);

            return (object) ['id' => $id, 'status' => 'pending', 'version' => 1, 'existing' => false];
        });
        if (isset($result->error)) {
            return $this->error($result->error, $result->error === 'ACCOUNT_RESTRICTED' ? 403 : 409);
        }

        return response()->json(['membership_id' => (int) $result->id, 'status' => $result->status, 'version' => (int) $result->version], $result->existing ? 200 : 201);
    }

    public function memberships(Request $request): JsonResponse
    {
        if (! $request->user()?->is_admin || $request->user()->banned || ! CohortPolicy::college()) {
            return $this->error('OPERATOR_FORBIDDEN', 403);
        }
        $pageSize = $request->query('page_size', 50);
        $after = $request->query('after');
        $statusFilter = $request->query('status');
        if ((! is_int($pageSize) && ! (is_string($pageSize) && ctype_digit($pageSize))) || ($after !== null && (! is_int($after) && ! (is_string($after) && ctype_digit($after)))) || ($statusFilter !== null && ! is_string($statusFilter))) {
            return $this->error('PAGINATION_INVALID', 422);
        }
        $size = min(100, max(1, (int) $pageSize));
        $query = DB::table('mobility_memberships')->join('users', 'users.id', '=', 'mobility_memberships.user_id')->select('mobility_memberships.*', 'users.name', 'users.email')->orderBy('mobility_memberships.id');
        if ($statusFilter !== null && $statusFilter !== '') {
            $query->where('mobility_memberships.status', $statusFilter);
        }
        $query->where('mobility_memberships.college_code', CohortPolicy::college());
        if ($after !== null) {
            $query->where('mobility_memberships.id', '>', (int) $after);
        }
        $rows = $query->limit($size + 1)->get();
        $more = $rows->count() > $size;
        $rows = $rows->take($size);

        return response()->json(['data' => $rows->map(fn ($row) => ['user_id' => (int) $row->user_id, 'name' => $row->name, 'email' => $row->email, 'college_code' => $row->college_code, 'affiliation' => $row->affiliation, 'status' => $row->status, 'version' => (int) $row->version])->values()->all(), 'pagination' => ['has_more' => $more, 'next_after' => $more ? (int) $rows->last()->id : null]]);
    }

    public function decideMembership(Request $request, int $user): JsonResponse
    {
        return $this->decide($request, 'membership', $user);
    }

    public function applyHost(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! CohortPolicy::activeUser($user)) {
            return $this->error('ACCOUNT_RESTRICTED', 403);
        }
        if (DriverOnboardingPolicy::documentsRequired()) {
            return $this->error('DRIVER_ONBOARDING_REQUIRED', 409);
        }
        if (! CohortPolicy::approved((int) $user->id)) {
            return $this->error('MEMBERSHIP_REQUIRED', 403);
        }
        $carInput = $request->input('car_id');
        $type = $request->input('vehicle_type');
        if ((! is_int($carInput) && ! (is_string($carInput) && ctype_digit($carInput))) || ! is_string($type)) {
            return $this->error('VEHICLE_INPUT_INVALID', 422);
        }
        $carId = (int) $carInput;
        if ($carId < 1 || ! in_array($type, ['bike', 'car'], true)) {
            return $this->error('VEHICLE_INPUT_INVALID', 422);
        }
        $car = Car::find($carId);
        if (! $car || (int) $car->user_id !== (int) $user->id || ! $car->isComplete()) {
            return $this->error('VEHICLE_NOT_ELIGIBLE', 403);
        }
        try {
            $result = DB::transaction(function () use ($user, $carId, $type): object {
                $lockedUser = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                if (! CohortPolicy::activeUser($lockedUser)) {
                    throw new \RuntimeException('ACCOUNT_RESTRICTED');
                }
                $membership = DB::table('mobility_memberships')->where('user_id', $user->id)->lockForUpdate()->first();
                if (! $membership || $membership->status !== 'approved' || $membership->college_code !== CohortPolicy::college()) {
                    throw new \RuntimeException('MEMBERSHIP_REQUIRED');
                }
                $car = Car::query()->where('id', $carId)->lockForUpdate()->first();
                if (! $car || (int) $car->user_id !== (int) $user->id || ! $car->isComplete()) {
                    throw new \RuntimeException('VEHICLE_NOT_ELIGIBLE');
                }
                $existing = DB::table('mobility_host_applications')->where(['user_id' => $user->id, 'car_id' => $carId])->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->vehicle_type !== $type) {
                        throw new \RuntimeException('HOST_APPLICATION_CONFLICT');
                    }

                    return (object) ['id' => $existing->id, 'status' => $existing->status, 'version' => $existing->version, 'existing' => true];
                }
                $id = DB::table('mobility_host_applications')->insertGetId(['user_id' => $user->id, 'car_id' => $carId, 'vehicle_type' => $type, 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'pending', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('mobility_host_application_audits')->insert(['application_id' => $id, 'actor_id' => $user->id, 'previous_status' => null, 'new_status' => 'pending', 'version' => 1, 'reason' => null, 'created_at' => now(), 'updated_at' => now()]);

                return (object) ['id' => $id, 'status' => 'pending', 'version' => 1, 'existing' => false];
            });
        } catch (\RuntimeException $e) {
            if (! in_array($e->getMessage(), ['HOST_APPLICATION_CONFLICT', 'ACCOUNT_RESTRICTED', 'MEMBERSHIP_REQUIRED', 'VEHICLE_NOT_ELIGIBLE'], true)) {
                throw $e;
            }

            return $this->error($e->getMessage(), $e->getMessage() === 'HOST_APPLICATION_CONFLICT' ? 409 : 403);
        }

        return response()->json(['application_id' => (int) $result->id, 'status' => $result->status, 'version' => (int) $result->version], $result->existing ? 200 : 201);
    }

    public function hostApplications(Request $request): JsonResponse
    {
        if (! $request->user()?->is_admin || $request->user()->banned || ! CohortPolicy::college()) {
            return $this->error('OPERATOR_FORBIDDEN', 403);
        }
        $pageSize = $request->query('page_size', 50);
        $after = $request->query('after');
        $statusFilter = $request->query('status');
        if ((! is_int($pageSize) && ! (is_string($pageSize) && ctype_digit($pageSize))) || ($after !== null && (! is_int($after) && ! (is_string($after) && ctype_digit($after)))) || ($statusFilter !== null && ! is_string($statusFilter))) {
            return $this->error('PAGINATION_INVALID', 422);
        }
        $size = min(100, max(1, (int) $pageSize));
        $query = DB::table('mobility_host_applications')->join('mobility_memberships', function ($join): void {
            $join->on('mobility_memberships.user_id', '=', 'mobility_host_applications.user_id')->where('mobility_memberships.college_code', '=', CohortPolicy::college());
        })->select('mobility_host_applications.*')->orderBy('mobility_host_applications.id');
        if ($statusFilter !== null && $statusFilter !== '') {
            $query->where('mobility_host_applications.status', $statusFilter);
        } if ($after !== null) {
            $query->where('mobility_host_applications.id', '>', (int) $after);
        }
        $rows = $query->limit($size + 1)->get();
        $more = $rows->count() > $size;
        $rows = $rows->take($size);

        return response()->json(['data' => $rows->map(fn ($r) => ['id' => (int) $r->id, 'user_id' => (int) $r->user_id, 'car_id' => (int) $r->car_id, 'vehicle_type' => $r->vehicle_type, 'status' => $r->status, 'version' => (int) $r->version])->values()->all(), 'pagination' => ['has_more' => $more, 'next_after' => $more ? (int) $rows->last()->id : null]]);
    }

    public function decideHost(Request $request, int $id): JsonResponse
    {
        // New onboarding records own their review lifecycle. Keeping this
        // guard here prevents the legacy decision endpoint from bypassing the
        // required checklist while retaining old fixtures without a record.
        if (DriverOnboardingPolicy::documentsRequired() || DB::table('mobility_driver_onboardings')->where('host_application_id', $id)->exists()) {
            return $this->error('DRIVER_ONBOARDING_REQUIRED', 409);
        }
        return $this->decide($request, 'host', $id);
    }

    private function decide(Request $request, string $kind, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor?->is_admin || $actor->banned || ! CohortPolicy::college()) {
            return $this->error('OPERATOR_FORBIDDEN', 403);
        }
        $statusInput = $request->input('status');
        $versionInput = $request->input('expected_version');
        $reasonInput = $request->input('reason', '');
        if (! is_string($statusInput) || (! is_int($versionInput) && ! is_string($versionInput)) || ! is_string($reasonInput)) {
            return $this->error('DECISION_INVALID', 422);
        }
        $status = $statusInput;
        $expected = filter_var($versionInput, FILTER_VALIDATE_INT);
        $reason = trim($reasonInput);
        if ($expected === false || $expected < 1 || ! in_array($status, ['approved', 'rejected', 'suspended'], true) || $reason === '' || strlen($reason) > 500) {
            return $this->error('DECISION_INVALID', 422);
        }
        $table = $kind === 'membership' ? 'mobility_memberships' : 'mobility_host_applications';
        $audit = $kind === 'membership' ? 'mobility_membership_audits' : 'mobility_host_application_audits';
        $key = $kind === 'membership' ? 'user_id' : 'id';
        try {
            $row = DB::transaction(function () use ($table, $audit, $key, $id, $actor, $status, $expected, $reason, $kind): ?object {
                $reference = DB::table($table)->where($key, $id)->first();
                if (! $reference) {
                    return null;
                }
                // One lock order across applications and decisions: users (ID
                // order), membership, vehicle, host application. Account bans
                // and cohort suspension cannot race an approval grant.
                $users = DB::table('users')->whereIn('id', [$actor->id, $reference->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $lockedActor = $users->get($actor->id);
                $target = $users->get($reference->user_id);
                if (! CohortPolicy::activeUser($lockedActor) || ! $lockedActor->is_admin) {
                    throw new \RuntimeException('OPERATOR_FORBIDDEN');
                }
                $membership = DB::table('mobility_memberships')->where('user_id', $reference->user_id)->lockForUpdate()->first();
                if ($kind === 'membership') {
                    $query = DB::table($table)->where($key, $id);
                    $row = $membership;
                    if (! $row || $row->college_code !== CohortPolicy::college()) {
                        throw new \RuntimeException('COLLEGE_MISMATCH');
                    }
                } else {
                    if (! $membership || $membership->college_code !== CohortPolicy::college()) {
                        throw new \RuntimeException('MEMBERSHIP_REQUIRED');
                    }
                    $car = Car::withTrashed()->where('id', $reference->car_id)->lockForUpdate()->first();
                    $query = DB::table($table)->where($key, $id)->lockForUpdate();
                    $row = $query->first();
                    if (! $row) {
                        return null;
                    }
                    if ($status === 'approved' && ($membership->status !== 'approved')) {
                        throw new \RuntimeException('MEMBERSHIP_REQUIRED');
                    }
                    if ($status === 'approved' && (! $car || $car->trashed() || (int) $car->user_id !== (int) $row->user_id || ! $car->isComplete() || CohortPolicy::vehicleFingerprint($car) !== $row->vehicle_fingerprint)) {
                        throw new \RuntimeException('VEHICLE_NOT_ELIGIBLE');
                    }
                }
                if ($status === 'approved' && ! CohortPolicy::activeUser($target)) {
                    throw new \RuntimeException('TARGET_RESTRICTED');
                }
                if ((int) $row->version !== $expected) {
                    throw new \RuntimeException('VERSION_CONFLICT');
                }
                if ((int) $row->user_id === (int) $actor->id) {
                    throw new \RuntimeException('SELF_REVIEW');
                }
                if (! self::allowedDecision((string) $row->status, $status)) {
                    throw new \RuntimeException('DECISION_INVALID');
                }
                $version = (int) $row->version + 1;
                $query->update(['status' => $status, 'version' => $version, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason' => $reason ?: null, 'updated_at' => now()]);
                DB::table($audit)->insert(array_filter(['membership_id' => $kind === 'membership' ? $row->id : null, 'application_id' => $kind === 'host' ? $row->id : null, 'user_id' => $kind === 'membership' ? $row->user_id : null, 'actor_id' => $actor->id, 'previous_status' => $row->status, 'new_status' => $status, 'version' => $version, 'reason' => $reason ?: null, 'created_at' => now(), 'updated_at' => now()], fn ($v) => $v !== null));

                return (object) ['id' => $row->id, 'status' => $status, 'version' => $version];
            });
        } catch (\RuntimeException $e) {
            $code = $e->getMessage();
            if (! in_array($code, ['VERSION_CONFLICT', 'COLLEGE_MISMATCH', 'SELF_REVIEW', 'MEMBERSHIP_REQUIRED', 'TARGET_RESTRICTED', 'DECISION_INVALID', 'OPERATOR_FORBIDDEN', 'VEHICLE_NOT_ELIGIBLE'], true)) {
                throw $e;
            }
            $statusCode = in_array($code, ['VERSION_CONFLICT', 'COLLEGE_MISMATCH'], true) ? 409 : (in_array($code, ['SELF_REVIEW', 'MEMBERSHIP_REQUIRED', 'TARGET_RESTRICTED', 'OPERATOR_FORBIDDEN', 'VEHICLE_NOT_ELIGIBLE'], true) ? 403 : 422);

            return $this->error($code === 'SELF_REVIEW' ? 'SELF_REVIEW_FORBIDDEN' : ($code === 'VERSION_CONFLICT' ? 'VERSION_CONFLICT' : $code), $statusCode);
        }
        if (! $row) {
            return $this->error('REVIEW_TARGET_NOT_FOUND', 404);
        }

        return response()->json(['id' => (int) $row->id, 'status' => $row->status, 'version' => (int) $row->version]);
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code]], $status);
    }

    private static function allowedDecision(string $from, string $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return match ($from) {
            'pending' => in_array($to, ['approved', 'rejected'], true),
            'approved' => $to === 'suspended',
            'suspended' => $to === 'approved',
            'rejected' => false,
            default => false,
        };
    }
}
