<?php

namespace STS\Mobility\Dispatch;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DispatchWorkCommand extends Command
{
    protected $signature = 'mobility:dispatch-work {--limit=20} {--watch} {--sleep=2} {--max-cycles=0} {--health}';

    protected $description = 'Expire and reconcile bounded on-demand dispatch work.';

    public function handle(DispatchWorker $worker): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        $sleep = filter_var($this->option('sleep'), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        $maxCycles = filter_var($this->option('max-cycles'), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        $watch = (bool) $this->option('watch');
        $health = (bool) $this->option('health');

        if ($health) {
            $explicitWorkOption = $this->input->hasParameterOption('--limit')
                || $this->input->hasParameterOption('--sleep')
                || $this->input->hasParameterOption('--max-cycles')
                || $watch;
            if ($explicitWorkOption) {
                $this->error('dispatch.worker.invalid_options code=HEALTH_COMBINATION');

                return self::FAILURE;
            }
            $result = DispatchWorkerHeartbeat::health();
            if (! $result['healthy']) {
                $this->error('dispatch.worker.unhealthy code=HEARTBEAT_'.strtoupper($result['reason']));

                return self::FAILURE;
            }
            $this->line('dispatch.worker.healthy');

            return self::SUCCESS;
        }

        if ($limit === null || $limit < 1 || $limit > 100
            || $sleep === null || $sleep < 1 || $sleep > 10
            || $maxCycles === null || $maxCycles < 0 || $maxCycles > 10000
            || (! $watch && ($this->input->hasParameterOption('--sleep') || $this->input->hasParameterOption('--max-cycles') || $maxCycles !== 0))) {
            $this->error('dispatch.worker.invalid_options code=INVALID_ARGUMENT');

            return self::FAILURE;
        }

        $heartbeat = new DispatchWorkerHeartbeat;
        try {
            $locked = $heartbeat->acquire();
        } catch (\Throwable) {
            $this->error('dispatch.worker.failed code=HEARTBEAT_UNAVAILABLE');

            return self::FAILURE;
        }
        if (! $locked) {
            $this->error('dispatch.worker.failed code=HEARTBEAT_LOCKED');

            return self::FAILURE;
        }
        $stopRequested = false;
        $previousSignalHandlers = [];
        $previousAsyncSignals = null;
        $signalHandler = static function () use (&$stopRequested): void {
            $stopRequested = true;
        };
        if (function_exists('pcntl_async_signals')) {
            $previousAsyncSignals = pcntl_async_signals();
            pcntl_async_signals(true);
        }
        if (function_exists('pcntl_signal')) {
            foreach ([SIGTERM, SIGINT] as $signal) {
                $previousSignalHandlers[$signal] = function_exists('pcntl_signal_get_handler')
                    ? pcntl_signal_get_handler($signal) : SIG_DFL;
                pcntl_signal($signal, $signalHandler);
            }
        }

        try {
            $heartbeat->start();
            if ($watch) {
                DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
            }
            $cycles = 0;
            do {
                if ($stopRequested) {
                    break;
                }
                $processed = $worker->process((int) $limit);
                $heartbeat->success($processed);
                $cycles++;
                if (! $watch || $stopRequested || ($maxCycles > 0 && $cycles >= $maxCycles)) {
                    break;
                }
                sleep((int) $sleep);
            } while (true);
            $heartbeat->stop();

            return self::SUCCESS;
        } catch (\Throwable) {
            try {
                $heartbeat->fail();
            } catch (\Throwable) {
                // Preserve the sanitized failure contract if the health file is unavailable.
            }
            $this->error('dispatch.worker.failed code=WORKER_FAILURE');

            return self::FAILURE;
        } finally {
            if (function_exists('pcntl_signal')) {
                foreach ($previousSignalHandlers as $signal => $handler) {
                    pcntl_signal((int) $signal, $handler);
                }
            }
            if ($previousAsyncSignals !== null && function_exists('pcntl_async_signals')) {
                pcntl_async_signals($previousAsyncSignals);
            }
            $heartbeat->release();
        }
    }
}
