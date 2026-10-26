<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step MCP-1.2 — bearer tokens for the remote MCP transport. Each token resolves a request to
 * exactly one organization (`org_id`) and carries a capability scope set (`scopes`), so an AI
 * assistant connected with it can only ever see/act on that one tenant. Only the SHA-256 of the
 * token is stored (`token_hash`); the plain token is shown once at creation and never persisted
 * or logged. A token can optionally be attributed to a user (`user_id`) for the audit trail.
 * Revocation is a soft `revoked_at` stamp (keeps the audit history, unlike a hard delete).
 *
 * Scope *gating* (the `McpScope` enum + per-tool checks) lands in MCP-1.2's sibling step MCP-1.3;
 * this migration just stores the scope set. See .junie/plans/mcp-server.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('org_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tokens');
    }
};
