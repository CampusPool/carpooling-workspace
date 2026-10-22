<?php

namespace STS\Mobility\Recurrence;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Trust\CohortPolicy;

final class DerivedQuoteFactory
{
    public function child(object $parent, int $userId): array
    {
        if ((int) $parent->user_id !== $userId || $parent->college_code !== CohortPolicy::college() || CarbonImmutable::parse($parent->expires_at, 'UTC')->lte(CarbonImmutable::now('UTC'))) {
            throw new ProgramException('QUOTE_EXPIRED', 409);
        }
        $id = (string) Str::uuid();
        DB::table('mobility_route_quotes')->insert(['id' => $id, 'parent_quote_id' => $parent->id, 'user_id' => $userId, 'college_code' => $parent->college_code,
            'snapshot' => $parent->snapshot, 'expires_at' => $parent->expires_at, 'created_at' => now(), 'updated_at' => now()]);

        return ['id' => $id];
    }
}
