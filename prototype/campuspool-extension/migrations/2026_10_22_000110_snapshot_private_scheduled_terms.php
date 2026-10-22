<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobility_private_scheduled_bindings', fn (Blueprint $t) => $t->json('terms_snapshot')->nullable());
        Schema::table('mobility_private_scheduled_publications', fn (Blueprint $t) => $t->unsignedInteger('source_fare_minor')->nullable());
    }

    public function down(): void
    {
        Schema::table('mobility_private_scheduled_bindings', fn (Blueprint $t) => $t->dropColumn('terms_snapshot'));
        Schema::table('mobility_private_scheduled_publications', fn (Blueprint $t) => $t->dropColumn('source_fare_minor'));
    }
};
