<?php

namespace STS\Mobility\Recurrence;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use STS\Mobility\MobilityController;
use STS\Mobility\Payments\PaymentService;
use STS\Models\Trip;
use STS\Models\User;

/** Atomic owner controls for a dated recurring program. */
final class ProgramControlService
{
    public function __construct(private readonly ?MobilityController $mobility = null, private readonly ?PaymentService $payments = null) {}

    public function control(User $actor, string $programId, string $key, int $expectedVersion, string $action, string $reason): array
    {
        if (! in_array($action, ['pause', 'resume'], true) || $expectedVersion < 1 || $reason === '' || mb_strlen($reason) > 500) {
            throw new ProgramException('PROGRAM_CONTROL_INVALID');
        }

        return $this->mutate($actor, $programId, $key, ['action' => $action, 'expected_version' => $expectedVersion, 'reason' => $reason], function ($program) use ($action, $reason): array {
            if ($program->state === $action.'d' || ($action === 'resume' && $program->state === 'active')) {
                throw new ProgramException('PROGRAM_STATE_UNCHANGED', 409);
            }
            if (! in_array($program->state, ['active', 'paused'], true)) {
                throw new ProgramException('PROGRAM_STATE_INVALID', 409);
            }
            $next = $action === 'pause' ? 'paused' : 'active';
            $version = (int) $program->version + 1;
            DB::table('mobility_recurring_programs')->where('id', $program->id)->update(['state' => $next, 'version' => $version, 'updated_at' => now()]);

            return ['program' => ProgramReader::summary((object) array_merge((array) $program, ['state' => $next, 'version' => $version])), 'action' => $action, 'reason' => $reason];
        });
    }

