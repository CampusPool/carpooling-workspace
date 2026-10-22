<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An additive migration also upgrades pilot databases whose initial
        // cohort migration ran before the audit invariant was introduced.
        if (! Schema::hasIndex('mobility_membership_audits', ['membership_id', 'version'], 'unique')) {
            Schema::table('mobility_membership_audits', function (Blueprint $table): void {
                $table->unique(['membership_id', 'version']);
            });
        }
        if (! Schema::hasIndex('mobility_host_application_audits', ['application_id', 'version'], 'unique')) {
            Schema::table('mobility_host_application_audits', function (Blueprint $table): void {
                $table->unique(['application_id', 'version']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('mobility_host_application_audits', function (Blueprint $table): void {
            $table->dropUnique(['application_id', 'version']);
        });
        Schema::table('mobility_membership_audits', function (Blueprint $table): void {
            $table->dropUnique(['membership_id', 'version']);
        });
    }
};
