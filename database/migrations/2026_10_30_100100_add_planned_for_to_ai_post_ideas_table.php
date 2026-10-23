<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step B-6.3 — "Plan my week" items reuse `ai_post_ideas`, marked with the local day they're
 * planned for (`planned_for`). A null value is an ordinary recommended idea; a date means the idea
 * was generated to fill a posting-goal gap and belongs to the weekly plan, which makes generation
 * idempotent (one plan item per brand per local day) and lets the plan list itself cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_post_ideas', function (Blueprint $table) {
            $table->date('planned_for')->nullable()->after('suggested_post_at');
            $table->index(['client_id', 'planned_for']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_post_ideas', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'planned_for']);
            $table->dropColumn('planned_for');
        });
    }
};
