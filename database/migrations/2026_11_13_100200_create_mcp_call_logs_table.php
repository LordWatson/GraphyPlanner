<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step MCP-1.5 — the MCP audit trail: one row per executed tool call. Records who (the resolving
 * `org_id` + the `mcp_token_id`), what (`tool`), the argument *keys* only (never the values — a
 * value could be a secret), the `outcome` and how long it took (`duration_ms`). This is the data the
 * MCP-7.1 usage/audit UI reads back. No secret, token or argument value is ever stored here.
 *
 * `mcp_token_id` is nullable + `nullOnDelete`: a token can be hard-deleted later while its audit
 * history stays. See .junie/plans/mcp-server.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('mcp_token_id')->nullable()->constrained('mcp_tokens')->nullOnDelete();
            $table->string('tool');
            $table->json('argument_keys');
            $table->string('outcome'); // 'success' | 'error'
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index('org_id');
            $table->index('mcp_token_id');
            $table->index(['org_id', 'tool']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_call_logs');
    }
};
