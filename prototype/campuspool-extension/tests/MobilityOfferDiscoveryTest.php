<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Car;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

final class MobilityOfferDiscoveryTest extends TestCase
{
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = ['MOBILITY_COHORT_REQUIRED' => getenv('MOBILITY_COHORT_REQUIRED'), 'MOBILITY_COLLEGE_CODE' => getenv('MOBILITY_COLLEGE_CODE')];
        putenv('MOBILITY_COHORT_REQUIRED=true');
        putenv('MOBILITY_COLLEGE_CODE=CAMPUS-BLR');
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
        parent::tearDown();
    }

    public function test_discovery_returns_only_current_ready_quote_backed_future_inventory_and_canonical_capacity(): void
    {
        $rider = $this->member();
        [$host, $car] = $this->host();
        $visible = $this->publish($host, $car, now('Asia/Kolkata')->addDays(2)->setTime(9, 0), ['vehicle_type' => 'car', 'seat_price_minor' => 777]);
        $accepted = $this->member();
        DB::table('trip_passengers')->insert(['trip_id' => $visible->id, 'user_id' => $accepted->id, 'request_state' => Passenger::STATE_ACCEPTED, 'created_at' => now(), 'updated_at' => now()]);
        $visible->refresh();
        $this->publish($host, $car, now('Asia/Kolkata')->addDays(2)->setTime(10, 0), ['vehicle_type' => 'car'], ['state' => Trip::STATE_CANCELED]);
        $this->publish($this->member(), $car, now('Asia/Kolkata')->subMinutes(2), ['vehicle_type' => 'car']);
        $this->actingAsApiUser($rider)->getJson('/api/mobility/pool-offers?service_date='.$visible->trip_date->format('Y-m-d'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.seats_available', 1)->assertJsonPath('data.0.seat_price_minor', 777);
    }

    public function test_filters_are_strict_inclusive_and_paginate_after_filtering(): void
    {
        $rider = $this->member();
        [$host, $car] = $this->host();
        $date = now('Asia/Kolkata')->addDays(3)->startOfDay();
        [$bikeHost, $bike] = $this->host('bike');
        $a = $this->publish($bikeHost, $bike, $date->copy()->setTime(8, 0), ['vehicle_type' => 'bike'], ['total_seats' => 1]);
        $b = $this->publish($host, $car, $date->copy()->setTime(9, 0), ['vehicle_type' => 'car']);
        $this->actingAsApiUser($rider)->getJson('/api/mobility/pool-offers?service_date='.$date->format('Y-m-d').'&from_time=08:00&to_time=09:00&page_size=1')->assertOk()->assertJsonPath('data.0.id', $a->id)->assertJsonPath('next_cursor', (string) $a->id);
        $this->actingAsApiUser($rider)->getJson('/api/mobility/pool-offers?service_date='.$date->format('Y-m-d').'&after='.$a->id.'&vehicle_type=car')->assertOk()->assertJsonPath('data.0.id', $b->id);
        $this->actingAsApiUser($rider)->getJson('/api/mobility/pool-offers?service_date='.$date->format('Y-m-d').'&from_time=10:00&to_time=09:00')->assertStatus(422);
        $this->actingAsApiUser($rider)->getJson('/api/mobility/pool-offers?service_date='.$date->format('Y-m-d').'&page_size[]=2')->assertStatus(422);
    }

    public function test_private_unapproved_departed_started_and_own_offers_are_hidden(): void
    {
        $rider = $this->member();
        [$host, $car] = $this->host();
        $date = now('Asia/Kolkata')->addDays(2)->startOfDay();
        $own = $this->publish($rider, $car, $date->copy()->setTime(8, 0));
        $this->publish($host, $car, $date->copy()->setTime(9, 0), [], ['friendship_type_id' => Trip::PRIVACY_FRIENDS]);
        $this->publish($host, $car, $date->copy()->setTime(10, 0), [], ['state' => 'started']);
        $this->actingAsApiUser($rider)->getJson('/api/mobility/pool-offers?service_date='.$date->format('Y-m-d'))->assertOk()->assertJsonMissing(['id' => $own->id])->assertJsonCount(0, 'data');
        $banned = $this->member(['banned' => true]);
        $this->actingAsApiUser($banned)->getJson('/api/mobility/pool-offers?service_date='.$date->format('Y-m-d'))->assertStatus(403);
    }

    private function member(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge(['active' => true, 'banned' => false, 'identity_validated' => true], $overrides));
        DB::table('mobility_memberships')->insert(['user_id' => $user->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    private function host(string $vehicleType = 'car'): array
    {
        $user = $this->member();
        $car = Car::factory()->withCatalog()->create(['user_id' => $user->id]);
        DB::table('mobility_host_applications')->insert(['user_id' => $user->id, 'car_id' => $car->id, 'vehicle_type' => $vehicleType, 'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $car];
    }

    private function publish(User $host, Car $car, $date, array $snapshot = [], array $trip = []): Trip
    {
        $defaults = ['vehicle_type' => 'car', 'seat_price_minor' => 321, 'synthetic_only' => true, 'currency' => 'INR', 'distance_meters' => 2000, 'duration_seconds' => 600, 'provider' => 'fixture', 'profile' => 'fixture-car', 'pricing_version' => 'test-v1',
            'points' => [['address' => 'Synthetic A', 'lat' => 13.0, 'lng' => 77.5], ['address' => 'Synthetic B', 'lat' => 13.01, 'lng' => 77.51]],
            'pickup_points' => [['id' => 101, 'version' => 1, 'canonical_node_id' => 501], ['id' => 102, 'version' => 1, 'canonical_node_id' => 502]]];
        $snapshot = array_merge($defaults, $snapshot);
        $tripModel = Trip::factory()->create(array_merge(['user_id' => $host->id, 'car_id' => $car->id, 'trip_date' => $date->format('Y-m-d H:i:s'), 'total_seats' => 2, 'state' => Trip::STATE_READY, 'is_passenger' => false], $trip));
        $quote = (string) Str::uuid();
        DB::table('mobility_route_quotes')->insert(['id' => $quote, 'user_id' => $host->id, 'college_code' => 'CAMPUS-BLR', 'snapshot' => json_encode($snapshot), 'consumed_trip_id' => $tripModel->id, 'expires_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mobility_pool_publications')->insert(['user_id' => $host->id, 'key_hash' => hash('sha256', Str::random(12)), 'request_hash' => hash('sha256', Str::random(12)), 'quote_id' => $quote, 'trip_id' => $tripModel->id, 'created_at' => now(), 'updated_at' => now()]);

        return $tripModel;
    }
}
