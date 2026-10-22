<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('mobility_commute_profiles',function(Blueprint $t){$t->unsignedInteger('user_id')->primary();$t->json('preferences');$t->timestamps();});
  Schema::create('mobility_commute_demo_actors',function(Blueprint $t){$t->unsignedInteger('user_id')->primary();$t->string('fixture_key')->unique();$t->timestamps();});
  Schema::create('mobility_commute_demo_trips',function(Blueprint $t){$t->unsignedInteger('trip_id')->primary();$t->unsignedInteger('audience_id');$t->string('fixture_key',64)->unique();$t->string('vehicle_type',8);$t->integer('fare_minor');$t->integer('base_fare_minor');$t->integer('surcharge_minor');$t->json('journey');$t->timestamps();});
  // These entries are nonredeemable demo credits, isolated from provider money.
  Schema::create('mobility_commute_demo_wallets',function(Blueprint $t){$t->unsignedInteger('user_id')->primary();$t->bigInteger('available_minor')->default(0);$t->timestamps();});
  Schema::create('mobility_commute_demo_holds',function(Blueprint $t){$t->unsignedInteger('booking_id')->primary();$t->unsignedInteger('rider_id');$t->unsignedInteger('driver_id');$t->integer('amount_minor');$t->string('state',16)->default('held');$t->timestamp('arrived_at')->nullable();$t->timestamp('release_at')->nullable();$t->timestamps();});
  Schema::create('mobility_commute_demo_entries',function(Blueprint $t){$t->id();$t->unsignedInteger('user_id');$t->string('kind',24);$t->bigInteger('amount_minor');$t->unsignedInteger('booking_id')->nullable();$t->timestamps();});
  Schema::create('mobility_commute_receipts',function(Blueprint $t){$t->id();$t->unsignedInteger('user_id');$t->string('key_hash',64);$t->string('request_hash',64);$t->json('response');$t->timestamps();$t->unique(['user_id','key_hash']);});
 }
 public function down():void {foreach(['mobility_commute_receipts','mobility_commute_demo_entries','mobility_commute_demo_holds','mobility_commute_demo_wallets','mobility_commute_demo_trips','mobility_commute_demo_actors','mobility_commute_profiles']as$t)Schema::dropIfExists($t);}
};
