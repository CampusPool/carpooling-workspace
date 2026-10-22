<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mobility_college_email_challenges', function (Blueprint $table): void {
            $table->id();
            $table->uuid('challenge_id')->unique();
            $table->string('email', 320);
            $table->char('code_hash', 64);
            $table->string('ip_address', 45)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('resend_after');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['email', 'created_at']);
            $table->index(['expires_at', 'consumed_at']);
        });
        Schema::create('mobility_verified_identities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('provider', 32);
            $table->string('email', 320);
            $table->string('domain', 255);
            $table->timestamp('verified_at');
            $table->timestamps();
            $table->unique(['provider', 'email']);
            $table->unique(['provider', 'user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_verified_identities');
        Schema::dropIfExists('mobility_college_email_challenges');
    }
};
