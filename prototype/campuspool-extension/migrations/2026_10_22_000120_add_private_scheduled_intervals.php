<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('mobility_private_scheduled_bindings', function (Blueprint $t): void {
            $t->json('interval_snapshot')->nullable();
            $t->string('interval_hash', 64)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('mobility_private_scheduled_bindings', fn (Blueprint $t) => $t->dropColumn(['interval_snapshot', 'interval_hash']));
    }
};
