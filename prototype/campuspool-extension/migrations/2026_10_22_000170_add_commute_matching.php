<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('mobility_commute_demo_trips',function(Blueprint$t){$t->boolean('is_publication')->default(false)->index();$t->string('direction',12)->nullable();});
  // Route/fare consent snapshots only. Trip/Passenger remain lifecycle and seat authority.
  Schema::create('mobility_commute_matches',function(Blueprint$t){$t->string('match_key',64)->primary();$t->unsignedInteger('trip_id')->index();$t->unsignedInteger('rider_id')->index();$t->string('profile_hash',64);$t->json('journey');$t->integer('fare_minor');$t->integer('base_fare_minor');$t->integer('surcharge_minor');$t->dateTime('pickup_at');$t->timestamp('expires_at');$t->timestamps();});
  Schema::create('mobility_commute_booking_terms',function(Blueprint$t){$t->unsignedInteger('booking_id')->primary();$t->string('match_key',64)->index();$t->timestamps();});
 }
 public function down():void {Schema::dropIfExists('mobility_commute_booking_terms');Schema::dropIfExists('mobility_commute_matches');Schema::table('mobility_commute_demo_trips',fn(Blueprint$t)=>$t->dropColumn(['is_publication','direction']));}
};
