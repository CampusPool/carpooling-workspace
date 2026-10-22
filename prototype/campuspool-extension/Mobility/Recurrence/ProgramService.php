<?php

namespace STS\Mobility\Recurrence;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Routing\OfferService;
use STS\Mobility\Routing\RouteQuoteService;
use STS\Mobility\Routing\RoutingException;
use STS\Mobility\Routing\RouteDataVersion;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\User;

final class ProgramService
{
    public function __construct(private readonly OfferService $offers, private readonly RouteQuoteService $quotes, private readonly DerivedQuoteFactory $derived) {}

    public function list(User $actor, ?string $after = null, int $size = 20): array
    {
        $this->readable($actor);
        $size = ProgramInputs::integer($size, 1, 50, 'PROGRAM_PAGE_INVALID');
        $query = DB::table('mobility_recurring_programs')->where('user_id', $actor->id)->orderBy('id')->limit($size + 1);
        if ($after !== null) {
            $query->where('id', '>', ProgramInputs::uuid($after));
        }
        $rows = $query->get();
        $more = $rows->count() > $size;
        $rows = $rows->take($size);

        return ['data' => $rows->map(fn ($p) => ProgramReader::summary($p))->all(), 'next_cursor' => $more ? (string) $rows->last()->id : null];
    }

    public function show(User $actor, string $id, ?string $after = null, int $size = 30): array
    {
        $size = ProgramInputs::integer($size, 1, 50, 'PROGRAM_PAGE_INVALID');
        if ($after !== null) {
            ProgramInputs::date($after);
        }
        $p = $this->owned($actor, $id);
        // Page the union of dates, so an exception without a trip cannot disappear at a cursor boundary.
        $occurrenceDates = DB::table('mobility_recurring_occurrences')->where('program_id', $id)->select('service_date');
        $exceptionDates = DB::table('mobility_recurring_exceptions')->where('program_id', $id)->select('service_date');
        $timeline = DB::query()->fromSub($occurrenceDates->union($exceptionDates), 'timeline')->orderBy('service_date')->limit($size + 1);
        if ($after !== null) {
            $timeline->where('service_date', '>', $after);
        }
        $dates = $timeline->pluck('service_date');
        $more = $dates->count() > $size;
        $page = $dates->take($size);
        $last = $page->last();
        $occurrences = array_values(array_filter(ProgramReader::occurrences($p, $after, $size), fn ($row) => $last !== null && $row['service_date'] <= $last));
        $exceptions = DB::table('mobility_recurring_exceptions')->where('program_id', $id)->whereIn('service_date', $page->all())->orderBy('service_date')->get()
            ->map(fn ($row) => ['service_date' => (string) $row->service_date, 'action' => (string) $row->action, 'reason' => (string) $row->reason])->all();

        return ['program' => ProgramReader::summary($p), 'occurrences' => $occurrences, 'exceptions' => $exceptions, 'next_cursor' => $more ? $last : null];
    }

