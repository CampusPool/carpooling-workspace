<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

final class MobilityOperatorCashQueueHttpTest extends TestCase
{
    private const PATH = '/api/mobility/operator/cash-disputes';

    public function test_queue_requires_authenticated_active_admin_authority(): void
    {
        $this->getJson(self::PATH)->assertUnauthorized();

        $user = User::factory()->create(['active' => true, 'banned' => false, 'is_admin' => false]);
        $this->actingAsApiUser($user)->getJson(self::PATH)
            ->assertForbidden()->assertJsonPath('error.code', 'OPERATOR_FORBIDDEN');

        $inactiveAdmin = User::factory()->create(['active' => false, 'banned' => false, 'is_admin' => true]);
        $this->actingAsApiUser($inactiveAdmin)->getJson(self::PATH)->assertUnauthorized();
    }

    public function test_queue_filters_disputes_and_provider_conflicts_across_trips_without_private_facts(): void
    {
        $admin = User::factory()->create(['active' => true, 'banned' => false, 'is_admin' => true]);
        [$disputed, $conflict, $ordinary] = $this->fixtures();
        DB::table('mobility_cash_receipts')->where('id', $disputed['receipt'])->update(['disputed_at' => now()->utc()]);
        DB::table('mobility_payment_attempts')->insert($this->attempt($conflict['trip'], $conflict['booking']));

        $response = $this->actingAsApiUser($admin)->getJson(self::PATH)->assertOk()
            ->assertJsonPath('audience', 'operator')
            ->assertJsonPath('live_provider_dispatch_enabled', false)
            ->assertJsonCount(2, 'data');
        $rows = $response->json('data');
        $this->assertSame([$disputed['receipt'], $conflict['receipt']], array_column($rows, 'receipt_id'));
        $this->assertSame('cash_disputed', $rows[0]['reconciliation_state']);
        $this->assertSame('channel_conflict', $rows[1]['reconciliation_state']);
        $this->assertTrue($rows[1]['has_provider_attempt']);
        $this->assertStringNotContainsString('private-order', $response->getContent());
        $this->assertStringNotContainsString('payment_reference', $response->getContent());
        $this->assertNotSame($ordinary['receipt'], $rows[0]['receipt_id']);
    }

    public function test_queue_reports_canonical_cash_facts_and_uses_bounded_monotonic_cursor(): void
    {
        $admin = User::factory()->create(['active' => true, 'banned' => false, 'is_admin' => true]);
        [$first, $second, $third] = $this->fixtures();
        foreach ([$first, $second, $third] as $fixture) {
            DB::table('mobility_cash_receipts')->where('id', $fixture['receipt'])->update(['disputed_at' => now()->utc()]);
        }

        $page = $this->actingAsApiUser($admin)->getJson(self::PATH.'?page_size=2')->assertOk()
            ->assertJsonPath('pagination.page_size', 2)->assertJsonPath('pagination.has_more', true);
        $rows = $page->json('data');
        $this->assertSame([$first['receipt'], $second['receipt']], array_column($rows, 'receipt_id'));
        $this->assertSame($second['receipt'], $page->json('pagination.next_after'));
        $this->assertSame(10000, $rows[0]['gross_amount']);
        $this->assertSame(1000, $rows[0]['commission_amount']);
        $this->assertSame('INR', $rows[0]['currency']);
        $this->assertTrue($rows[0]['rider_acknowledged']);
        $this->assertFalse($rows[0]['driver_acknowledged']);
        $this->assertSame('disputed', $rows[0]['cash_state']);
        $this->assertSame('not_settled', $rows[0]['cash_commission_status']);
        $this->assertSame('not_applicable_cash', $rows[0]['settlement_status']);

        $this->actingAsApiUser($admin)->getJson(self::PATH.'?page_size=2&after='.$page->json('pagination.next_after'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.receipt_id', $third['receipt'])
            ->assertJsonPath('pagination.has_more', false)->assertJsonPath('pagination.next_after', null);
        $this->actingAsApiUser($admin)->getJson(self::PATH.'?page_size=0')->assertStatus(422);
        $this->actingAsApiUser($admin)->getJson(self::PATH.'?page_size=101')->assertStatus(422);
        $this->actingAsApiUser($admin)->getJson(self::PATH.'?after=-1')->assertStatus(422);
    }

    public function test_repeated_queue_reads_do_not_mutate_cash_or_provider_tables(): void
    {
        $admin = User::factory()->create(['active' => true, 'banned' => false, 'is_admin' => true]);
        [$fixture] = $this->fixtures();
        DB::table('mobility_cash_receipts')->where('id', $fixture['receipt'])->update(['disputed_at' => now()->utc()]);
        DB::table('mobility_payment_attempts')->insert($this->attempt($fixture['trip'], $fixture['booking']));
        $before = $this->counts();

        $this->actingAsApiUser($admin)->getJson(self::PATH)->assertOk();
        $this->actingAsApiUser($admin)->getJson(self::PATH)->assertOk();

        $this->assertSame($before, $this->counts());
    }

    private function fixtures(): array
    {
        $fixtures = [];
        for ($index = 0; $index < 3; $index++) {
            $driver = User::factory()->create(['active' => true, 'banned' => false]);
            $rider = User::factory()->create(['active' => true, 'banned' => false]);
            $trip = Trip::factory()->create(['user_id' => $driver->id]);
            $booking = Passenger::factory()->aceptado()->create(['trip_id' => $trip->id, 'user_id' => $rider->id]);
            $receipt = DB::table('mobility_cash_receipts')->insertGetId([
                'trip_id' => $trip->id, 'booking_id' => $booking->id, 'rider_id' => $rider->id, 'driver_id' => $driver->id,
                'currency' => 'INR', 'gross_amount' => 10000, 'commission_amount' => 1000,
                'rider_confirmed_at' => now()->utc(), 'driver_confirmed_at' => $index === 1 ? now()->utc() : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $fixtures[] = ['trip' => $trip->id, 'booking' => $booking->id, 'receipt' => $receipt];
        }

        return $fixtures;
    }

    private function attempt(int $trip, int $booking): array
    {
        return ['trip_id' => $trip, 'booking_id' => $booking, 'provider' => 'fixture', 'environment' => 'test',
            'order_reference' => 'private-order-'.$booking, 'payment_reference' => 'private-payment-'.$booking,
            'amount' => 10000, 'currency' => 'INR', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()];
    }

    private function counts(): array
    {
        return [
            'cash' => DB::table('mobility_cash_receipts')->count(),
            'cash_events' => DB::table('mobility_cash_receipt_events')->count(),
            'attempts' => DB::table('mobility_payment_attempts')->count(),
            'postings' => DB::table('mobility_financial_postings')->count(),
        ];
    }
}
