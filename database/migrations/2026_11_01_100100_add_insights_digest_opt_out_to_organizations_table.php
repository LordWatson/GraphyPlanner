<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step B-8.2 — a business workspace's weekly Insights email digest is on by default; this column is
 * the Owner's opt-out (the agency surface never reads it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('insights_digest_opt_out')->default(false)->after('require_post_review');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('insights_digest_opt_out');
        });
    }
};
