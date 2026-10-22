<?php

namespace STS\Mobility\Recurrence;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ProgramGenerationCommand extends Command
{
    protected $signature = 'mobility:generate-recurring {--limit=20}';

    protected $description = 'Generate bounded active recurring program occurrences';

    public function handle(ProgramService $service): int
    {
        $limit = max(1, min(20, (int) $this->option('limit')));
        $count = 0;
        $errors = 0;
        $rows = DB::table('mobility_recurring_programs')->where('state', 'active')->where(function ($q) {
            $q->whereNull('date_to')->orWhere('date_to', '>=', now('Asia/Kolkata')->toDateString());
        })->orderByRaw('generated_through IS NOT NULL, generated_through, id')->limit($limit)->get();
        foreach ($rows as $p) {
            try {
                $user = \STS\Models\User::find($p->user_id);
                if ($user) {
                    $service->generate($user, $p->id, 'scheduler-'.$p->id.'-'.now('UTC')->format('YmdHi'));
                    $count++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $code = $e instanceof ProgramException ? $e->machineCode : 'PROGRAM_GENERATION_FAILED';
                DB::table('mobility_recurring_programs')->where('id', $p->id)->update(['last_generation_code' => $code, 'updated_at' => now()]);
                $this->warn($p->id.': '.$code);
            }
        }
        $this->info("Generated {$count} programs");

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
