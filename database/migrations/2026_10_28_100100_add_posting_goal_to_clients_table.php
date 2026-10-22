<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step B-4.5 — the primary brand's posting goal: how many posts a week it wants to put out (and,
 * optionally, which days it prefers). Stored as JSON so it can hold a per-platform breakdown later
 * without another migration. It drives the "Gaps" section of the Today dashboard (B-4.3), the
 * calendar's ghost slots, and the AI "Plan my week" generator (B-6.3).
 *
 * Nullable so every existing (agency) client is untouched — the single-integer
 * `target_posts_per_week` (health input) stays as it is; this richer goal is set in the business
 * onboarding wizard or on `/brand`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'posting_goal')) {
                // `json` so both MySQL 8 and the SQLite test run store a structured value; cast to
                // an array on the model.
                $table->json('posting_goal')->nullable()->after('target_posts_per_week');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'posting_goal')) {
                $table->dropColumn('posting_goal');
            }
        });
    }
};
