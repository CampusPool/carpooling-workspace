<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_cash_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('trip_id');
            $table->unsignedInteger('booking_id')->unique();
            $table->unsignedInteger('rider_id');
            $table->unsignedInteger('driver_id');
            $table->char('currency', 3)->collation('ascii_bin');
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('commission_amount');
            $table->timestamp('rider_confirmed_at')->nullable();
            $table->timestamp('driver_confirmed_at')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->timestamps();
            $table->foreign('trip_id')->references('id')->on('trips')->restrictOnDelete();
            $table->foreign('booking_id')->references('id')->on('trip_passengers')->restrictOnDelete();
            $table->foreign('rider_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('driver_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('mobility_cash_receipt_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('trip_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('actor_id');
            $table->string('action', 24)->collation('ascii_bin');
            $table->string('idempotency_key', 100)->collation('ascii_bin');
            $table->char('request_fingerprint', 64)->collation('ascii_bin');
            $table->json('response_body');
            $table->timestamps();
            $table->unique(['trip_id', 'booking_id', 'actor_id', 'idempotency_key'], 'mobility_cash_receipt_event_key');
            $table->foreign('trip_id')->references('id')->on('trips')->restrictOnDelete();
            $table->foreign('booking_id')->references('id')->on('trip_passengers')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_cash_receipt_events');
        Schema::dropIfExists('mobility_cash_receipts');
    }
};
