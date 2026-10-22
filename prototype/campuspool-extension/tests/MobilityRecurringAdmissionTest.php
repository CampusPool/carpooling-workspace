<?php

namespace Tests\Feature\Mobility;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use STS\Mobility\Recurrence\ProgramAdmissionGuard;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

final class MobilityRecurringAdmissionTest extends TestCase
{
    public function test_paused_occurrence_denies_new_request_and_acceptance_but_get_history_passes(): void
    {
        [$trip, $program, $owner] = $this->occurrence('paused');
        $guard = new ProgramAdmissionGuard;
        foreach (['/api/trips/'.$trip->id.'/requests', '/api/trips/'.$trip->id.'/requests/9/accept'] as $path) {
            $request = Request::create($path, 'POST');
            $request->setUserResolver(fn () => $owner);
            $request->setRouteResolver(fn () => (new Route(['POST'], $path, []))->bind($request));
            $response = $guard->handle($request, fn () => response('admitted'));
            $this->assertSame(409, $response->getStatusCode());
            $this->assertSame('PROGRAM_PAUSED', json_decode($response->getContent(), true)['error']['code']);
        }
        $history = Request::create('/api/trips/'.$trip->id, 'GET');
        $history->setRouteResolver(fn () => (new Route(['GET'], $history->path(), []))->bind($history));
        $this->assertSame('history', $guard->handle($history, fn () => 'history'));
    }

    public function test_skipped_occurrence_denies_autoaccept_path_and_unrelated_trip_is_untouched(): void
    {
        [$trip, $program, $owner] = $this->occurrence('active');
        DB::table('mobility_recurring_exceptions')->insert(['program_id' => $program, 'service_date' => now('Asia/Kolkata')->addDay()->format('Y-m-d'), 'action' => 'skip', 'reason' => 'closure', 'actor_id' => $trip->user_id, 'created_at' => now(), 'updated_at' => now()]);
        $request = Request::create('/api/trips/'.$trip->id.'/requests/9/accept', 'POST');
        $request->setUserResolver(fn () => $owner);
        $request->setRouteResolver(fn () => (new Route(['POST'], $request->path(), []))->bind($request));
        $response = (new ProgramAdmissionGuard)->handle($request, fn () => response('admitted'));
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('PROGRAM_DATE_SKIPPED', json_decode($response->getContent(), true)['error']['code']);
        $this->assertDatabaseCount('mobility_recurring_exceptions', 1);
    }

    private function occurrence(string $state): array
    {
        $owner = User::factory()->create(['active' => true, 'banned' => false]);
        $car = \STS\Models\Car::factory()->withCatalog()->create(['user_id' => $owner->id]);
        $trip = Trip::factory()->create(['user_id' => $owner->id, 'car_id' => $car->id]);
        $this->actingAsApiUser($owner);
        $program = (string) \Illuminate\Support\Str::uuid();
        $quote = (string) \Illuminate\Support\Str::uuid();
        DB::table('mobility_route_quotes')->insert(['id' => $quote, 'user_id' => $owner->id, 'college_code' => 'CAMPUS-BLR', 'snapshot' => json_encode(['vehicle_type' => 'car', 'pickup_points' => []]), 'expires_at' => now('UTC')->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mobility_recurring_programs')->insert(['id' => $program, 'user_id' => $owner->id, 'car_id' => $trip->car_id, 'source_quote_id' => $quote, 'route_snapshot' => '{"vehicle_type":"car","pickup_points":[]}', 'weekly_schedule' => 2, 'departure_time' => '08:00', 'date_from' => now('Asia/Kolkata')->format('Y-m-d'), 'date_to' => now('Asia/Kolkata')->addDays(7)->format('Y-m-d'), 'total_seats' => 2, 'vehicle_type' => 'car', 'max_fare_minor' => 1000, 'pricing_version' => 'test-v1', 'description' => '', 'state' => $state, 'version' => 1, 'generated_through' => now('Asia/Kolkata')->format('Y-m-d'), 'last_generation_code' => null, 'create_key_hash' => hash('sha256', $program), 'request_hash' => hash('sha256', $program), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mobility_recurring_occurrences')->insert(['program_id' => $program, 'service_date' => now('Asia/Kolkata')->addDay()->format('Y-m-d'), 'trip_id' => $trip->id, 'quote_id' => $quote, 'program_version' => 1, 'config_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return [$trip, $program, $owner];
    }
}
