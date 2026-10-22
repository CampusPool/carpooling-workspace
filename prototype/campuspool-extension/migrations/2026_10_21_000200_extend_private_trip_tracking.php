<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_live_shares', function (Blueprint $table): void {
            $table->uuid('driver_session_id')->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->decimal('accuracy_meters', 8, 2)->nullable();
        });
        Schema::create('mobility_tracking_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('trip_id');
            $table->unsignedInteger('actor_id');
            $table->string('kind', 12);
            $table->string('idempotency_key', 100)->charset('ascii')->collation('ascii_bin');
            $table->string('request_fingerprint', 64);
            $table->json('response_body');
            $table->timestamps();
            $table->unique(['trip_id', 'actor_id', 'kind', 'idempotency_key'], 'mobility_tracking_receipt_identity');
            $table->foreign('trip_id')->references('id')->on('trips');
            $table->foreign('actor_id')->references('id')->on('users');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('mobility_tracking_receipts');
        Schema::table('trip_live_shares', fn (Blueprint $table) => $table->dropColumn(['driver_session_id', 'consented_at', 'last_sequence', 'accuracy_meters']));
    }
};
