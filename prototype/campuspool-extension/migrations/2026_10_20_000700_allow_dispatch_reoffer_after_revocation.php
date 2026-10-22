<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobility_dispatch_offers', function (Blueprint $table): void {
            $table->unique(['request_id', 'driver_id', 'attempt'], 'dispatch_driver_offer_attempt');
            $table->dropUnique(['request_id', 'driver_id']);
        });
    }

    public function down(): void
    {
        Schema::table('mobility_dispatch_offers', function (Blueprint $table): void {
            // Rollback fails closed if retained offer history cannot fit the old uniqueness rule.
            $table->unique(['request_id', 'driver_id']);
            $table->dropUnique('dispatch_driver_offer_attempt');
        });
    }
};
