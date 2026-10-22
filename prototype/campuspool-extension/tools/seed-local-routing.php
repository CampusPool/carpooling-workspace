<?php

// Reuses the explicitly gated local seed; never accepts or changes a real user.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('MOBILITY_COLLEGE_CODE') !== 'SYNTHETIC-LOCAL'
    || getenv('MOBILITY_ROUTING_SYNTHETIC_ONLY') !== 'true') {
    fwrite(STDERR, "Start the explicitly synthetic routing pilot before seeding.\n");
    exit(2);
}
require __DIR__.'/seed-local-pilot.php';

use Illuminate\Support\Facades\DB;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\NodeGeo;

if (CohortPolicy::college() !== 'SYNTHETIC-LOCAL' || ! config('mobility_routing.synthetic_only')) {
    throw new RuntimeException('Synthetic routing configuration required.');
}
DB::transaction(function () use ($host, $rider, $operator, $car): void {
    $reason = 'SYNTHETIC FIXTURE ONLY: no real affiliation, vehicle or identity verification.';
    foreach ([$host, $rider] as $actor) {
        $memberId = DB::table('mobility_memberships')->insertGetId(['user_id' => $actor->id, 'college_code' => 'SYNTHETIC-LOCAL',
            'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'reviewed_by' => $operator->id,
            'reviewed_at' => now(), 'review_reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mobility_membership_audits')->insert(['membership_id' => $memberId, 'user_id' => $actor->id, 'actor_id' => $operator->id,
            'previous_status' => null, 'new_status' => 'approved', 'version' => 1, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
    }
    $id = DB::table('mobility_host_applications')->insertGetId(['user_id' => $host->id, 'car_id' => $car->id, 'vehicle_type' => 'car',
        'vehicle_fingerprint' => CohortPolicy::vehicleFingerprint($car), 'status' => 'approved', 'version' => 1, 'reviewed_by' => $operator->id,
        'reviewed_at' => now(), 'review_reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('mobility_host_application_audits')->insert(['application_id' => $id, 'actor_id' => $operator->id, 'previous_status' => null,
        'new_status' => 'approved', 'version' => 1, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([['Synthetic Routing Stop A', 13.0, 77.5], ['Synthetic Routing Stop B', 13.01, 77.51], ['Synthetic Routing Stop C', 13.02, 77.52]] as [$name, $lat, $lng]) {
        $node = NodeGeo::firstOrCreate(['name' => $name, 'type' => 'mobility-synthetic'], ['lat' => $lat, 'lng' => $lng]);
        if ((float) $node->lat !== $lat || (float) $node->lng !== $lng) {
            throw new RuntimeException('Synthetic catalog collision.');
        }
        DB::table('mobility_pickup_points')->updateOrInsert(['college_code' => 'SYNTHETIC-LOCAL', 'name' => $name, 'synthetic_only' => true],
            ['canonical_node_id' => $node->id, 'version' => 1, 'lat' => $lat, 'lng' => $lng, 'vehicle_modes' => json_encode(['car', 'bike']),
                'active' => true, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
});
echo "Synthetic route catalog and preapproved test fixtures prepared. No real verification or road metrics.\n";
