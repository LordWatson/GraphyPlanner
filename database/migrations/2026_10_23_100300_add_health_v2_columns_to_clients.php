<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step M-3.4 / I-6.1 — client health v2.
 *
 * - `health_reasons`: every reason behind the stored health, heaviest first
 *   (`[{key, tone, weight, label, owner_only}]`), so the top one shows and the rest are on hover.
 *   Owner-only reasons (overdue invoices) are filtered out for everyone else at render time.
 * - `target_posts_per_week`: the posting cadence agreed with the client; health flags a month
 *   that falls well short of it. Null = no target, no cadence check.
 *
 * Existing rows get their reasons on the next recompute (the hourly sweep).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->json('health_reasons')->nullable()->after('health_reason');
            $table->unsignedTinyInteger('target_posts_per_week')->nullable()->after('approval_lead_days');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['health_reasons', 'target_posts_per_week']);
        });
    }
};
