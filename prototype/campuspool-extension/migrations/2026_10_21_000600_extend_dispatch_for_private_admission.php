<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobility_dispatch_requests', function (Blueprint $table): void {
            $table->string('dispatch_scope', 16)->default('catalog')->after('vehicle_type');
            $table->unsignedInteger('target_driver_id')->nullable()->after('dispatch_scope');
            $table->unsignedBigInteger('pickup_point_id')->nullable()->change();
            $table->uuid('private_journey_quote_id')->nullable()->after('pickup_point_id');
            $table->string('private_source_hash', 64)->nullable()->after('private_journey_quote_id');
            $table->foreign('target_driver_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('private_journey_quote_id')->references('id')->on('mobility_private_journey_quotes')->restrictOnDelete();
            $table->index(['dispatch_scope', 'target_driver_id', 'state'], 'mob_dispatch_private_target_idx');
        });
        Schema::table('mobility_dispatch_availability', function (Blueprint $table): void {
            $table->string('dispatch_scope', 16)->default('catalog')->after('vehicle_fingerprint');
            $table->unsignedBigInteger('pickup_point_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mobility_dispatch_availability', function (Blueprint $table): void {
            $table->dropColumn('dispatch_scope');
            $table->unsignedBigInteger('pickup_point_id')->nullable(false)->change();
        });
        Schema::table('mobility_dispatch_requests', function (Blueprint $table): void {
            $table->dropForeign(['target_driver_id']);
            $table->dropForeign(['private_journey_quote_id']);
            $table->dropIndex('mob_dispatch_private_target_idx');
            $table->dropColumn(['dispatch_scope', 'target_driver_id', 'private_journey_quote_id', 'private_source_hash']);
            $table->unsignedBigInteger('pickup_point_id')->nullable(false)->change();
        });
    }
};
