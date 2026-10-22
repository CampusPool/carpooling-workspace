<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_dispatch_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('rider_id');
            $table->uuid('quote_id')->unique();
            $table->char('quote_hash', 64);
            $table->string('vehicle_type', 8);
            $table->unsignedBigInteger('pickup_point_id');
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('state', 16);
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('next_attempt_at')->nullable();
            $table->uuid('current_assignment_id')->nullable();
            $table->unsignedInteger('active_rider_id')->nullable()->unique();
            $table->timestamps();
            $table->unique(['rider_id', 'key_hash']);
            $table->index(['state', 'next_attempt_at']);
            $table->index(['state', 'expires_at']);
            $table->foreign('rider_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('active_rider_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('quote_id')->references('id')->on('mobility_route_quotes')->restrictOnDelete();
            $table->foreign('pickup_point_id')->references('id')->on('mobility_pickup_points')->restrictOnDelete();
        });
        Schema::create('mobility_dispatch_availability', function (Blueprint $table): void {
            $table->unsignedInteger('driver_id')->primary();
            $table->unsignedInteger('car_id');
            $table->string('vehicle_type', 8);
            $table->char('vehicle_fingerprint', 64);
            $table->unsignedBigInteger('pickup_point_id');
            $table->boolean('online')->default(false);
            $table->timestamp('last_seen_at');
            $table->timestamp('fresh_until');
            $table->uuid('active_request_id')->nullable()->unique();
            $table->timestamp('last_offered_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['online', 'vehicle_type', 'pickup_point_id', 'fresh_until'], 'dispatch_candidate_availability');
            $table->foreign('driver_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('car_id')->references('id')->on('cars')->restrictOnDelete();
            $table->foreign('pickup_point_id')->references('id')->on('mobility_pickup_points')->restrictOnDelete();
            $table->foreign('active_request_id')->references('id')->on('mobility_dispatch_requests')->restrictOnDelete();
        });
        Schema::create('mobility_dispatch_offers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id');
            $table->unsignedInteger('driver_id');
            $table->unsignedInteger('car_id');
            $table->char('vehicle_fingerprint', 64);
            $table->unsignedTinyInteger('attempt');
            $table->string('state', 16);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['request_id', 'driver_id']);
            $table->index(['driver_id', 'state', 'expires_at']);
            $table->index(['request_id', 'state']);
            $table->foreign('request_id')->references('id')->on('mobility_dispatch_requests')->restrictOnDelete();
            $table->foreign('driver_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('car_id')->references('id')->on('cars')->restrictOnDelete();
        });
        Schema::create('mobility_dispatch_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id');
            $table->uuid('offer_id')->unique();
            $table->uuid('quote_id');
            $table->char('quote_hash', 64);
            $table->unsignedInteger('driver_id');
            $table->unsignedInteger('rider_id');
            $table->unsignedInteger('trip_id')->unique();
            $table->unsignedInteger('booking_id')->unique();
            $table->unsignedBigInteger('fare_minor');
            $table->uuid('active_request_id')->nullable()->unique();
            $table->unsignedInteger('active_driver_id')->nullable()->unique();
            $table->timestamps();
            $table->index('request_id');
            $table->foreign('request_id')->references('id')->on('mobility_dispatch_requests')->restrictOnDelete();
            $table->foreign('offer_id')->references('id')->on('mobility_dispatch_offers')->restrictOnDelete();
            $table->foreign('quote_id')->references('id')->on('mobility_route_quotes')->restrictOnDelete();
            $table->foreign('driver_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('rider_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('trip_id')->references('id')->on('trips')->restrictOnDelete();
            $table->foreign('booking_id')->references('id')->on('trip_passengers')->restrictOnDelete();
            $table->foreign('active_request_id')->references('id')->on('mobility_dispatch_requests')->restrictOnDelete();
            $table->foreign('active_driver_id')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('mobility_dispatch_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('request_id')->nullable();
            $table->unsignedInteger('driver_id')->nullable();
            $table->uuid('offer_id')->nullable();
            $table->string('action', 48);
            $table->string('event_key', 191)->unique();
            $table->json('payload');
            $table->timestamp('created_at');
            $table->index(['request_id', 'id']);
            $table->foreign('request_id')->references('id')->on('mobility_dispatch_requests')->restrictOnDelete();
            $table->foreign('driver_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('offer_id')->references('id')->on('mobility_dispatch_offers')->restrictOnDelete();
        });
        Schema::table('mobility_route_quotes', function (Blueprint $table): void {
            $table->uuid('dispatch_request_id')->nullable()->unique();
            $table->foreign('dispatch_request_id')->references('id')->on('mobility_dispatch_requests')->restrictOnDelete();
        });
        Schema::table('mobility_dispatch_requests', function (Blueprint $table): void {
            $table->foreign('current_assignment_id')->references('id')->on('mobility_dispatch_assignments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mobility_dispatch_requests', fn (Blueprint $table) => $table->dropForeign(['current_assignment_id']));
        Schema::table('mobility_route_quotes', function (Blueprint $table): void {
            $table->dropForeign(['dispatch_request_id']);
            $table->dropColumn('dispatch_request_id');
        });
        Schema::dropIfExists('mobility_dispatch_events');
        Schema::dropIfExists('mobility_dispatch_assignments');
        Schema::dropIfExists('mobility_dispatch_offers');
        Schema::dropIfExists('mobility_dispatch_availability');
        Schema::dropIfExists('mobility_dispatch_requests');
    }
};
