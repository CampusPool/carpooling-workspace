<?php

namespace STS\Mobility\Communications;

use Illuminate\Support\Facades\DB;

final class PairBlockPolicy
{
    public static function blocked(int $a, int $b): bool
    {
        return DB::table('mobility_user_blocks')->where('blocked', true)
            ->where(function ($q) use ($a, $b): void {
                $q->where(fn ($q) => $q->where('actor_id', $a)->where('target_id', $b))
                    ->orWhere(fn ($q) => $q->where('actor_id', $b)->where('target_id', $a));
            })->lock('for share nowait')->get()->isNotEmpty();
    }
}
