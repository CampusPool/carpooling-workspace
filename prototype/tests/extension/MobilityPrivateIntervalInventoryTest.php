<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use STS\Mobility\Journeys\CanonicalRouteIntervalProjector;
use STS\Mobility\Journeys\PrivateIntervalInventory;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;
use Tests\TestCase;

final class MobilityPrivateIntervalInventoryTest extends TestCase
{
    private function interval(int $from, int $till): array
    {
        $row = ['version'=>1,'canonical_route_hash'=>str_repeat('a',64),'route_length_meters'=>1000,'from_meters'=>$from,'till_meters'=>$till];
        $row['interval_hash'] = CanonicalRouteIntervalProjector::hashInterval($row);
        return $row;
    }

    private function accepted(Trip $trip, ?array $interval, bool $badHash = false): Passenger
    {
        $passenger = Passenger::factory()->create(['trip_id'=>$trip->id,'user_id'=>User::factory()->create()->id,'request_state'=>Passenger::STATE_ACCEPTED,'passenger_type'=>Passenger::TYPE_PASAJERO]);
        DB::table('mobility_private_scheduled_bindings')->insert(['booking_id'=>$passenger->id,'trip_id'=>$trip->id,'quote_id'=>(string)Str::uuid(),'rider_id'=>$passenger->user_id,'fare_minor'=>1250,'quote_hash'=>str_repeat('c',64),'terms_snapshot'=>'{}','interval_snapshot'=>$interval ? json_encode($interval,JSON_THROW_ON_ERROR) : null,'interval_hash'=>$interval ? ($badHash?str_repeat('f',64):$interval['interval_hash']) : null,'created_at'=>now(),'updated_at'=>now()]);
        return $passenger;
    }

    public function test_disjoint_intervals_share_a_seat_and_overlap_consumes_it(): void
    {
        $trip = Trip::factory()->create(['total_seats'=>1]);
        $this->accepted($trip,$this->interval(0,300));
        $this->assertSame(1,PrivateIntervalInventory::available($trip,$this->interval(400,900),str_repeat('a',64)));
        $this->assertSame(0,PrivateIntervalInventory::available($trip,$this->interval(200,500),str_repeat('a',64)));
        $this->assertSame(1,PrivateIntervalInventory::available($trip,$this->interval(300,500),str_repeat('a',64)));
    }

    public function test_capacity_uses_peak_overlap_and_cancellation_releases_only_its_interval(): void
    {
        $trip = Trip::factory()->create(['total_seats'=>2]);
        $this->accepted($trip,$this->interval(0,300));
        $this->accepted($trip,$this->interval(400,900));
        $this->assertSame(1,PrivateIntervalInventory::available($trip,$this->interval(0,1000),str_repeat('a',64)));
        $third = $this->accepted($trip,$this->interval(200,600));
        $this->assertSame(0,PrivateIntervalInventory::available($trip,$this->interval(0,1000),str_repeat('a',64)));
        DB::table('trip_passengers')->where('id',$third->id)->update(['request_state'=>Passenger::STATE_CANCELED]);
        $this->assertSame(1,PrivateIntervalInventory::available($trip,$this->interval(0,1000),str_repeat('a',64)));
    }

    public function test_legacy_and_invalid_metadata_conservatively_occupy_the_entire_route(): void
    {
        $trip = Trip::factory()->create(['total_seats'=>2]);
        $this->accepted($trip,null);
        $this->assertSame(1,PrivateIntervalInventory::available($trip,$this->interval(400,900),str_repeat('a',64)));
        $this->accepted($trip,$this->interval(0,300),true);
        $this->assertSame(0,PrivateIntervalInventory::available($trip,$this->interval(400,900),str_repeat('a',64)));
    }

    public function test_invalid_candidate_boundaries_fail_closed(): void
    {
        $trip = Trip::factory()->create(['total_seats'=>2]);
        $candidate=$this->interval(0,300);$candidate['from_meters']='0';
        $this->assertSame(0,PrivateIntervalInventory::available($trip,$candidate,str_repeat('a',64)));
        $this->assertSame(0,PrivateIntervalInventory::available($trip,$this->interval(300,300),str_repeat('a',64)));
    }
}
