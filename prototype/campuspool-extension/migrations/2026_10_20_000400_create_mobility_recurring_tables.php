<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobility_route_quotes', function (Blueprint $table): void {
            $table->uuid('parent_quote_id')->nullable()->after('id');
            $table->index('parent_quote_id');
            $table->foreign('parent_quote_id')->references('id')->on('mobility_route_quotes')->restrictOnDelete();
        });
        Schema::create('mobility_recurring_programs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('car_id');
            $table->uuid('source_quote_id')->unique();
            $table->json('route_snapshot');
            $table->unsignedSmallInteger('weekly_schedule');
            $table->string('departure_time', 5);
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedSmallInteger('total_seats');
            $table->string('vehicle_type', 8);
            $table->unsignedBigInteger('max_fare_minor');
            $table->string('pricing_version', 64);
            $table->string('description', 600)->default('');
            $table->string('state', 16)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->date('generated_through')->nullable();
            $table->string('last_generation_code', 64)->nullable();
            $table->char('create_key_hash', 64);
            $table->char('request_hash', 64);
            $table->timestamps();
            $table->unique(['user_id', 'create_key_hash']);
            $table->index(['user_id', 'state']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('car_id')->references('id')->on('cars')->restrictOnDelete();
            $table->foreign('source_quote_id')->references('id')->on('mobility_route_quotes')->restrictOnDelete();
        });
        Schema::create('mobility_recurring_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->uuid('program_id');
            $table->date('service_date');
            $table->unsignedInteger('trip_id')->unique();
            $table->uuid('quote_id')->unique();
            $table->unsignedInteger('program_version');
            $table->json('config_snapshot');
            $table->timestamps();
            $table->unique(['program_id', 'service_date']);
            $table->foreign('program_id')->references('id')->on('mobility_recurring_programs')->cascadeOnDelete();
            $table->foreign('trip_id')->references('id')->on('trips')->cascadeOnDelete();
            $table->foreign('quote_id')->references('id')->on('mobility_route_quotes')->restrictOnDelete();
        });
        Schema::create('mobility_recurring_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('program_id');
            $table->date('service_date');
            $table->string('action', 16)->default('skip');
            $table->string('reason', 500);
            $table->unsignedInteger('actor_id');
            $table->timestamps();
            $table->unique(['program_id', 'service_date']);
            $table->foreign('program_id')->references('id')->on('mobility_recurring_programs')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('mobility_recurring_actions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('program_id');
            $table->unsignedInteger('actor_id');
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('action', 16);
            $table->unsignedInteger('previous_version');
            $table->unsignedInteger('version');
            $table->string('reason', 500)->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->timestamps();
            $table->unique(['program_id', 'actor_id', 'key_hash']);
            $table->foreign('program_id')->references('id')->on('mobility_recurring_programs')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_recurring_actions');
        Schema::dropIfExists('mobility_recurring_exceptions');
        Schema::dropIfExists('mobility_recurring_occurrences');
        Schema::dropIfExists('mobility_recurring_programs');
        Schema::table('mobility_route_quotes', function (Blueprint $table): void {
            $table->dropForeign(['parent_quote_id']);
            $table->dropIndex(['parent_quote_id']);
            $table->dropColumn('parent_quote_id');
        });
    }
};
