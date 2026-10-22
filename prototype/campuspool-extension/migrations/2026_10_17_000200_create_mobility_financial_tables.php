<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_fare_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('trip_id');
            $table->unsignedInteger('booking_id')->unique();
            $table->char('currency', 3);
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('commission_amount');
            $table->unsignedBigInteger('driver_amount');
            $table->timestamps();
            $table->foreign('trip_id')->references('id')->on('trips')->restrictOnDelete();
            $table->foreign('booking_id')->references('id')->on('trip_passengers')->restrictOnDelete();
        });
        Schema::create('mobility_payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('trip_id');
            $table->unsignedInteger('booking_id');
            $table->string('provider', 64)->collation('ascii_bin');
            $table->string('environment', 16)->collation('ascii_bin');
            $table->string('order_reference', 160)->collation('ascii_bin');
            $table->string('payment_reference', 160)->nullable()->collation('ascii_bin');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->collation('ascii_bin');
            $table->string('status', 32);
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'environment', 'order_reference'], 'mobility_payment_order');
            $table->unique(['provider', 'environment', 'payment_reference'], 'mobility_payment_reference');
            $table->foreign('trip_id')->references('id')->on('trips')->restrictOnDelete();
            $table->foreign('booking_id')->references('id')->on('trip_passengers')->restrictOnDelete();
        });
        Schema::create('mobility_provider_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64)->collation('ascii_bin');
            $table->string('environment', 16)->collation('ascii_bin');
            $table->string('event_id', 160)->collation('ascii_bin');
            $table->string('event_type', 64);
            $table->char('payload_hash', 64)->collation('ascii_bin');
            $table->timestamps();
            $table->unique(['provider', 'environment', 'event_id'], 'mobility_provider_event_identity');
        });
        Schema::create('mobility_financial_postings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('trip_id');
            $table->unsignedInteger('booking_id');
            $table->string('entry_group', 120);
            $table->string('account', 64);
            $table->string('side', 8);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->timestamps();
            $table->unique(['entry_group', 'account', 'side'], 'mobility_posting_idempotency');
            $table->foreign('trip_id')->references('id')->on('trips')->restrictOnDelete();
            $table->foreign('booking_id')->references('id')->on('trip_passengers')->restrictOnDelete();
        });
        Schema::create('mobility_payment_blocks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('trip_id');
            $table->unsignedInteger('booking_id');
            $table->string('reason', 64);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['booking_id', 'reason'], 'mobility_payment_block_identity');
            $table->foreign('trip_id')->references('id')->on('trips')->restrictOnDelete();
            $table->foreign('booking_id')->references('id')->on('trip_passengers')->restrictOnDelete();
        });
        Schema::create('mobility_payout_allocations', function (Blueprint $table): void {
            $table->string('beneficiary_reference', 160)->collation('ascii_bin');
            $table->id();
            $table->unsignedInteger('trip_id');
            $table->unsignedInteger('booking_id')->unique();
            $table->unsignedBigInteger('payment_attempt_id');
            $table->unsignedInteger('driver_id');
            $table->unsignedBigInteger('amount');
            $table->string('status', 32);
            $table->timestamps();
            $table->foreign('trip_id')->references('id')->on('trips')->restrictOnDelete();
            $table->foreign('booking_id')->references('id')->on('trip_passengers')->restrictOnDelete();
            $table->foreign('payment_attempt_id')->references('id')->on('mobility_payment_attempts')->restrictOnDelete();
            $table->foreign('driver_id')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('mobility_payout_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('allocation_id')->unique();
            $table->string('idempotency_key', 160)->unique();
            $table->string('status', 32);
            $table->timestamps();
            $table->foreign('allocation_id')->references('id')->on('mobility_payout_allocations')->restrictOnDelete();
        });
        Schema::create('mobility_payment_beneficiaries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('provider', 64)->collation('ascii_bin');
            $table->string('environment', 16)->collation('ascii_bin');
            $table->string('beneficiary_reference', 160)->collation('ascii_bin');
            $table->string('status', 32);
            $table->boolean('hold_capable')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider', 'environment'], 'mobility_beneficiary_identity');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['mobility_payment_beneficiaries', 'mobility_payout_outbox', 'mobility_payout_allocations', 'mobility_payment_blocks', 'mobility_financial_postings', 'mobility_provider_events', 'mobility_payment_attempts', 'mobility_fare_snapshots'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
