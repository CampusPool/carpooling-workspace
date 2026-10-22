<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_dropoff_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('trip_id');
            $table->unsignedInteger('booking_id')->unique();
            $table->unsignedInteger('user_id');
            $table->string('idempotency_key', 100)->collation('ascii_bin');
            $table->unsignedBigInteger('confirmed_after_event_id');
            $table->timestamp('confirmed_at');
            $table->json('response_body');
            $table->foreign('trip_id')->references('id')->on('trips')->restrictOnDelete();
            $table->foreign('booking_id')->references('id')->on('trip_passengers')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('confirmed_after_event_id')->references('id')->on('mobility_trip_events')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_dropoff_confirmations');
    }
};
