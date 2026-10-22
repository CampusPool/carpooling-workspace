<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mobility_trip_conversations', function (Blueprint $t): void {
            $t->unsignedInteger('trip_id')->primary();
            $t->unsignedInteger('conversation_id')->unique();
            $t->timestamp('created_at');
        });
        Schema::create('mobility_user_blocks', function (Blueprint $t): void {
            $t->unsignedInteger('actor_id'); $t->unsignedInteger('target_id');
            $t->boolean('blocked'); $t->timestamps();
            $t->primary(['actor_id', 'target_id']);
        });
        Schema::create('mobility_communication_receipts', function (Blueprint $t): void {
            $t->id(); $t->unsignedInteger('trip_id'); $t->unsignedInteger('actor_id');
            $t->string('kind', 16); $t->string('key_hash', 64); $t->string('fingerprint', 64);
            $t->json('response'); $t->timestamp('created_at');
            $t->unique(['actor_id', 'kind', 'key_hash'], 'mob_comms_receipt_unique');
        });
        Schema::create('mobility_trip_message_reads', function (Blueprint $t): void {
            $t->unsignedInteger('trip_id'); $t->unsignedInteger('user_id');
            $t->unsignedInteger('through_id'); $t->timestamp('updated_at');
            $t->primary(['trip_id', 'user_id']);
        });
    }
    public function down(): void
    {
        foreach (['mobility_trip_message_reads', 'mobility_communication_receipts', 'mobility_user_blocks', 'mobility_trip_conversations'] as $table) Schema::dropIfExists($table);
    }
};
