<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_pickup_plans', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->unsignedInteger('user_id');
            $t->string('college_code', 64);
            $t->unsignedInteger('car_id');
            $t->string('vehicle_type', 8);
            $t->string('ride_mode', 16);
            $t->json('points'); $t->json('path_snapshot');
            $t->string('path_data_version', 180);
            $t->unsignedInteger('version')->default(1);
            $t->string('create_key_hash', 64); $t->string('request_hash', 64); $t->string('invite_hash', 64);
            $t->timestamp('expires_at'); $t->timestamps();
            $t->unique(['user_id', 'create_key_hash']); $t->index(['user_id', 'expires_at']);
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete(); $t->foreign('car_id')->references('id')->on('cars')->restrictOnDelete();
        });
        Schema::create('mobility_pickup_intents', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('plan_id');
            $t->unsignedInteger('rider_id'); $t->unsignedInteger('driver_id'); $t->unsignedInteger('plan_version');
            $t->json('origin'); $t->unsignedInteger('max_walk_meters'); $t->unsignedInteger('version')->default(1);
            $t->string('create_key_hash', 64); $t->string('request_hash', 64); $t->timestamp('expires_at'); $t->timestamps();
            $t->unique(['rider_id', 'create_key_hash']); $t->index(['plan_id', 'expires_at']);
            $t->foreign('plan_id')->references('id')->on('mobility_pickup_plans')->cascadeOnDelete(); $t->foreign('rider_id')->references('id')->on('users')->cascadeOnDelete(); $t->foreign('driver_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::create('mobility_pickup_previews', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('plan_id'); $t->uuid('intent_id');
            $t->unsignedInteger('plan_version'); $t->unsignedInteger('intent_version'); $t->json('snapshot');
            $t->string('create_key_hash', 64); $t->string('request_hash', 64); $t->timestamp('expires_at'); $t->timestamps();
            $t->unique(['intent_id', 'create_key_hash']); $t->index(['plan_id', 'intent_id', 'expires_at']);
            $t->foreign('plan_id')->references('id')->on('mobility_pickup_plans')->cascadeOnDelete(); $t->foreign('intent_id')->references('id')->on('mobility_pickup_intents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_pickup_previews'); Schema::dropIfExists('mobility_pickup_intents'); Schema::dropIfExists('mobility_pickup_plans');
    }
};
