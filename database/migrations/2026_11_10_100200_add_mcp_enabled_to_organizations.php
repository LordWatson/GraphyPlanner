<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step MCP-7.2 — the per-org MCP master switch. Default OFF: an org opts into exposing its data
     * and actions to AI assistants explicitly (the Owner flips it on from the "AI connections"
     * settings page, MCP-6.1). `AuthenticateMcp` rejects every `/mcp` call for an org whose switch is
     * off, so turning it off takes effect immediately and is the per-org kill switch. The
     * `OrganizationFactory` defaults it on for the test suite (mirroring `ai_enabled`).
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('mcp_enabled')->default(false)->after('ai_monthly_token_budget');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('mcp_enabled');
        });
    }
};
