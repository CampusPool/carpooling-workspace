<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\Artisan;
use STS\Mobility\Dispatch\DispatchWorkerHeartbeat;
use Tests\TestCase;

final class MobilityDispatchWorkerCommandTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/mobility-dispatch-worker-'.bin2hex(random_bytes(5)).'.json';
        putenv('MOBILITY_DISPATCH_HEARTBEAT_PATH='.$this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path.'.lock');
        putenv('MOBILITY_DISPATCH_HEARTBEAT_PATH');
        parent::tearDown();
    }

    public function test_heartbeat_is_atomic_and_health_requires_current_running_instance(): void
    {
        $heartbeat = new DispatchWorkerHeartbeat($this->path);
        $this->assertTrue($heartbeat->acquire());
        $heartbeat->start();
        $heartbeat->success(3);

        $record = json_decode((string) file_get_contents($this->path), true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame(DispatchWorkerHeartbeat::SCHEMA_VERSION, $record['schema_version']);
        $this->assertSame('running', $record['status']);
        $this->assertSame(3, $record['last_processed_count']);
        $this->assertTrue(DispatchWorkerHeartbeat::health($this->path)['healthy']);

        $heartbeat->stop();
        $this->assertFalse(DispatchWorkerHeartbeat::health($this->path)['healthy']);
        $heartbeat->release();
    }

    public function test_second_instance_cannot_take_the_exclusive_heartbeat_lock(): void
    {
        $first = new DispatchWorkerHeartbeat($this->path);
        $second = new DispatchWorkerHeartbeat($this->path);
        $this->assertTrue($first->acquire());
        $this->assertFalse($second->acquire());
        $first->release();
        $this->assertTrue($second->acquire());
        $second->release();
    }

    public function test_released_running_heartbeat_cannot_be_healthy_for_a_reused_pid(): void
    {
        $heartbeat = new DispatchWorkerHeartbeat($this->path);
        $this->assertTrue($heartbeat->acquire());
        $heartbeat->start();
        $heartbeat->success(0);
        $this->assertTrue(DispatchWorkerHeartbeat::health($this->path)['healthy']);
        $heartbeat->release();
        $this->assertFalse(DispatchWorkerHeartbeat::health($this->path)['healthy']);
    }

    public function test_malformed_health_records_fail_closed(): void
    {
        file_put_contents($this->path, json_encode(['status' => 'running']));
        $this->assertFalse(DispatchWorkerHeartbeat::health($this->path)['healthy']);
        file_put_contents($this->path, '[]');
        $this->assertSame('invalid', DispatchWorkerHeartbeat::health($this->path)['reason']);
        file_put_contents($this->path, '{"status":');
        $this->assertSame('invalid', DispatchWorkerHeartbeat::health($this->path)['reason']);
    }

    public function test_stale_running_record_is_unhealthy(): void
    {
        $record = [
            'schema_version' => DispatchWorkerHeartbeat::SCHEMA_VERSION,
            'process_id' => getmypid(), 'instance_id' => 'stale-instance', 'status' => 'running',
            'last_success_at' => time() - 16, 'completed_cycles' => 1, 'last_processed_count' => 0,
        ];
        file_put_contents($this->path, json_encode($record));
        $this->assertSame('stale', DispatchWorkerHeartbeat::health($this->path)['reason']);
    }

    public function test_health_cannot_be_combined_with_work_options(): void
    {
        $exit = Artisan::call('mobility:dispatch-work', ['--health' => true, '--watch' => true]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('HEALTH_COMBINATION', Artisan::output());
    }

    public function test_invalid_limits_are_rejected_before_processing(): void
    {
        $exit = Artisan::call('mobility:dispatch-work', ['--limit' => 101]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('INVALID_ARGUMENT', Artisan::output());
    }

    public function test_one_shot_rejects_watch_only_options_before_resolving_work(): void
    {
        $exit = Artisan::call('mobility:dispatch-work', ['--max-cycles' => 1]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('INVALID_ARGUMENT', Artisan::output());
    }
}