    public function edit(User $actor, string $programId, string $key, int $expectedVersion, string $effectiveFrom, array $changes, string $reason): array
    {
        ProgramInputs::date($effectiveFrom);
        ProgramInputs::text($reason, 500, true);
        if ($expectedVersion < 1 || ! $changes) {
            throw new ProgramException('PROGRAM_EDIT_INVALID');
        }

        return $this->mutate($actor, $programId, $key, ['action' => 'edit', 'expected_version' => $expectedVersion, 'effective_from' => $effectiveFrom, 'changes' => $changes, 'reason' => $reason], function ($program) use ($actor, $effectiveFrom, $changes): array {
            $next = $program->generated_through ? ProgramInputs::date($program->generated_through)->addDay()->format('Y-m-d') : $program->date_from;
            if ($effectiveFrom !== $next) {
                throw new ProgramException('PROGRAM_EDIT_WATERMARK', 409);
            }
            $allowed = ['weekly_schedule', 'departure_time', 'total_seats', 'date_to', 'quote_id'];
            if (array_diff(array_keys($changes), $allowed) !== []) {
                throw new ProgramException('PROGRAM_EDIT_INVALID');
            }
            if (array_key_exists('weekly_schedule', $changes) && (! is_int($changes['weekly_schedule']) || $changes['weekly_schedule'] < 1 || $changes['weekly_schedule'] > 127)) {
                throw new ProgramException('PROGRAM_EDIT_INVALID');
            }
            if (array_key_exists('departure_time', $changes) && (! is_string($changes['departure_time']) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $changes['departure_time']))) {
                throw new ProgramException('PROGRAM_EDIT_INVALID');
            }
            if (array_key_exists('total_seats', $changes) && (! is_int($changes['total_seats']) || $changes['total_seats'] < 1 || ($program->vehicle_type === 'bike' ? $changes['total_seats'] !== 1 : $changes['total_seats'] > 5))) {
                throw new ProgramException('PROGRAM_EDIT_INVALID');
            }
            if (array_key_exists('date_to', $changes)) {
                $dateTo = ProgramInputs::date($changes['date_to']);
                $dateFrom = ProgramInputs::date($program->date_from);
                if ($dateTo->lt($program->generated_through ? ProgramInputs::date($program->generated_through) : $dateFrom) || $dateTo->gt($dateFrom->addDays(365))) {
                    throw new ProgramException('PROGRAM_DATE_RANGE_INVALID');
                }
            }
            $update = ['version' => (int) $program->version + 1, 'updated_at' => now()];
            foreach (['weekly_schedule', 'departure_time', 'total_seats', 'date_to'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $update[$field] = $changes[$field];
                }
            }
            if (array_key_exists('quote_id', $changes)) {
                $changes['quote_id'] = ProgramInputs::uuid($changes['quote_id']);
                $quote = DB::table('mobility_route_quotes')->where('id', $changes['quote_id'])->where('user_id', $actor->id)->lockForUpdate()->first();
                if (! $quote || \Carbon\CarbonImmutable::parse($quote->expires_at, 'UTC')->lte(now('UTC'))) {
                    throw new ProgramException('PROGRAM_QUOTE_INVALID', 409);
                }
                $snapshot = json_decode((string) $quote->snapshot, true);
                $original = json_decode((string) $program->route_snapshot, true);
                if (($quote->dispatch_request_id ?? null) !== null || $quote->parent_quote_id !== null || $quote->consumed_trip_id !== null || ! is_array($snapshot) || ! is_array($original) || ($snapshot['vehicle_type'] ?? null) !== $program->vehicle_type || ($quote->college_code ?? null) !== \STS\Mobility\Trust\CohortPolicy::college() || array_column($snapshot['pickup_points'] ?? [], 'id') !== array_column($original['pickup_points'] ?? [], 'id')) {
                    throw new ProgramException('PROGRAM_QUOTE_INVALID', 409);
                }
                $update['route_snapshot'] = $quote->snapshot;
                $update['max_fare_minor'] = (int) ($snapshot['seat_price_minor'] ?? 0);
                $update['pricing_version'] = (string) ($snapshot['pricing_version'] ?? '');
            }
            DB::table('mobility_recurring_programs')->where('id', $program->id)->update($update);

            return ['program' => ProgramReader::summary((object) array_merge((array) $program, $update)), 'effective_from' => $effectiveFrom];
        });
    }

    public function exception(User $actor, string $programId, string $key, int $expectedVersion, string $serviceDate, string $action, string $reason): array
    {
        if ($action !== 'skip' || $expectedVersion < 1 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $serviceDate) || $reason === '' || mb_strlen($reason) > 500) {
            throw new ProgramException('PROGRAM_EXCEPTION_INVALID');
        }

        return $this->mutate($actor, $programId, $key, ['action' => 'skip', 'expected_version' => $expectedVersion, 'service_date' => $serviceDate, 'reason' => $reason], function ($program) use ($actor, $serviceDate, $reason): array {
            ProgramInputs::date($serviceDate);
            $today = now('Asia/Kolkata')->format('Y-m-d');
            if ($serviceDate < $today || $serviceDate < $program->date_from || $serviceDate > $program->date_to) {
                throw new ProgramException('PROGRAM_EXCEPTION_DATE_INVALID');
            }
            $occ = DB::table('mobility_recurring_occurrences')->where(['program_id' => $program->id, 'service_date' => $serviceDate])->first();
            $departure = $occ ? \Carbon\CarbonImmutable::parse(Trip::query()->whereKey($occ->trip_id)->value('trip_date'), 'Asia/Kolkata') : ProgramInputs::date($serviceDate)->setTimeFromTimeString($program->departure_time);
            if ($departure->lte(now('Asia/Kolkata'))) {
                throw new ProgramException('PROGRAM_EXCEPTION_DATE_INVALID');
            }
            if ($occ && ! Trip::query()->whereKey($occ->trip_id)->where('user_id', $program->user_id)->exists()) {
                throw new ProgramException('PROGRAM_MAPPING_CHANGED', 409);
            }
            if (DB::table('mobility_recurring_exceptions')->where(['program_id' => $program->id, 'service_date' => $serviceDate])->exists()) {
                throw new ProgramException('PROGRAM_EXCEPTION_EXISTS', 409);
            }
            if ($occ) {
                $trip = Trip::query()->find($occ->trip_id);
                if (! $trip) {
                    throw new ProgramException('PROGRAM_OCCURRENCE_MISSING', 409);
                }
                $state = DB::table('mobility_trip_lifecycles')->where('trip_id', $trip->id)->value('state') ?: ($trip->state === Trip::STATE_CANCELED ? 'cancelled' : 'planned');
                if ($state !== 'planned') {
                    throw new ProgramException('PROGRAM_OCCURRENCE_NOT_PLANNED', 409);
                }
                $cancelRequest = Request::create('/api/trips/'.$trip->id, 'POST', ['action' => 'cancel'], [], [], ['HTTP_IDEMPOTENCY_KEY' => 'recurring:'.$program->id.':'.$serviceDate]);
                $cancelRequest->setUserResolver(fn () => $actor);
                $activeStates = [\STS\Models\Passenger::STATE_PENDING, \STS\Models\Passenger::STATE_ACCEPTED, \STS\Models\Passenger::STATE_WAITING_PAYMENT];
                $bookings = DB::table('trip_passengers')->where('trip_id', $trip->id)->where('user_id', '<>', $trip->user_id)->whereIn('request_state', $activeStates)->get();
                $paymentBookingIds = DB::table('mobility_payment_attempts')->whereIn('booking_id', $bookings->pluck('id'))->exists();
                $cancelled = ($this->mobility ?? app(MobilityController::class))->transition($cancelRequest, $trip);
                if ($cancelled->getStatusCode() >= 400) {
                    throw new ProgramException('PROGRAM_CANCEL_FAILED', 409);
                }
                $required = $paymentBookingIds;
                foreach ($bookings as $row) {
                    ($this->payments ?? app(PaymentService::class))->blockRefundOrDispute($trip, \STS\Models\Passenger::findOrFail($row->id), 'recurring_occurrence_cancelled');
                }
            } else {
                $required = false;
            }
            DB::table('mobility_recurring_exceptions')->insert(['program_id' => $program->id, 'service_date' => $serviceDate, 'action' => 'skip', 'reason' => $reason, 'actor_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $version = (int) $program->version + 1;
            DB::table('mobility_recurring_programs')->where('id', $program->id)->update(['version' => $version, 'updated_at' => now()]);

            return ['program' => ProgramReader::summary((object) array_merge((array) $program, ['version' => $version])), 'skipped_service_date' => $serviceDate, 'trip_id' => $occ ? (int) $occ->trip_id : null, 'financial_recovery_required' => $required];
        });
    }

    private function mutate(User $actor, string $id, string $key, array $input, callable $work): array
    {
        ProgramInputs::key($key);
        $hash = ProgramInputs::hash($input);

        return DB::transaction(function () use ($actor, $id, $key, $hash, $input, $work): array {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! $actor->active || $actor->banned) {
                throw new ProgramException('ACCOUNT_RESTRICTED', 403);
            }
            if (($input['action'] ?? null) === 'edit') {
                $candidate = DB::table('mobility_recurring_programs')->where('id', $id)->first();
                if (! $candidate || (int) $candidate->user_id !== (int) $actor->id) {
                    throw new ProgramException('PROGRAM_NOT_FOUND', 404);
                }
                ProgramHostPolicy::lock($actor, (int) $candidate->car_id, (string) $candidate->vehicle_type);
            }
            $lockedTripId = null;
            // The exception path is the only control which touches an existing
            // trip. Establish Trip -> program order before the program lock.
            if (($input['action'] ?? null) === 'skip' && isset($input['service_date'])) {
                $occ = DB::table('mobility_recurring_occurrences')->where(['program_id' => $id, 'service_date' => $input['service_date']])->first();
                if ($occ) {
                    $lockedTripId = (int) $occ->trip_id;
                    Trip::query()->whereKey($lockedTripId)->lockForUpdate()->first();
                }
            }
            $program = DB::table('mobility_recurring_programs')->where('id', $id)->lockForUpdate()->first();
            if (! $program || (int) $program->user_id !== (int) $actor->id) {
                throw new ProgramException('PROGRAM_NOT_FOUND', 404);
            }
            if (($input['action'] ?? null) === 'skip') {
                $currentTripId = DB::table('mobility_recurring_occurrences')->where(['program_id' => $id, 'service_date' => $input['service_date']])->value('trip_id');
                if (($currentTripId === null ? null : (int) $currentTripId) !== $lockedTripId) {
                    throw new ProgramException('PROGRAM_MAPPING_CHANGED', 409);
                }
            }
            $existing = DB::table('mobility_recurring_actions')->where(['program_id' => $id, 'actor_id' => $actor->id, 'key_hash' => hash('sha256', $key)])->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new ProgramException('IDEMPOTENCY_CONFLICT', 409);
                }

                return json_decode($existing->response_body, true, 512, JSON_THROW_ON_ERROR);
            }
            if ((int) $program->version !== (int) $input['expected_version']) {
                throw new ProgramException('PROGRAM_VERSION_STALE', 409);
            }
            $result = $work($program);
            $afterEvidence = $result['program'] ?? [];
            if ($input['action'] === 'edit' && isset($input['changes']['quote_id'])) {
                $approval = DB::table('mobility_route_quotes')->where('id', $input['changes']['quote_id'])->first();
                $afterEvidence['approval_quote_id'] = (string) $approval->id;
                $afterEvidence['approval_quote_snapshot_hash'] = hash('sha256', (string) $approval->snapshot);
            }
            DB::table('mobility_recurring_actions')->insert(['program_id' => $id, 'actor_id' => $actor->id, 'key_hash' => hash('sha256', $key), 'request_hash' => $hash, 'action' => $input['action'], 'previous_version' => $program->version, 'version' => $result['program']['version'] ?? $program->version, 'reason' => $input['reason'] ?? null, 'before_snapshot' => json_encode((array) $program), 'after_snapshot' => json_encode($afterEvidence, JSON_THROW_ON_ERROR), 'response_status' => 200, 'response_body' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return $result;
        });
    }
}
