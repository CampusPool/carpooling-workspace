<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_match_references', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('trip_id');
            $table->uuid('quote_id');
            $table->unsignedTinyInteger('from_index');
            $table->unsignedTinyInteger('till_index');
            $table->unsignedBigInteger('fare_minor');
            $table->char('route_hash', 64);
            $table->char('vehicle_fingerprint', 64);
            $table->timestamp('expires_at');
            $table->unsignedInteger('booking_id')->nullable();
            $table->char('request_key_hash', 64)->nullable();
            $table->json('response_body')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_key_hash']);
            $table->index(['user_id', 'created_at']);
            $table->index('trip_id');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('trip_id')->references('id')->on('trips')->restrictOnDelete();
            $table->foreign('quote_id')->references('id')->on('mobility_route_quotes')->restrictOnDelete();
            $table->foreign('booking_id')->references('id')->on('trip_passengers')->restrictOnDelete();
        });
        Schema::create('mobility_booking_intervals', function (Blueprint $table): void {
            $table->unsignedInteger('booking_id')->primary();
            $table->unsignedInteger('trip_id');
            $table->uuid('quote_id');
            $table->unsignedTinyInteger('from_index');
            $table->unsignedTinyInteger('till_index');
            $table->unsignedBigInteger('fare_minor');
            $table->char('route_hash', 64);
            $table->uuid('match_reference_id')->nullable();
            $table->timestamps();
            $table->index('trip_id');
            $table->foreign('booking_id')->references('id')->on('trip_passengers')->restrictOnDelete();
            $table->foreign('trip_id')->references('id')->on('trips')->restrictOnDelete();
            $table->foreign('quote_id')->references('id')->on('mobility_route_quotes')->restrictOnDelete();
            $table->foreign('match_reference_id')->references('id')->on('mobility_match_references')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_booking_intervals');
        Schema::dropIfExists('mobility_match_references');
    }
};
