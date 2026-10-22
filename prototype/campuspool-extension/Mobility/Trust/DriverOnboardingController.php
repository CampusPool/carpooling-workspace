<?php

namespace STS\Mobility\Trust;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use STS\Models\Car;

final class DriverOnboardingController
{
    public function create(Request $request): JsonResponse
    {
        if ($this->unknown($request, ['car_id', 'vehicle_type', 'profile_display_name', 'profile_phone'])) return $this->error('UNKNOWN_FIELDS', 422);
        $user = $request->user();
        if (! CohortPolicy::activeUser($user)) return $this->error('ACCOUNT_RESTRICTED', 403);
        if (! CohortPolicy::approved((int) $user->id)) return $this->error('MEMBERSHIP_REQUIRED', 403);
        $carId = $this->positive($request->input('car_id'));
        $type = $request->input('vehicle_type');
        $name = is_string($request->input('profile_display_name')) ? trim($request->input('profile_display_name')) : '';
        $phone = $request->input('profile_phone');
        if (! $carId || ! in_array($type, ['bike', 'car'], true) || $name === '' || strlen($name) > 120
            || ($phone !== null && (! is_string($phone) || strlen($phone) > 32))) {
            return $this->error('ONBOARDING_INPUT_INVALID', 422);
        }
        try {
            $result = DB::transaction(function () use ($user, $carId, $type, $name, $phone): object {
                $freshUser = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                if (! CohortPolicy::activeUser($freshUser)) throw new \RuntimeException('MEMBERSHIP_REQUIRED');
                $member = DB::table('mobility_memberships')->where('user_id', $user->id)->lockForUpdate()->first();
                if (! $member || $member->status !== 'approved' || $member->college_code !== CohortPolicy::college()) throw new \RuntimeException('MEMBERSHIP_REQUIRED');
                $car = Car::query()->where('id', $carId)->lockForUpdate()->first();
                if (! $car || (int) $car->user_id !== (int) $user->id || ! $car->isComplete()) throw new \RuntimeException('VEHICLE_NOT_ELIGIBLE');
                $application = DB::table('mobility_host_applications')->where(['user_id' => $user->id, 'car_id' => $carId])->lockForUpdate()->first();
                if ($application && $application->vehicle_type !== $type) throw new \RuntimeException('HOST_APPLICATION_CONFLICT');
                if (! $application) {
                    $applicationId = DB::table('mobility_host_applications')->insertGetId([
                        'user_id' => $user->id, 'car_id' => $carId, 'vehicle_type' => $type,
                        'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'pending', 'version' => 1,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('mobility_host_application_audits')->insert(['application_id' => $applicationId, 'actor_id' => $user->id,
                        'previous_status' => null, 'new_status' => 'pending', 'version' => 1, 'reason' => 'Driver onboarding submitted', 'created_at' => now(), 'updated_at' => now()]);
                    $application = DB::table('mobility_host_applications')->where('id', $applicationId)->first();
                }
                $existing = DB::table('mobility_driver_onboardings')->where('host_application_id', $application->id)->lockForUpdate()->first();
                if ($existing) return (object) ['id' => $existing->id, 'status' => $existing->status, 'version' => $existing->version, 'existing' => true];
                $id = DB::table('mobility_driver_onboardings')->insertGetId([
                    'user_id' => $user->id, 'host_application_id' => $application->id, 'car_id' => $carId, 'vehicle_type' => $type,
                    'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'profile_display_name' => $name, 'profile_phone' => $phone,
                    'status' => 'pending', 'version' => 1, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('mobility_driver_onboarding_audits')->insert(['onboarding_id' => $id, 'actor_id' => $user->id,
                    'previous_status' => null, 'new_status' => 'pending', 'version' => 1, 'reason' => 'Application created', 'created_at' => now(), 'updated_at' => now()]);
                return (object) ['id' => $id, 'status' => 'pending', 'version' => 1, 'existing' => false];
            });
        } catch (\RuntimeException $e) {
            $code = $e->getMessage();
            if (! in_array($code, ['MEMBERSHIP_REQUIRED', 'VEHICLE_NOT_ELIGIBLE', 'HOST_APPLICATION_CONFLICT'], true)) throw $e;
            return $this->error($code, $code === 'HOST_APPLICATION_CONFLICT' ? 409 : 403);
        }
        return response()->json(['onboarding_id' => (int) $result->id, 'status' => $result->status, 'version' => (int) $result->version], $result->existing ? 200 : 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! CohortPolicy::activeUser($user)) return $this->error('ACCOUNT_RESTRICTED', 403);
        $rows = DB::table('mobility_driver_onboardings')->where('user_id', $user->id)->orderByDesc('id')->get();
        return response()->json(['data' => $rows->map(fn ($row) => $this->view($row, false))->values()->all()]);
    }

    public function upload(Request $request, int $id): JsonResponse
    {
        if ($this->unknown($request, ['document_type', 'document', 'expires_at', 'expected_version'])) return $this->error('UNKNOWN_FIELDS', 422);
        $user = $request->user();
        $row = DB::table('mobility_driver_onboardings')->where('id', $id)->first();
        if (! $row) return $this->error('ONBOARDING_NOT_FOUND', 404);
        if (! $user || (int) $row->user_id !== (int) $user->id) return $this->error('ONBOARDING_FORBIDDEN', 403);
        if (! in_array($row->status, ['pending', 'needs_resubmission', 'rejected'], true)) return $this->error('ONBOARDING_NOT_EDITABLE', 409);
        $type = $request->input('document_type');
        $file = $request->file('document');
        $expiry = $request->input('expires_at');
        $expected = filter_var($request->input('expected_version'), FILTER_VALIDATE_INT);
        if (! in_array($type, DriverOnboardingPolicy::TYPES, true) || ! $file || ! $file->isValid() || $expected === false || $expected < 1) return $this->error('DOCUMENT_INPUT_INVALID', 422);
        $mime = (string) $file->getMimeType();
        $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
        if (! in_array($mime, $allowed, true) || $file->getSize() > 10 * 1024 * 1024) return $this->error('DOCUMENT_FILE_INVALID', 422);
        if (DriverOnboardingPolicy::expiring($type) && (! $this->validDate($expiry) || $expiry < now()->toDateString())) return $this->error('DOCUMENT_EXPIRY_REQUIRED', 422);
        if ($expiry !== null && ! $this->validDate($expiry)) return $this->error('DOCUMENT_EXPIRY_INVALID', 422);
        $path = 'mobility-driver-documents/'.$id.'/'.Str::uuid().'.'.($mime === 'application/pdf' ? 'pdf' : ($mime === 'image/png' ? 'png' : 'jpg'));
        try {
            $version = DB::transaction(function () use ($id, $type, $path, $file, $mime, $expiry, $user, $expected): int {
            $locked = $this->lockApplication($id, (int) $user->id);
            if (! $locked || (int) $locked->user_id !== (int) $user->id) throw new \RuntimeException('ONBOARDING_FORBIDDEN');
            if ((int) $locked->version !== $expected) throw new \RuntimeException('VERSION_CONFLICT');
            if (! in_array($locked->status, ['pending', 'needs_resubmission', 'rejected'], true)) throw new \RuntimeException('ONBOARDING_NOT_EDITABLE');
            $next = ((int) DB::table('mobility_driver_documents')->where(['onboarding_id' => $id, 'document_type' => $type])->lockForUpdate()->max('version')) + 1;
            if (! Storage::disk('local')->putFileAs(dirname($path), $file, basename($path))) throw new \RuntimeException('DOCUMENT_STORAGE_FAILED');
            DB::table('mobility_driver_documents')->insert(['onboarding_id' => $id, 'document_type' => $type, 'version' => $next, 'storage_path' => $path,
                'mime_type' => $mime, 'byte_size' => (int) $file->getSize(), 'expires_at' => $expiry, 'status' => 'submitted', 'submitted_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            $onboardingVersion = (int) $locked->version + 1;
            DB::table('mobility_driver_onboardings')->where('id', $id)->update(['version' => $onboardingVersion, 'updated_at' => now()]);
            DB::table('mobility_driver_onboarding_audits')->insert(['onboarding_id' => $id, 'actor_id' => $user->id, 'previous_status' => $locked->status, 'new_status' => $locked->status, 'version' => $onboardingVersion, 'reason' => 'Document uploaded: '.$type, 'created_at' => now(), 'updated_at' => now()]);
            return $onboardingVersion;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            $code = $e->getMessage();
            if (! in_array($code, ['ONBOARDING_FORBIDDEN', 'VERSION_CONFLICT', 'ONBOARDING_NOT_EDITABLE', 'DOCUMENT_STORAGE_FAILED'], true)) $code = 'DOCUMENT_STORAGE_FAILED';
            return $this->error($code, in_array($code, ['VERSION_CONFLICT', 'ONBOARDING_NOT_EDITABLE'], true) ? 409 : ($code === 'DOCUMENT_STORAGE_FAILED' ? 503 : 403));
        }
        return response()->json(['document_type' => $type, 'version' => $version, 'onboarding_version' => $version, 'status' => 'submitted'], 201);
    }

    public function profile(Request $request, int $id): JsonResponse
    {
        if ($this->unknown($request, ['profile_display_name', 'profile_phone', 'expected_version'])) return $this->error('UNKNOWN_FIELDS', 422);
        $user = $request->user();
        $row = DB::table('mobility_driver_onboardings')->where('id', $id)->first();
        if (! $row) return $this->error('ONBOARDING_NOT_FOUND', 404);
        if (! $user || (int) $row->user_id !== (int) $user->id) return $this->error('ONBOARDING_FORBIDDEN', 403);
        if (! in_array($row->status, ['pending', 'needs_resubmission', 'rejected'], true)) return $this->error('ONBOARDING_NOT_EDITABLE', 409);
        $name = is_string($request->input('profile_display_name')) ? trim($request->input('profile_display_name')) : '';
        $phone = $request->input('profile_phone');
        $expected = filter_var($request->input('expected_version'), FILTER_VALIDATE_INT);
        if ($name === '' || strlen($name) > 120 || ($phone !== null && (! is_string($phone) || strlen($phone) > 32)) || $expected === false || $expected < 1) return $this->error('ONBOARDING_INPUT_INVALID', 422);
        try {
            $version = DB::transaction(function () use ($id, $user, $expected, $name, $phone): int {
                $locked = $this->lockApplication($id, (int) $user->id);
                if (! $locked || (int) $locked->user_id !== (int) $user->id) throw new \RuntimeException('ONBOARDING_FORBIDDEN');
                if ((int) $locked->version !== $expected) throw new \RuntimeException('VERSION_CONFLICT');
                if (! in_array($locked->status, ['pending', 'needs_resubmission', 'rejected'], true)) throw new \RuntimeException('ONBOARDING_NOT_EDITABLE');
                $version = $expected + 1;
                DB::table('mobility_driver_onboardings')->where('id', $id)->update(['profile_display_name' => $name, 'profile_phone' => $phone, 'version' => $version, 'updated_at' => now()]);
                DB::table('mobility_driver_onboarding_audits')->insert(['onboarding_id' => $id, 'actor_id' => $user->id, 'previous_status' => $locked->status, 'new_status' => $locked->status, 'version' => $version, 'reason' => 'Profile corrected', 'created_at' => now(), 'updated_at' => now()]);
                return $version;
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getMessage() === 'VERSION_CONFLICT' || $e->getMessage() === 'ONBOARDING_NOT_EDITABLE' ? 409 : 403);
        }
        return response()->json(['onboarding_id' => $id, 'profile_display_name' => $name, 'version' => $version]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        if ($this->unknown($request, ['expected_version'])) return $this->error('UNKNOWN_FIELDS', 422);
        $user = $request->user();
        $row = DB::table('mobility_driver_onboardings')->where('id', $id)->first();
        if (! $row) return $this->error('ONBOARDING_NOT_FOUND', 404);
        if (! $user || (int) $row->user_id !== (int) $user->id) return $this->error('ONBOARDING_FORBIDDEN', 403);
        $expected = filter_var($request->input('expected_version'), FILTER_VALIDATE_INT);
        if ($expected === false || $expected < 1) return $this->error('ONBOARDING_INPUT_INVALID', 422);
        try {
            $version = DB::transaction(function () use ($id, $user, $expected): int {
                $locked = $this->lockApplication($id, (int) $user->id);
                if (! $locked || (int) $locked->user_id !== (int) $user->id) throw new \RuntimeException('ONBOARDING_FORBIDDEN');
                if ((int) $locked->version !== $expected) throw new \RuntimeException('VERSION_CONFLICT');
                if (! in_array($locked->status, ['pending', 'needs_resubmission', 'rejected'], true)) throw new \RuntimeException('ONBOARDING_NOT_EDITABLE');
                $failure = null;
                if (! DriverOnboardingPolicy::checklistComplete($id, $failure)) throw new \RuntimeException($failure ?: 'DOCUMENTS_INCOMPLETE');
                $version = $expected + 1;
                DB::table('mobility_driver_onboardings')->where('id', $id)->update(['status' => 'pending', 'version' => $version, 'submitted_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null, 'review_reason' => null, 'updated_at' => now()]);
                DB::table('mobility_driver_onboarding_audits')->insert(['onboarding_id' => $id, 'actor_id' => $user->id, 'previous_status' => $locked->status, 'new_status' => 'pending', 'version' => $version, 'reason' => 'Corrected documents submitted', 'created_at' => now(), 'updated_at' => now()]);
                $host = DB::table('mobility_host_applications')->where('id', $locked->host_application_id)->lockForUpdate()->first();
                if ($host && $host->status === 'approved') {
                    $hostVersion = (int) $host->version + 1;
                    DB::table('mobility_host_applications')->where('id', $host->id)->update(['status' => 'pending', 'version' => $hostVersion, 'reviewed_by' => null, 'reviewed_at' => null, 'review_reason' => 'Driver onboarding resubmitted', 'updated_at' => now()]);
                    DB::table('mobility_host_application_audits')->insert(['application_id' => $host->id, 'actor_id' => $user->id, 'previous_status' => 'approved', 'new_status' => 'pending', 'version' => $hostVersion, 'reason' => 'Driver onboarding resubmitted', 'created_at' => now(), 'updated_at' => now()]);
                }
                return $version;
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), in_array($e->getMessage(), ['VERSION_CONFLICT', 'ONBOARDING_NOT_EDITABLE'], true) ? 409 : ($e->getMessage() === 'DOCUMENTS_INCOMPLETE' || $e->getMessage() === 'DOCUMENT_EXPIRED' ? 422 : 403));
        }
        return response()->json(['onboarding_id' => $id, 'status' => 'pending', 'version' => $version]);
    }

    public function queue(Request $request): JsonResponse
    {
        if (! $this->operator($request)) return $this->error('OPERATOR_FORBIDDEN', 403);
        if ($this->unknown($request, ['status', 'after', 'limit'])) return $this->error('UNKNOWN_FIELDS', 422);
        $status = $request->query('status', 'pending');
        if (! in_array($status, ['pending', 'needs_resubmission', 'approved', 'rejected', 'suspended', 'all'], true)) return $this->error('ONBOARDING_INPUT_INVALID', 422);
        $after = filter_var($request->query('after', 0), FILTER_VALIDATE_INT);
        $limit = filter_var($request->query('limit', 25), FILTER_VALIDATE_INT);
        if ($after === false || $after < 0 || $limit === false || $limit < 1 || $limit > 50) return $this->error('ONBOARDING_INPUT_INVALID', 422);
        $query = DB::table('mobility_driver_onboardings')->where('id', '>', $after)->whereExists(function ($q): void {
            $q->selectRaw('1')->from('mobility_memberships')->whereColumn('mobility_memberships.user_id', 'mobility_driver_onboardings.user_id')->where('college_code', CohortPolicy::college());
        });
        if ($status === 'pending') $query->whereIn('status', ['pending', 'needs_resubmission']);
        elseif ($status !== 'all') $query->where('status', $status);
        $rows = $query->orderBy('id')->limit($limit + 1)->get(); $more = $rows->count() > $limit; $rows = $rows->take($limit);
        return response()->json(['data' => $rows->map(fn ($row) => $this->view($row, true))->values()->all(), 'next_after' => $more ? (int) $rows->last()->id : null]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $target = DB::table('mobility_driver_onboardings')->where('id', $id)->first();
        if (! $target || ! $this->operator($request, (int) $target->user_id)) return $this->error($target ? 'OPERATOR_FORBIDDEN' : 'ONBOARDING_NOT_FOUND', $target ? 403 : 404);
        if ($this->unknown($request, ['status', 'expected_version', 'reason', 'document_type'])) return $this->error('UNKNOWN_FIELDS', 422);
        $status = $request->input('status'); $expected = filter_var($request->input('expected_version'), FILTER_VALIDATE_INT); $reason = is_string($request->input('reason')) ? trim($request->input('reason')) : '';
        $documentType = $request->input('document_type');
        if (! in_array($status, ['approved', 'rejected', 'needs_resubmission'], true) || $expected === false || $expected < 1 || $reason === '' || strlen($reason) > 500) return $this->error('DECISION_INVALID', 422);
        if ($documentType !== null && ! in_array($documentType, DriverOnboardingPolicy::TYPES, true)) return $this->error('DOCUMENT_INPUT_INVALID', 422);
        try {
            $result = DB::transaction(function () use ($id, $actor, $status, $expected, $reason, $documentType): object {
                $row = $this->lockApplication($id, (int) $actor->id);
                if (! $row) throw new \RuntimeException('ONBOARDING_NOT_FOUND');
                if ((int) $row->version !== $expected) throw new \RuntimeException('VERSION_CONFLICT');
                if ((int) $row->user_id === (int) $actor->id) throw new \RuntimeException('SELF_REVIEW');
                if (! self::allowedReview((string) $row->status, (string) $status)) throw new \RuntimeException('DECISION_INVALID');
                if ($documentType !== null && $status === 'approved') throw new \RuntimeException('DECISION_INVALID');
                if ($status === 'approved') {
                    $failure = null;
                    if (! DriverOnboardingPolicy::checklistComplete($id, $failure)) throw new \RuntimeException($failure ?: 'DOCUMENTS_INCOMPLETE');
                    $car = Car::withTrashed()->find($row->car_id);
                    if (! $car || $car->trashed() || ! $car->isComplete() || CohortPolicy::vehicleFingerprint($car) !== $row->vehicle_fingerprint) throw new \RuntimeException('VEHICLE_NOT_ELIGIBLE');
                    DB::table('mobility_driver_documents')->where('onboarding_id', $id)->where('status', 'submitted')->update(['status' => 'accepted', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'updated_at' => now()]);
                }
                if ($documentType !== null) {
                    $doc = DB::table('mobility_driver_documents')->where(['onboarding_id' => $id, 'document_type' => $documentType])->orderByDesc('version')->lockForUpdate()->first();
                    if (! $doc) throw new \RuntimeException('DOCUMENT_NOT_FOUND');
                    DB::table('mobility_driver_documents')->where('id', $doc->id)->update(['status' => $status === 'approved' ? 'accepted' : 'needs_resubmission', 'review_reason' => $reason, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'updated_at' => now()]);
                    $status = 'needs_resubmission';
                }
                $version = (int) $row->version + 1;
                DB::table('mobility_driver_onboardings')->where('id', $id)->update(['status' => $status, 'version' => $version, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason' => $reason, 'updated_at' => now()]);
                DB::table('mobility_driver_onboarding_audits')->insert(['onboarding_id' => $id, 'actor_id' => $actor->id, 'previous_status' => $row->status, 'new_status' => $status, 'version' => $version, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
                $host = DB::table('mobility_host_applications')->where('id', $row->host_application_id)->lockForUpdate()->first();
                $hostStatus = $status === 'approved' ? 'approved' : ($status === 'rejected' ? 'rejected' : ($status === 'suspended' ? 'suspended' : 'pending'));
                if ($host && $host->status !== $hostStatus) {
                    $hostVersion = (int) $host->version + 1;
                    DB::table('mobility_host_applications')->where('id', $host->id)->update(['status' => $hostStatus, 'version' => $hostVersion, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason' => $reason, 'updated_at' => now()]);
                    DB::table('mobility_host_application_audits')->insert(['application_id' => $host->id, 'actor_id' => $actor->id, 'previous_status' => $host->status, 'new_status' => $hostStatus, 'version' => $hostVersion, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
                }
                return (object) ['status' => $status, 'version' => $version];
            });
        } catch (\RuntimeException $e) {
            $code = $e->getMessage();
            if (! in_array($code, ['ONBOARDING_NOT_FOUND', 'VERSION_CONFLICT', 'SELF_REVIEW', 'DOCUMENTS_INCOMPLETE', 'DOCUMENT_EXPIRED', 'VEHICLE_NOT_ELIGIBLE', 'DOCUMENT_NOT_FOUND', 'DECISION_INVALID'], true)) $code = 'DECISION_INVALID';
            return $this->error($code === 'SELF_REVIEW' ? 'SELF_REVIEW_FORBIDDEN' : $code, $code === 'ONBOARDING_NOT_FOUND' ? 404 : (in_array($code, ['VERSION_CONFLICT', 'DECISION_INVALID'], true) ? 409 : 403));
        }
        return response()->json(['onboarding_id' => $id, 'status' => $result->status, 'version' => $result->version]);
    }

    public function document(Request $request, int $id, int $document): mixed
    {
        $row = DB::table('mobility_driver_onboardings')->where('id', $id)->first();
        if (! $row) return $this->error('ONBOARDING_NOT_FOUND', 404);
        $user = $request->user();
        if (! $user || ((int) $row->user_id !== (int) $user->id && ! $this->operator($request, (int) $row->user_id))) return $this->error('ONBOARDING_FORBIDDEN', 403);
        $doc = DB::table('mobility_driver_documents')->where(['id' => $document, 'onboarding_id' => $id])->first();
        if (! $doc || ! Storage::disk('local')->exists($doc->storage_path)) return $this->error('DOCUMENT_NOT_FOUND', 404);
        return Storage::disk('local')->download($doc->storage_path, 'driver-document-'.$doc->id.'.'.pathinfo($doc->storage_path, PATHINFO_EXTENSION), ['Content-Type' => $doc->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function view(object $row, bool $operator): array
    {
        $docs = DriverOnboardingPolicy::currentDocuments((int) $row->id);
        return ['id' => (int) $row->id, 'user_id' => (int) $row->user_id, 'car_id' => (int) $row->car_id, 'vehicle_type' => $row->vehicle_type, 'profile_display_name' => $row->profile_display_name, 'profile_phone' => $row->profile_phone, 'vehicle' => $this->vehicle((int) $row->car_id), 'status' => $row->status, 'version' => (int) $row->version, 'review_reason' => $row->review_reason,
            'checklist' => array_map(fn ($type) => ['document_type' => $type, 'required' => in_array($type, DriverOnboardingPolicy::requiredTypes(), true), 'status' => $docs[$type]->status ?? 'missing', 'expires_at' => $docs[$type]->expires_at ?? null, 'version' => isset($docs[$type]) ? (int) $docs[$type]->version : null, 'review_reason' => $docs[$type]->review_reason ?? null, 'document_id' => isset($docs[$type]) ? (int) $docs[$type]->id : null], DriverOnboardingPolicy::TYPES),
            ...($operator ? ['profile_phone' => $row->profile_phone, 'review_reason' => $row->review_reason] : [])];
    }

    private function vehicle(int $carId): ?array
    {
        $car = Car::withTrashed()->find($carId);
        return $car ? ['id' => (int) $car->id, 'description' => $car->description, 'registration' => $car->patente,
            'brand' => $car->brand_other, 'model' => $car->model_other, 'year' => $car->year,
            'color_id' => (int) $car->car_color_id, 'complete' => $car->isComplete(), 'deleted' => $car->trashed()] : null;
    }

    /** Shared trust→vehicle→host→onboarding order, also used by creation. */
    private function lockApplication(int $id, int $actorId): object
    {
        $preflight = DB::table('mobility_driver_onboardings')->where('id', $id)->first();
        if (! $preflight) throw new \RuntimeException('ONBOARDING_NOT_FOUND');
        $ids = array_values(array_unique([(int) $preflight->user_id, $actorId])); sort($ids, SORT_NUMERIC);
        DB::table('users')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        DB::table('mobility_memberships')->where('user_id', $preflight->user_id)->lockForUpdate()->first();
        DB::table('cars')->where('id', $preflight->car_id)->lockForUpdate()->first();
        DB::table('mobility_host_applications')->where('id', $preflight->host_application_id)->lockForUpdate()->first();
        $locked = DB::table('mobility_driver_onboardings')->where('id', $id)->lockForUpdate()->first();
        if (! $locked || (int) $locked->user_id !== (int) $preflight->user_id || (int) $locked->car_id !== (int) $preflight->car_id
            || (int) $locked->host_application_id !== (int) $preflight->host_application_id) throw new \RuntimeException('ONBOARDING_FORBIDDEN');
        return $locked;
    }

    private function operator(Request $request, ?int $targetUserId = null): bool {
        $user = $request->user();
        if (! $user || ! $user->is_admin || ! CohortPolicy::activeUser($user) || CohortPolicy::college() === null) return false;
        if ($targetUserId !== null) {
            $membership = CohortPolicy::membership($targetUserId);
            if (! $membership || $membership->college_code !== CohortPolicy::college()) return false;
        }
        return true;
    }
    private static function allowedReview(string $from, string $to): bool {
        if ($from === 'approved') return $to === 'suspended';
        if ($from === 'suspended') return $to === 'approved';
        return in_array($from, ['pending', 'needs_resubmission'], true) && in_array($to, ['approved', 'rejected', 'needs_resubmission'], true);
    }
    private function validDate(mixed $value): bool { if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return false; $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value); return $date !== false && $date->format('Y-m-d') === $value; }
    private function unknown(Request $request, array $allowed): bool { return array_diff(array_keys($request->all()), $allowed) !== []; }
    private function positive(mixed $value): ?int { if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) return null; $value = filter_var($value, FILTER_VALIDATE_INT); return $value !== false && $value > 0 ? $value : null; }
    private function error(string $code, int $status): JsonResponse { return response()->json(['error' => ['code' => $code]], $status); }
}
