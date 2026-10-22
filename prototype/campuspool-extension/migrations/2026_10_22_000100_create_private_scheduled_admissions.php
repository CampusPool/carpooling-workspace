<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_private_scheduled_publications', function (Blueprint $t): void {
            $t->unsignedInteger('trip_id')->primary();
            $t->uuid('publication_id')->unique();
            $t->uuid('source_quote_id')->unique();
            $t->unsignedInteger('driver_id');
            $t->unsignedInteger('car_id');
            $t->unsignedInteger('total_seats');
            $t->dateTime('departure_at');
            $t->string('route_hash', 64);
            $t->json('driver_path');
            $t->json('points');
            $t->string('create_key_hash', 64)->unique();
            $t->timestamps();
        });
        Schema::create('mobility_private_scheduled_bindings', function (Blueprint $t): void {
            $t->unsignedInteger('booking_id')->primary();
            $t->unsignedInteger('trip_id');
            $t->uuid('quote_id');
            $t->unsignedInteger('rider_id');
            $t->unsignedInteger('fare_minor');
            $t->string('quote_hash', 64);
            $t->timestamps();
            $t->unique(['trip_id', 'quote_id'], 'private_sched_trip_quote_unique');
        });
        Schema::create('mobility_private_scheduled_actions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedInteger('trip_id');
            $t->unsignedInteger('rider_id');
            $t->string('kind', 24);
            $t->string('key_hash', 64);
            $t->string('request_hash', 64);
            $t->json('response');
            $t->timestamps();
            $t->unique(['trip_id', 'rider_id', 'kind', 'key_hash'], 'private_sched_action_unique');
        });
    }

    public function down(): void
    {
        foreach (['mobility_private_scheduled_actions', 'mobility_private_scheduled_bindings', 'mobility_private_scheduled_publications'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
