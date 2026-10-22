<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::create('mobility_college_otp_rate_windows',function(Blueprint$t){$t->string('bucket_hash',64)->primary();$t->unsignedInteger('hits')->default(0);$t->timestamp('window_until');$t->timestamps();});}
 public function down():void{Schema::dropIfExists('mobility_college_otp_rate_windows');}
};
