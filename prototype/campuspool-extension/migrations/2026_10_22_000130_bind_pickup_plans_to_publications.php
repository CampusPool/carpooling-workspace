<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('mobility_pickup_plans', function (Blueprint $t): void {
            $t->uuid('source_publication_id')->nullable()->index();
            $t->string('source_publication_route_hash', 64)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('mobility_pickup_plans', function (Blueprint $t): void {
            $t->dropIndex(['source_publication_id']);
            $t->dropColumn(['source_publication_id', 'source_publication_route_hash']);
        });
    }
};
