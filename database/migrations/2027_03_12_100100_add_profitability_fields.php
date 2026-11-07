<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client profitability: the two numbers that cannot be inferred.
 *
 * Everything else — posts produced, revision rounds, assets, inbox replies, reports — the app
 * already sees. What it cannot know is what an hour of the agency's time costs, and whether this
 * particular client's effort is being tracked for real.
 *
 * `blended_hourly_rate_minor` is per organization because it is one number for the whole business,
 * and asking for it once is the difference between a margin figure and a shrug.
 *
 * `estimated_hours_per_month` is per client and optional: the escape hatch for agencies that do
 * track time, whose own number beats anything inferred. Null means "use the inferred effort",
 * which is the normal case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedBigInteger('blended_hourly_rate_minor')->nullable()->after('default_currency');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedSmallInteger('estimated_hours_per_month')->nullable()->after('retainer_amount');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('estimated_hours_per_month');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('blended_hourly_rate_minor');
        });
    }
};
