<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_pickup_selections', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('plan_id'); $t->uuid('intent_id'); $t->uuid('preview_id');
            $t->uuid('active_intent_id')->nullable()->unique();
            $t->unsignedInteger('plan_version'); $t->unsignedInteger('intent_version'); $t->unsignedInteger('version');
            $t->unsignedInteger('driver_id'); $t->unsignedInteger('rider_id'); $t->unsignedInteger('car_id');
            $t->string('college_code', 64); $t->string('vehicle_type', 8); $t->string('ride_mode', 16);
            $t->string('choice', 32); $t->string('state', 16);
            $t->string('preview_hash', 64); $t->json('terms'); $t->string('terms_hash', 64);
            $t->string('create_key_hash', 64); $t->string('request_hash', 64);
            $t->timestamp('expires_at'); $t->timestamp('driver_selected_at');
            $t->timestamp('rider_confirmed_at')->nullable(); $t->timestamp('closed_at')->nullable(); $t->timestamps();
            $t->unique(['driver_id', 'create_key_hash']); $t->unique(['intent_id', 'version']);
            $t->index(['rider_id', 'state', 'expires_at']);
            $t->foreign('plan_id')->references('id')->on('mobility_pickup_plans')->restrictOnDelete();
            $t->foreign('intent_id')->references('id')->on('mobility_pickup_intents')->restrictOnDelete();
            $t->foreign('preview_id')->references('id')->on('mobility_pickup_previews')->restrictOnDelete();
            $t->foreign('active_intent_id')->references('id')->on('mobility_pickup_intents')->restrictOnDelete();
            $t->foreign('driver_id')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('rider_id')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('car_id')->references('id')->on('cars')->restrictOnDelete();
        });
        Schema::create('mobility_pickup_selection_actions', function (Blueprint $t): void {
            $t->bigIncrements('id'); $t->uuid('selection_id'); $t->unsignedInteger('actor_id');
            $t->string('action', 16); $t->string('key_hash', 64); $t->string('request_hash', 64); $t->timestamps();
            $t->unique(['actor_id', 'key_hash']);
            $t->foreign('selection_id')->references('id')->on('mobility_pickup_selections')->restrictOnDelete();
            $t->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_pickup_selection_actions');
        Schema::dropIfExists('mobility_pickup_selections');
    }
};