    public function create(User $actor, array $input, string $key): array
    {
        ProgramInputs::key($key);
        ProgramInputs::fields($input, ['quote_id', 'car_id', 'weekly_schedule', 'departure_time', 'date_from', 'date_to', 'total_seats'], ['description']);
        $input = ['quote_id' => ProgramInputs::uuid($input['quote_id']), 'car_id' => ProgramInputs::integer($input['car_id'], 1, PHP_INT_MAX),
            'weekly_schedule' => ProgramInputs::integer($input['weekly_schedule'], 1, 127), 'departure_time' => ProgramInputs::time($input['departure_time']),
            'date_from' => ProgramInputs::date($input['date_from'])->format('Y-m-d'), 'date_to' => ProgramInputs::date($input['date_to'])->format('Y-m-d'),
            'total_seats' => ProgramInputs::integer($input['total_seats'], 1, 5), 'description' => ProgramInputs::text($input['description'] ?? '', 600)];
        $hash = ProgramInputs::hash($input);
        $keyHash = hash('sha256', $key);

        return DB::transaction(function () use ($actor, $input, $hash, $keyHash): array {
            $owner = ProgramHostPolicy::lock($actor, $input['car_id']);
            $existing = DB::table('mobility_recurring_programs')->where('user_id', $owner->id)->where('create_key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new ProgramException('IDEMPOTENCY_CONFLICT', 409);
                }
                $replay = $this->replay($existing->id, (int) $owner->id, $keyHash, $hash);
                if (! $replay) {
                    throw new ProgramException('PROGRAM_HISTORY_UNAVAILABLE', 503);
                }
                $replay['status'] = 200;
                $replay['data']['existing'] = true;

                return $replay;
            }
            $today = CarbonImmutable::now(ProgramCalendar::ZONE)->startOfDay();
            $from = ProgramInputs::date($input['date_from']);
            $to = ProgramInputs::date($input['date_to']);
            if ($from->lt($today) || $from->gt($today->addDays(21)) || $to->lt($from) || $to->gt($from->addDays(365))) {
                throw new ProgramException('PROGRAM_DATE_INVALID');
            }
            if (DB::table('mobility_recurring_programs')->where('user_id', $owner->id)->whereIn('state', ['active', 'paused'])->where('date_to', '>=', $today->format('Y-m-d'))->count() >= 10
                || DB::table('mobility_recurring_programs')->where('user_id', $owner->id)->where('created_at', '>=', now()->subDay())->count() >= 3) {
                throw new ProgramException('PROGRAM_RATE_LIMIT', 429);
            }
            $quote = $this->quote($owner, $input['quote_id']);
            $route = json_decode($quote->snapshot, true, 512, JSON_THROW_ON_ERROR);
            $synthetic = ($route['synthetic_only'] ?? false) === true && app()->environment('testing');
            if (! RouteDataVersion::allowed($route['data_version'] ?? null, (string) ($route['vehicle_type'] ?? ''), $synthetic)) {
                throw new ProgramException('PROGRAM_ROUTE_INVALID', 409);
            }
            $mode = $route['vehicle_type'] ?? null;
            if (! in_array($mode, ['bike', 'car'], true) || ($mode === 'bike' && $input['total_seats'] !== 1)) {
                throw new ProgramException('PROGRAM_CAPACITY_INVALID');
            }
            ProgramHostPolicy::lock($owner, $input['car_id'], $mode);
            $through = ProgramCalendar::generationThrough($input['date_from'], $input['date_to']);
            $id = (string) Str::uuid();
            DB::table('mobility_recurring_programs')->insert(['id' => $id, 'user_id' => $owner->id, 'car_id' => $input['car_id'], 'source_quote_id' => $quote->id,
                'route_snapshot' => $quote->snapshot, 'weekly_schedule' => $input['weekly_schedule'], 'departure_time' => $input['departure_time'],
                'date_from' => $input['date_from'], 'date_to' => $input['date_to'], 'total_seats' => $input['total_seats'], 'vehicle_type' => $mode,
                'max_fare_minor' => (int) $route['seat_price_minor'], 'pricing_version' => (string) $route['pricing_version'], 'description' => $input['description'],
                'state' => 'active', 'version' => 1, 'generated_through' => null, 'last_generation_code' => null, 'create_key_hash' => $keyHash, 'request_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
            $p = $this->owned($owner, $id, true);
            $dates = $this->eligibleDates($p, $through);
            if ($dates === []) {
                throw new ProgramException('NO_ELIGIBLE_OCCURRENCE');
            }
            foreach ($dates as $index => [$date, $departure]) {
                $quoteId = $index === 0 ? (string) $quote->id : $this->derived->child($quote, (int) $owner->id)['id'];
                $this->publish($owner, $p, $date, $departure, $quoteId, (int) $route['seat_price_minor']);
            }
            DB::table('mobility_recurring_programs')->where('id', $id)->update(['generated_through' => $through, 'last_generation_code' => 'CREATED', 'updated_at' => now()]);
            $p = $this->owned($owner, $id);
            $data = ['program' => ProgramReader::summary($p), 'occurrences' => ProgramReader::occurrences($p, null, 50), 'existing' => false];
            $this->record($p, (int) $owner->id, $keyHash, $hash, 'create', $data, 201);

            return ['status' => 201, 'data' => $data];
        });
    }

    public function generate(User $actor, string $id, string $key, ?string $until = null, ?int $expectedVersion = null): array
    {
        ProgramInputs::key($key);
        ProgramInputs::uuid($id);
        if ($until !== null) {
            ProgramInputs::date($until, 'GENERATION_RANGE_INVALID');
        }
        if ($expectedVersion !== null) {
            ProgramInputs::integer($expectedVersion, 1, PHP_INT_MAX, 'PROGRAM_VERSION_INVALID');
        }
        $keyHash = hash('sha256', $key);
        $hash = ProgramInputs::hash(['action' => 'generate', 'until' => $until, 'expected_version' => $expectedVersion]);
        $preflight = DB::transaction(function () use ($actor, $id, $keyHash, $hash, $until, $expectedVersion): array {
            $p = $this->owned($actor, $id);
            $owner = ProgramHostPolicy::lock($actor, (int) $p->car_id, (string) $p->vehicle_type);
            $p = $this->owned($owner, $id, true);
            if ($replay = $this->replay($id, (int) $owner->id, $keyHash, $hash)) {
                return ['result' => $replay];
            }
            $this->generationState($p, $expectedVersion);
            $target = $until ?? ProgramCalendar::generationThrough($p->date_from, $p->date_to);
            $this->generationRange($p, $target);
            $dates = $this->eligibleDates($p, $target);
            if ($dates === []) {
                return ['result' => $this->finishGeneration($p, $target, [], $keyHash, $hash)];
            }

            return ['program' => $p, 'target' => $target];
        });
        if (isset($preflight['result'])) {
            return $preflight['result'];
        }
        $observed = $preflight['program'];
        $route = json_decode($observed->route_snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (! RouteDataVersion::allowed($route['data_version'] ?? null, (string) $observed->vehicle_type, ($route['synthetic_only'] ?? false) === true && app()->environment('testing'))) {
            throw new ProgramException('PROGRAM_REQUOTE_REQUIRED', 409);
        }
        $points = $route['pickup_points'] ?? [];
        if (! is_array($points) || count($points) < 2 || count($points) > 8) {
            throw new ProgramException('PROGRAM_ROUTE_INVALID', 409);
        }
        // Provider I/O runs without program, user, vehicle or booking locks.
        try {
            $preparedInput = count($points) === 2 && ($route['route_schema'] ?? null) !== 'ordered-legs-v1'
                ? ['pickup_point_id' => (int) $points[0]['id'], 'dropoff_point_id' => (int) $points[1]['id'], 'vehicle_type' => $observed->vehicle_type]
                : ['stop_ids' => array_map(static fn (array $point): int => (int) $point['id'], $points), 'vehicle_type' => $observed->vehicle_type];
            $prepared = $this->quotes->quote((int) $actor->id, $preparedInput);
        } catch (RoutingException $e) {
            $this->generationFailure($observed, $e->machineCode);
            throw new ProgramException($e->machineCode, 503);
        }
        try {
            return DB::transaction(function () use ($actor, $id, $keyHash, $hash, $observed, $preflight, $prepared): array {
                $owner = ProgramHostPolicy::lock($actor, (int) $observed->car_id, (string) $observed->vehicle_type);
                $p = $this->owned($owner, $id, true);
                if ($replay = $this->replay($id, (int) $owner->id, $keyHash, $hash)) {
                    return $replay;
                }
                $this->generationState($p, (int) $observed->version);
                $this->generationRange($p, $preflight['target']);
                $dates = $this->eligibleDates($p, $preflight['target']);
                if ($dates === []) {
                    return $this->finishGeneration($p, $preflight['target'], [], $keyHash, $hash);
                }
                $quote = $this->quote($owner, $prepared['id']);
                $route = json_decode($quote->snapshot, true, 512, JSON_THROW_ON_ERROR);
                $storedRoute = json_decode((string) $p->route_snapshot, true, 512, JSON_THROW_ON_ERROR);
                if (($route['vehicle_type'] ?? null) !== $p->vehicle_type || ($route['pricing_version'] ?? null) !== $p->pricing_version || (int) ($route['seat_price_minor'] ?? PHP_INT_MAX) > (int) $p->max_fare_minor
                    || ($route['data_version'] ?? null) !== ($storedRoute['data_version'] ?? null)) {
                    throw new ProgramException('PROGRAM_REQUOTE_REQUIRED', 409);
                }
                $generated = [];
                foreach ($dates as [$date, $departure]) {
                    $child = $this->derived->child($quote, (int) $owner->id);
                    $this->publish($owner, $p, $date, $departure, $child['id'], (int) $route['seat_price_minor']);
                    $generated[] = $date;
                }

                return $this->finishGeneration($p, $preflight['target'], $generated, $keyHash, $hash);
            });
        } catch (ProgramException $e) {
            $this->generationFailure($observed, $e->machineCode);
            throw $e;
        }
    }

    private function eligibleDates(object $p, string $target): array
    {
        if ($p->generated_through !== null && $target <= $p->generated_through) {
            return [];
        }
        $start = $p->generated_through ? ProgramInputs::date($p->generated_through)->addDay() : ProgramInputs::date($p->date_from);
        $start = $start->max(CarbonImmutable::now(ProgramCalendar::ZONE)->startOfDay());
        if ($start->format('Y-m-d') > $target) {
            return [];
        }
        $skipped = DB::table('mobility_recurring_exceptions')->where('program_id', $p->id)->pluck('service_date')->all();

        return array_values(array_filter(ProgramCalendar::dates((int) $p->weekly_schedule, (string) $p->departure_time, $start->format('Y-m-d'), $target),
            fn ($entry) => ! in_array($entry[0], $skipped, true) && $entry[1]->gt(CarbonImmutable::now(ProgramCalendar::ZONE)->addMinutes(15)) && $entry[1]->lte(CarbonImmutable::now(ProgramCalendar::ZONE)->addDays(30))));
    }

    private function publish(User $owner, object $p, string $date, CarbonImmutable $departure, string $quoteId, int $fare): void
    {
        $published = $this->offers->publish($owner, ['quote_id' => $quoteId, 'car_id' => (int) $p->car_id, 'total_seats' => (int) $p->total_seats, 'description' => $p->description], 'recurring-'.$p->id.'-'.$date, $departure);
        // Link immediately: the next date must not count this dated inventory against the legacy single-offer abuse limit.
        DB::table('mobility_recurring_occurrences')->insert(['program_id' => $p->id, 'service_date' => $date, 'trip_id' => (int) $published['data']['id'], 'quote_id' => $quoteId, 'program_version' => (int) $p->version,
            'config_snapshot' => json_encode(['weekly_schedule' => (int) $p->weekly_schedule, 'departure_time' => $p->departure_time, 'total_seats' => (int) $p->total_seats, 'seat_price_minor' => $fare, 'max_fare_minor' => (int) $p->max_fare_minor, 'pricing_version' => $p->pricing_version], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function generationState(object $p, ?int $expected): void
    {
        if ($expected !== null && (int) $p->version !== $expected) {
            throw new ProgramException('PROGRAM_VERSION_STALE', 409);
        }
        if ($p->state !== 'active') {
            throw new ProgramException('PROGRAM_NOT_ACTIVE', 409);
        }
    }

    private function generationRange(object $p, string $target): void
    {
        $date = ProgramInputs::date($target, 'GENERATION_RANGE_INVALID');
        if ($date->gt(CarbonImmutable::now(ProgramCalendar::ZONE)->startOfDay()->addDays(28)) || $target > $p->date_to || $target < $p->date_from) {
            throw new ProgramException('GENERATION_RANGE_INVALID');
        }
    }

    private function finishGeneration(object $p, string $target, array $generated, string $keyHash, string $hash): array
    {
        $watermark = $p->generated_through === null || $target > $p->generated_through ? $target : $p->generated_through;
        DB::table('mobility_recurring_programs')->where('id', $p->id)->update(['generated_through' => $watermark, 'last_generation_code' => 'GENERATED', 'updated_at' => now()]);
        $p = DB::table('mobility_recurring_programs')->where('id', $p->id)->first();
        $data = ['program' => ProgramReader::summary($p), 'occurrences' => ProgramReader::occurrences($p, null, 50), 'generated' => $generated];
        $this->record($p, (int) $p->user_id, $keyHash, $hash, 'generate', $data, 200);

        return ['status' => 200, 'data' => $data];
    }

    private function quote(User $owner, string $id): object
    {
        $quote = DB::table('mobility_route_quotes')->where('id', $id)->where('user_id', $owner->id)->lockForUpdate()->first();
        if (! $quote) {
            throw new ProgramException('QUOTE_NOT_FOUND', 404);
        }
        if (($quote->dispatch_request_id ?? null) !== null || $quote->parent_quote_id !== null || $quote->college_code !== CohortPolicy::college() || $quote->consumed_trip_id !== null || CarbonImmutable::parse($quote->expires_at, 'UTC')->lte(CarbonImmutable::now('UTC'))) {
            throw new ProgramException('QUOTE_EXPIRED', 409);
        }

        return $quote;
    }

    private function owned(User $actor, string $id, bool $lock = false): object
    {
        $this->readable($actor);
        $query = DB::table('mobility_recurring_programs')->where('id', $id)->where('user_id', $actor->id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $p = $query->first();
        if (! $p) {
            throw new ProgramException('PROGRAM_NOT_FOUND', 404);
        }

        return $p;
    }

    private function readable(User $actor): void
    {
        if (! CohortPolicy::activeUser(User::query()->whereKey($actor->id)->first())) {
            throw new ProgramException('ACCOUNT_RESTRICTED', 403);
        }
    }

    private function replay(string $id, int $actorId, string $keyHash, string $hash): ?array
    {
        $action = DB::table('mobility_recurring_actions')->where('program_id', $id)->where('actor_id', $actorId)->where('key_hash', $keyHash)->first();
        if (! $action) {
            return null;
        }
        if (! hash_equals($action->request_hash, $hash)) {
            throw new ProgramException('IDEMPOTENCY_CONFLICT', 409);
        }

        return ['status' => (int) $action->response_status, 'data' => json_decode($action->response_body, true, 512, JSON_THROW_ON_ERROR)];
    }

    private function record(object $p, int $actorId, string $keyHash, string $hash, string $action, array $data, int $status): void
    {
        DB::table('mobility_recurring_actions')->insert(['program_id' => $p->id, 'actor_id' => $actorId, 'key_hash' => $keyHash, 'request_hash' => $hash, 'action' => $action, 'previous_version' => (int) $p->version, 'version' => (int) $p->version,
            'response_status' => $status, 'response_body' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function generationFailure(object $p, string $code): void
    {
        DB::table('mobility_recurring_programs')->where('id', $p->id)->where('version', $p->version)->where('generated_through', $p->generated_through)->where('state', 'active')->update(['last_generation_code' => substr($code, 0, 64), 'updated_at' => now()]);
    }
}
