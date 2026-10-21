<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step M-3.1 (C-7.3 note) — a wrap report freezes engagement (totals, rate, follower growth, top
 * posts) alongside its KPI snapshot when shared. Reports shared before this stay as they were
 * (null: no engagement section), so a client never sees numbers change after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_reports', function (Blueprint $table) {
            $table->json('engagement_snapshot')->nullable()->after('kpi_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_reports', function (Blueprint $table) {
            $table->dropColumn('engagement_snapshot');
        });
    }
};
