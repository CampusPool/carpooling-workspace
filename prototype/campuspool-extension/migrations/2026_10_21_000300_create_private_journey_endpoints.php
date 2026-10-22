<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_private_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('user_id');
            $table->string('college_code', 64);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 11, 7)->nullable();
            $table->string('source', 24);
            $table->string('coordinate_hash', 64);
            $table->string('key_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('redacted_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key_hash'], 'private_endpoint_request_identity');
            $table->index(['expires_at', 'redacted_at'], 'private_endpoint_retention');
            $table->foreign('user_id')->references('id')->on('users');
        });
    }
    public function down(): void { Schema::dropIfExists('mobility_private_endpoints'); }
};
