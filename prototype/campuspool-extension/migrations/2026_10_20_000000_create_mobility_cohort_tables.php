<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_memberships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('college_code', 64);
            $table->string('affiliation', 16);
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_reason', 500)->nullable();
            $table->timestamps();
            $table->unique('user_id');
            $table->index(['college_code', 'status', 'id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('mobility_membership_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('membership_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('actor_id')->nullable();
            $table->string('previous_status', 16)->nullable();
            $table->string('new_status', 16);
            $table->unsignedInteger('version');
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('membership_id')->references('id')->on('mobility_memberships')->cascadeOnDelete();
        });
        Schema::create('mobility_host_applications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('car_id');
            $table->string('vehicle_type', 8);
            $table->string('vehicle_fingerprint', 64);
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'car_id']);
            $table->index(['user_id', 'status']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('car_id')->references('id')->on('cars')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('mobility_host_application_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->unsignedInteger('actor_id')->nullable();
            $table->string('previous_status', 16)->nullable();
            $table->string('new_status', 16);
            $table->unsignedInteger('version');
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('application_id')->references('id')->on('mobility_host_applications')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_host_application_audits');
        Schema::dropIfExists('mobility_host_applications');
        Schema::dropIfExists('mobility_membership_audits');
        Schema::dropIfExists('mobility_memberships');
    }
};
