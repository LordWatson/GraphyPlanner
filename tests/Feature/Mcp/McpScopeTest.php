<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\ListClientsTool;
use App\Models\Organization;
use App\Support\Mcp\McpContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Mcp\SampleWriteTool;
use Tests\Fixtures\Mcp\ScopeTestServer;
use Tests\TestCase;

/**
 * Step MCP-1.3 — capability-scope gating. Proves the "done when": a read-only token calling a write
 * tool is rejected with a clear scope error, and the gate covers both scope-present and
 * scope-absent for a sample tool — all *before* the tool's Action would run.
 */
class McpScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind the per-request MCP identity the way `AuthenticateMcp` does on the HTTP transport, so the
     * in-process harness exercises the real `McpContext::current()` path the gate reads.
     *
     * @param  list<string>  $scopes
     */
    private function actAsToken(array $scopes): Organization
    {
        $org = Organization::factory()->create();

        $this->app->instance(McpContext::class, new McpContext(
            organization: $org,
            scopes: $scopes,
        ));

        return $org;
    }

    public function test_a_read_only_token_is_rejected_from_a_write_tool(): void
    {
        $this->actAsToken(['read']);

        ScopeTestServer::tool(SampleWriteTool::class, [])
            ->assertHasErrors()
            ->assertSee('write:drafts')
            ->assertDontSee('wrote a draft');
    }

    public function test_a_write_tool_runs_when_the_token_has_the_scope(): void
    {
        $this->actAsToken(['read', 'write:drafts']);

        ScopeTestServer::tool(SampleWriteTool::class, [])
            ->assertOk()
            ->assertSee('wrote a draft');
    }

    public function test_a_tool_runs_with_no_context_on_the_trusted_local_transport(): void
    {
        // No McpContext bound — the local/stdio + in-process developer transport is trusted, so the
        // scope gate is a no-op (HTTP always has a context; AuthenticateMcp 401s before this).
        ScopeTestServer::tool(SampleWriteTool::class, [])
            ->assertOk()
            ->assertSee('wrote a draft');
    }

    public function test_list_clients_requires_the_read_scope(): void
    {
        // A token carrying only the AI scope (no read) cannot even list clients.
        $this->actAsToken(['ai']);

        ScopeTestServer::tool(ListClientsTool::class, [])
            ->assertHasErrors()
            ->assertSee('read');
    }

    public function test_list_clients_runs_with_the_read_scope(): void
    {
        $this->actAsToken(['read']);

        ScopeTestServer::tool(ListClientsTool::class, [])
            ->assertOk();
    }
}
