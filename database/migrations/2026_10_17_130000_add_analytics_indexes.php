<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 17.3 — composite indexes for organization analytics. Every query filters on a single
     * org's posts/invoices, then on a status and a time window. (`ai_usage_logs (org_id,
     * created_at)` already exists from Step 16.11.)
     */
    public function up(): void
    {
        Schema::table('post_activity_logs', function (Blueprint $table) {
            $table->index(['post_id', 'to_status', 'created_at'], 'post_activity_logs_post_status_created_index');
            $table->index(['to_status', 'created_at'], 'post_activity_logs_status_created_index');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->index(['org_id', 'status'], 'posts_org_status_index');
            $table->index(['org_id', 'created_at'], 'posts_org_created_index');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['org_id', 'status', 'issue_date'], 'invoices_org_status_issue_index');
        });
    }

    public function down(): void
    {
        Schema::table('post_activity_logs', function (Blueprint $table) {
            $table->dropIndex('post_activity_logs_post_status_created_index');
            $table->dropIndex('post_activity_logs_status_created_index');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_org_status_index');
            $table->dropIndex('posts_org_created_index');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_org_status_issue_index');
        });
    }
};
