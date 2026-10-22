<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_pickup_points', function (Blueprint $table): void {
            $table->id();
            $table->string('college_code', 64);
            $table->unsignedInteger('canonical_node_id');
            $table->unsignedInteger('version');
            $table->string('name', 160);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->json('vehicle_modes');
            $table->boolean('active')->default(true);
            $table->timestamp('verified_at')->nullable();
            $table->boolean('synthetic_only')->default(false);
            $table->timestamps();
            $table->index(['college_code', 'active', 'id']);
            $table->index(['canonical_node_id', 'version']);
            $table->foreign('canonical_node_id')->references('id')->on('nodes_geo')->restrictOnDelete();
        });

        Schema::create('mobility_route_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('user_id');
            $table->string('college_code', 64);
            $table->json('snapshot');
            $table->timestamp('expires_at');
            $table->unsignedInteger('consumed_trip_id')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
            $table->index(['college_code', 'expires_at']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('consumed_trip_id')->references('id')->on('trips')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_route_quotes');
        Schema::dropIfExists('mobility_pickup_points');
    }
};
