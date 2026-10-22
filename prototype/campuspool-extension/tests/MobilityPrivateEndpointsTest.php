<?php

namespace Tests\Feature\Mobility;

use Illuminate\Support\Facades\DB;
use STS\Mobility\Journeys\PrivateEndpointResolver;
use STS\Models\User;
use Tests\TestCase;

final class MobilityPrivateEndpointsTest extends TestCase
{
    private array $prior = [];
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['MOBILITY_COHORT_REQUIRED' => 'true', 'MOBILITY_COLLEGE_CODE' => 'CAMPUS-BLR'] as $key => $value) { $this->prior[$key] = getenv($key); putenv($key.'='.$value); }
    }
    protected function tearDown(): void { foreach ($this->prior as $key => $value) putenv($value === false ? $key : $key.'='.$value); parent::tearDown(); }

    public function test_private_identity_exact_replay_and_no_public_nodes_or_address_content(): void
    {
        $user = $this->member(); $nodes = DB::table('nodes_geo')->count(); $catalog = DB::table('mobility_pickup_points')->count();
        $body = ['lat' => 12.9716123, 'lng' => 77.5946123, 'source' => 'map_pin'];
        $first = $this->actingAsApiUser($user)->postJson('/api/mobility/private-endpoints', $body, ['Idempotency-Key' => 'private-pin'])->assertCreated();
        $id = $first->json('data.id'); $this->assertNotEmpty($id); $this->assertTrue($first->headers->hasCacheControlDirective('no-store'));
        $this->actingAsApiUser($user)->postJson('/api/mobility/private-endpoints', $body, ['Idempotency-Key' => 'private-pin'])->assertOk()->assertJsonPath('data.id', $id);
        $this->actingAsApiUser($user)->postJson('/api/mobility/private-endpoints', array_replace($body, ['lat' => 12.97]), ['Idempotency-Key' => 'private-pin'])->assertStatus(409);
        $this->assertSame($nodes, DB::table('nodes_geo')->count()); $this->assertSame($catalog, DB::table('mobility_pickup_points')->count());
        $this->actingAsApiUser($this->member())->getJson('/api/mobility/private-endpoints/'.$id)->assertNotFound();
        $this->assertSame(1, DB::table('mobility_private_endpoints')->count());
    }

    public function test_unknown_fields_google_address_content_and_invalid_coordinates_are_rejected(): void
    {
        $user = $this->member(); $body = ['lat' => 12.97, 'lng' => 77.59, 'source' => 'google_place'];
        foreach ([['address' => 'Do not cache provider address'], ['lat' => '12.97'], ['lng' => 78.0], ['source' => 'arbitrary'], ['lat' => null]] as $change) {
            $this->actingAsApiUser($user)->postJson('/api/mobility/private-endpoints', array_replace($body, $change), ['Idempotency-Key' => 'bad-point'])->assertStatus(422);
        }
        $this->assertSame(0, DB::table('mobility_private_endpoints')->count());
    }

    public function test_expiry_retention_preserves_opaque_identity_and_never_revives_retry(): void
    {
        $user = $this->member(); $body = ['lat' => 12.97, 'lng' => 77.59, 'source' => 'device_gps'];
        $id = $this->actingAsApiUser($user)->postJson('/api/mobility/private-endpoints', $body, ['Idempotency-Key' => 'expire-pin'])->assertCreated()->json('data.id');
        $hash = DB::table('mobility_private_endpoints')->where('id', $id)->value('coordinate_hash');
        DB::table('mobility_private_endpoints')->where('id', $id)->update(['expires_at' => now('UTC')->subSecond()]);
        $this->actingAsApiUser($user)->getJson('/api/mobility/private-endpoints/'.$id)->assertStatus(410);
        $this->assertSame(1, app(PrivateEndpointResolver::class)->redactExpired());
        $this->assertDatabaseHas('mobility_private_endpoints', ['id' => $id, 'lat' => null, 'lng' => null, 'coordinate_hash' => $hash]);
        $this->actingAsApiUser($user)->postJson('/api/mobility/private-endpoints', $body, ['Idempotency-Key' => 'expire-pin'])->assertStatus(410);
        $this->assertSame(0, app(PrivateEndpointResolver::class)->redactExpired());
    }

    private function member(): User
    {
        $u = User::factory()->create(['active' => true, 'banned' => false]);
        DB::table('mobility_memberships')->insert(['user_id' => $u->id, 'college_code' => 'CAMPUS-BLR', 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]); return $u;
    }
}
