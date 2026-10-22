<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void { Schema::table('trip_live_shares', fn (Blueprint $table) => $table->timestamp('sample_recorded_at', 3)->nullable()); }
    public function down(): void { Schema::table('trip_live_shares', fn (Blueprint $table) => $table->dropColumn('sample_recorded_at')); }
};
