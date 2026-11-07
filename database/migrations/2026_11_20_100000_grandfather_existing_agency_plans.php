<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step GTM-0.1 — paid agency tiers, without taking capacity off anyone who already has it.
 *
 * Before this step every agency org resolved to `agency_standard`, which was unlimited and free.
 * `agency_standard` is now a £99/15-brand tier, so every agency org that exists at this point is
 * pinned to `agency_legacy` (same unlimited plan under a new, non-public key) and given a
 * `plan_grandfathered_until` date 12 months out. `plans:end-grandfather` moves them onto
 * `agency_standard` when that date passes.
 *
 * Only orgs on the old unlimited plan are touched: an agency that already has an explicit paid plan
 * key keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('plan_grandfathered_until')->nullable()->after('plan');
        });

        DB::table('organizations')
            ->where('type', 'agency')
            ->where(fn ($query) => $query->whereNull('plan')->orWhere('plan', 'agency_standard'))
            ->update([
                'plan' => 'agency_legacy',
                'plan_grandfathered_until' => now()->addYear(),
            ]);
    }

    public function down(): void
    {
        DB::table('organizations')
            ->where('type', 'agency')
            ->where('plan', 'agency_legacy')
            ->update(['plan' => 'agency_standard']);

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('plan_grandfathered_until');
        });
    }
};
