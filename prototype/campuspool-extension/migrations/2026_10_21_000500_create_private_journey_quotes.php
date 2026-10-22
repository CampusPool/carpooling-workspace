<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_private_journey_quotes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('route_quote_id');
            $t->uuid('selection_id');
            $t->uuid('active_selection_id')->nullable();
            $t->unsignedInteger('driver_id');
            $t->unsignedInteger('rider_id');
            $t->uuid('origin_endpoint_id');
            $t->uuid('destination_endpoint_id');
            $t->string('origin_endpoint_hash', 64);
            $t->string('destination_endpoint_hash', 64);
            $t->string('source_terms_hash', 64);
            $t->unsignedInteger('source_selection_version');
            $t->unsignedInteger('version')->default(1);
            $t->string('state', 24);
            $t->string('quote_hash', 64);
            $t->string('create_key_hash', 64);
            $t->string('request_hash', 64);
            $t->timestamp('expires_at');
            $t->timestamp('driver_approved_at')->nullable();
            $t->string('driver_approval_hash', 64)->nullable();
            $t->timestamp('rider_consented_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['rider_id', 'create_key_hash'], 'private_quote_request_identity');
            $t->unique('active_selection_id', 'private_quote_one_active_selection');
            $t->unique('route_quote_id', 'private_quote_route_quote');
            $t->index(['selection_id', 'state']);
            $t->index(['driver_id', 'state', 'expires_at']);
            $t->index(['rider_id', 'state', 'expires_at']);
            $t->foreign('route_quote_id')->references('id')->on('mobility_route_quotes')->restrictOnDelete();
            $t->foreign('selection_id')->references('id')->on('mobility_pickup_selections')->restrictOnDelete();
            $t->foreign('active_selection_id')->references('id')->on('mobility_pickup_selections')->restrictOnDelete();
            $t->foreign('driver_id')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('rider_id')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('origin_endpoint_id')->references('id')->on('mobility_private_endpoints')->restrictOnDelete();
            $t->foreign('destination_endpoint_id')->references('id')->on('mobility_private_endpoints')->restrictOnDelete();
        });
        Schema::create('mobility_private_journey_quote_actions', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->uuid('quote_id');
            $t->unsignedInteger('actor_id');
            $t->string('action', 24);
            $t->string('key_hash', 64);
            $t->string('request_hash', 64);
            $t->timestamps();
            $t->unique(['actor_id', 'key_hash'], 'private_quote_action_identity');
            $t->foreign('quote_id')->references('id')->on('mobility_private_journey_quotes')->restrictOnDelete();
            $t->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_private_journey_quote_actions');
        Schema::dropIfExists('mobility_private_journey_quotes');
    }
};
