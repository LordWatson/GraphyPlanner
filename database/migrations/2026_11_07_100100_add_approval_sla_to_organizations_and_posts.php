<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step M-6.5 (Phase 9) — approval SLA & reminders. `organizations.approval_sla_hours` is the
     * window (default 48h) after which a post stuck in `waiting_client` / `changes_requested` is
     * "overdue"; `posts.last_reminded_at` throttles the daily reminder to once per 24h per post.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedSmallInteger('approval_sla_hours')->nullable()->default(48)->after('default_currency');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->timestamp('last_reminded_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('approval_sla_hours');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('last_reminded_at');
        });
    }
};
