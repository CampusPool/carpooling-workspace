<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_trip_report_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('trip_id');
            $table->unsignedInteger('actor_id');
            $table->string('idempotency_key', 100)->collation('ascii_bin');
            $table->char('request_fingerprint', 64)->collation('ascii_bin');
            $table->unsignedInteger('ticket_id');
            $table->timestamps();
            $table->unique(['trip_id', 'actor_id', 'idempotency_key'], 'mobility_trip_report_key');
            $table->foreign('trip_id')->references('id')->on('trips')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('ticket_id')->references('id')->on('support_tickets')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_trip_report_requests');
    }
};
