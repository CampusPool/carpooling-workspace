<?php

declare(strict_types=1);

// Intentionally CLI-only and impossible to run in a non-testing or disabled environment.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
    || ! filter_var(getenv('MOBILITY_PILOT_UI_ENABLED'), FILTER_VALIDATE_BOOLEAN)
    || getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_HOST') !== 'mysql' || getenv('DB_DATABASE') !== 'carpoolear') {
    fwrite(STDERR, "Local pilot seed is permitted only in an enabled testing CLI.\n");
    exit(2);
}

require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.host') !== 'mysql'
    || config('database.connections.mysql.database') !== 'carpoolear') {
    fwrite(STDERR, "Local pilot seed requires the isolated local testing database.\n");
    exit(2);
}

use Illuminate\Support\Facades\Hash;
use STS\Mobility\Pilot\PilotDemoRoutes;
use STS\Models\Car;
use STS\Models\Trip;
use STS\Models\User;

PilotDemoRoutes::seed();

$nonce = bin2hex(random_bytes(5));
$password = 'Pilot!'.bin2hex(random_bytes(12));
$host = User::factory()->create([
    'name' => 'Synthetic Demo Host',
    'email' => 'pilot-host-'.$nonce.'@example.test',
    'password' => Hash::make($password),
    'active' => true,
    // Synthetic-only complete host profile for upstream TripsManager::create.
    'nro_doc' => 'DEMO'.strtoupper($nonce),
    'mobile_phone' => (string) random_int(1000000000, 9999999999),
    'identity_validated' => true,
]);
$rider = User::factory()->create([
    'name' => 'Synthetic Demo Rider',
    'email' => 'pilot-rider-'.$nonce.'@example.test',
    'password' => Hash::make($password),
    'active' => true,
]);
$operator = User::factory()->create([
    'name' => 'Synthetic Demo Operator',
    'email' => 'pilot-operator-'.$nonce.'@example.test',
    'password' => Hash::make($password),
    'active' => true,
    'banned' => false,
    'is_admin' => true,
]);
// Only synthetic test DB seed data. This route is never sent to the user as a real ride.
// Faker uniqueness is process-local; repeated retained-DB seeds can collide.
// Reuse dedicated synthetic catalog entries without editing existing catalogs.
$brand = \STS\Models\CarBrand::firstOrCreate(['slug' => 'mobility-synthetic-pilot-brand'], ['name' => 'Synthetic Pilot Brand', 'is_active' => true]);
$model = \STS\Models\CarModel::firstOrCreate(['car_brand_id' => $brand->id, 'slug' => 'mobility-synthetic-pilot-model'], ['name' => 'Synthetic Pilot Model', 'is_active' => true]);
$color = \STS\Models\CarColor::firstOrCreate(['slug' => 'mobility-synthetic-pilot-color'], ['name' => 'Synthetic Pilot Color', 'hex' => '#777777', 'is_active' => true, 'sort_order' => 0]);
$car = Car::factory()->create(['user_id' => $host->id, 'car_brand_id' => $brand->id, 'car_model_id' => $model->id, 'car_color_id' => $color->id, 'year' => (int) date('Y') - 3]);
$trip = Trip::factory()->create([
    'user_id' => $host->id,
    'car_id' => $car->id,
    'from_town' => 'Synthetic Campus Gate A',
    'to_town' => 'Synthetic Campus Gate B',
    'punto_partida' => 'Synthetic pickup',
    'punto_llegada' => 'Synthetic dropoff',
    'trip_date' => now()->addDays(3),
    'weekly_schedule' => 0,
    'total_seats' => 1,
    'friendship_type_id' => Trip::PRIVACY_PUBLIC,
    'is_passenger' => 0,
]);
echo "Created synthetic local-only users and an unbooked demo offer.\n";
echo 'HOST_EMAIL=', $host->email, "\n";
echo 'RIDER_EMAIL=', $rider->email, "\n";
echo 'OPERATOR_EMAIL=', $operator->email, "\n";
echo 'PASSWORD=', $password, "\n";
echo 'TRIP_ID=', $trip->id, "\n";
echo "These credentials are printed once. Do not reuse them on real accounts.\n";
