<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_pool_publications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->uuid('quote_id')->unique();
            $table->unsignedInteger('trip_id')->unique();
            $table->timestamps();
            $table->unique(['user_id', 'key_hash']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('quote_id')->references('id')->on('mobility_route_quotes')->cascadeOnDelete();
            $table->foreign('trip_id')->references('id')->on('trips')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_pool_publications');
    }
};
