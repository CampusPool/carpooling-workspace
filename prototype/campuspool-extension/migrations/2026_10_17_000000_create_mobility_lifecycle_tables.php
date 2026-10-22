<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_trip_lifecycles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('trip_id')->unique();
            $table->string('state', 32)->default('planned');
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->foreign('trip_id')->references('id')->on('trips')->restrictOnDelete();
        });

        Schema::create('mobility_trip_participants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('trip_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('user_id');
            $table->string('state', 32)->default('accepted');
            $table->timestamps();
            $table->unique(['trip_id', 'booking_id']);
            $table->unique(['trip_id', 'user_id']);
            $table->foreign('trip_id')->references('id')->on('trips')->restrictOnDelete();
            $table->foreign('booking_id')->references('id')->on('trip_passengers')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('mobility_trip_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('trip_id');
            $table->unsignedInteger('actor_id');
            $table->string('action', 32);
            $table->unsignedInteger('passenger_id')->nullable();
            $table->string('idempotency_key', 100);
            $table->string('request_fingerprint', 64);
            $table->unsignedInteger('version');
            $table->unsignedSmallInteger('response_status')->default(200);
            $table->json('response_body');
            $table->timestamps();
            $table->unique(['trip_id', 'actor_id', 'idempotency_key'], 'mobility_event_idempotency');
            $table->unique(['trip_id', 'version'], 'mobility_event_version');
            $table->foreign('trip_id')->references('id')->on('trips')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('passenger_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_trip_events');
        Schema::dropIfExists('mobility_trip_participants');
        Schema::dropIfExists('mobility_trip_lifecycles');
    }
};
