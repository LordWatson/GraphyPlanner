<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step M-7.1 (dev plan Steps 2.7–2.8) — the AI control plane:
     *   - a per-org enable toggle (effective flag = `config('ai.enabled')` AND this), default off so
     *     a new org opts in explicitly; the OrganizationFactory defaults it on for the test suite;
     *   - a per-org monthly token budget (null = unlimited) the `AiUsageGuard` enforces;
     *   - real token counts on `ai_usage_logs` so text spend is metered per token, not just per call.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(false)->after('ai_monthly_image_quota');
            // Null = unlimited. Counted against this calendar month's recorded text tokens.
            $table->unsignedInteger('ai_monthly_token_budget')->nullable()->after('ai_enabled');
        });

        Schema::table('ai_usage_logs', function (Blueprint $table) {
            // Null for calls where the vendor returned no usage block (or the Null provider, AI off).
            $table->unsignedInteger('prompt_tokens')->nullable()->after('duration_ms');
            $table->unsignedInteger('completion_tokens')->nullable()->after('prompt_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropColumn(['prompt_tokens', 'completion_tokens']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['ai_enabled', 'ai_monthly_token_budget']);
        });
    }
};
