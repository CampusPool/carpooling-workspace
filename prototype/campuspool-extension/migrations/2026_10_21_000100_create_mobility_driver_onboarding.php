<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_driver_onboardings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('host_application_id');
            $table->unsignedInteger('car_id');
            $table->string('vehicle_type', 8);
            $table->string('vehicle_fingerprint', 64);
            $table->string('profile_display_name', 120);
            $table->string('profile_phone', 32)->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_reason', 500)->nullable();
            $table->timestamps();
            $table->unique('host_application_id');
            $table->index(['user_id', 'status', 'id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('host_application_id')->references('id')->on('mobility_host_applications')->cascadeOnDelete();
            $table->foreign('car_id')->references('id')->on('cars')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('mobility_driver_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('onboarding_id');
            $table->string('document_type', 32);
            $table->unsignedInteger('version');
            $table->string('storage_path', 255);
            $table->string('mime_type', 64);
            $table->unsignedInteger('byte_size');
            $table->date('expires_at')->nullable();
            $table->string('status', 24)->default('submitted');
            $table->string('review_reason', 500)->nullable();
            $table->unsignedInteger('submitted_by');
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['onboarding_id', 'document_type', 'version'], 'driver_document_revision_unique');
            $table->index(['onboarding_id', 'document_type', 'status'], 'driver_document_review_index');
            $table->foreign('onboarding_id')->references('id')->on('mobility_driver_onboardings')->cascadeOnDelete();
            $table->foreign('submitted_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('mobility_driver_onboarding_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('onboarding_id');
            $table->unsignedInteger('actor_id')->nullable();
            $table->string('previous_status', 24)->nullable();
            $table->string('new_status', 24);
            $table->unsignedInteger('version');
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->foreign('onboarding_id')->references('id')->on('mobility_driver_onboardings')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_driver_onboarding_audits');
        Schema::dropIfExists('mobility_driver_documents');
        Schema::dropIfExists('mobility_driver_onboardings');
    }
};
