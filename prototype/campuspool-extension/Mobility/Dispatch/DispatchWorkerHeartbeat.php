<?php

namespace STS\Mobility\Dispatch;

use Illuminate\Support\Str;

/**
 * Small, local-only process liveness record for the supervised worker.
 * This class deliberately has no database or dispatch-state authority.
 */
final class DispatchWorkerHeartbeat
{
    public const SCHEMA_VERSION = 1;

    public const DEFAULT_PATH = '/tmp/mobility-dispatch-worker.json';

    public const MAX_AGE_SECONDS = 15;

    private $lockHandle;

    private array $record;

    public function __construct(?string $path = null, ?int $pid = null, ?string $instanceId = null)
    {
        $configured = getenv('MOBILITY_DISPATCH_HEARTBEAT_PATH');
        $this->path = $path ?: ($configured ?: self::DEFAULT_PATH);
        $this->record = [
            'schema_version' => self::SCHEMA_VERSION,
            'process_id' => $pid ?? getmypid(),
            'instance_id' => $instanceId ?: (string) Str::uuid(),
            'status' => 'starting',
            'last_success_at' => null,
            'completed_cycles' => 0,
            'last_processed_count' => 0,
        ];
    }

    private string $path;

    public function path(): string
    {
        return $this->path;
    }

    public function instanceId(): string
    {
        return $this->record['instance_id'];
    }

    /** Hold one exclusive lock for the complete watch lifetime. */
    public function acquire(): bool
    {
        if ($this->lockHandle !== null) {
            return true;
        }
        $this->ensureDirectory();
        $handle = @fopen($this->path.'.lock', 'c');
        if ($handle === false || ! @flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return false;
        }
        $this->lockHandle = $handle;

        return true;
    }

    public function release(): void
    {
        if (is_resource($this->lockHandle)) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
        }
        $this->lockHandle = null;
    }

    public function start(): void
    {
        $this->record['status'] = 'starting';
        $this->record['last_success_at'] = null;
        $this->record['completed_cycles'] = 0;
        $this->record['last_processed_count'] = 0;
        $this->write();
    }

    public function success(int $processed): void
    {
        $this->record['status'] = 'running';
        $this->record['last_success_at'] = time();
        $this->record['completed_cycles']++;
        $this->record['last_processed_count'] = max(0, $processed);
        $this->write();
    }

    public function stop(): void
    {
        $this->record['status'] = 'stopped';
        $this->write();
    }

    public function fail(): void
    {
        $this->record['status'] = 'failed';
        $this->write();
    }

    /** @return array{healthy:bool,reason:string,record?:array<string,mixed>} */
    public static function health(?string $path = null, int $maxAge = self::MAX_AGE_SECONDS): array
    {
        $heartbeat = new self($path);
        $raw = @file_get_contents($heartbeat->path());
        if ($raw === false) {
            return ['healthy' => false, 'reason' => 'missing'];
        }
        try {
            $record = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['healthy' => false, 'reason' => 'invalid'];
        }
        if (! is_array($record)) {
            return ['healthy' => false, 'reason' => 'invalid'];
        }
        $required = ['schema_version', 'process_id', 'instance_id', 'status', 'last_success_at', 'completed_cycles', 'last_processed_count'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $record)) {
                return ['healthy' => false, 'reason' => 'invalid'];
            }
        }
        if (! is_int($record['schema_version']) || ! is_int($record['process_id'])
            || ! is_string($record['instance_id']) || $record['instance_id'] === ''
            || ! is_string($record['status'])
            || (! is_int($record['last_success_at']) && $record['last_success_at'] !== null)
            || ! is_int($record['completed_cycles']) || $record['completed_cycles'] < 0
            || ! is_int($record['last_processed_count']) || $record['last_processed_count'] < 0) {
            return ['healthy' => false, 'reason' => 'invalid'];
        }
        $timestamp = is_int($record['last_success_at']) ? $record['last_success_at'] : 0;
        $age = time() - $timestamp;
        $pidAlive = self::pidAlive((int) $record['process_id']);
        $healthy = (int) $record['schema_version'] === self::SCHEMA_VERSION
            && $record['status'] === 'running'
            && $timestamp > 0
            && $age >= 0 && $age <= $maxAge
            && $pidAlive && self::lockIsHeld($heartbeat->path());
        $reason = $healthy ? 'ok' : ($record['status'] === 'running' && $age > $maxAge ? 'stale' : 'not-running');

        return ['healthy' => $healthy, 'reason' => $reason, 'record' => $record];
    }

    private static function pidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return $pid === getmypid();
    }

    private static function lockIsHeld(string $path): bool
    {
        $handle = @fopen($path.'.lock', 'r');
        if ($handle === false) {
            return false;
        }
        $available = @flock($handle, LOCK_EX | LOCK_NB);
        if ($available) {
            @flock($handle, LOCK_UN);
        }
        @fclose($handle);

        return ! $available;
    }

    private function write(): void
    {
        $this->ensureDirectory();
        $temp = $this->path.'.'.getmypid().'.'.bin2hex(random_bytes(6)).'.tmp';
        $json = json_encode($this->record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
        if (@file_put_contents($temp, $json, LOCK_EX) === false || ! @rename($temp, $this->path)) {
            @unlink($temp);
            throw new \RuntimeException('HEARTBEAT_WRITE_FAILED');
        }
        @chmod($this->path, 0600);
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('HEARTBEAT_DIRECTORY_FAILED');
        }
    }
}
