<?php

namespace Tests\Feature\Mcp;

use App\Actions\Mcp\CreateMcpTokenAction;
use App\Actions\Mcp\RecordMcpCallAction;
use App\Models\McpCallLog;
use App\Models\Organization;
use App\Support\Mcp\McpContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Step MCP-1.5 — audit logging. Proves the "done when": one tool call writes exactly one
 * `mcp_call_logs` row, the row (and the log line) never carry the token or an argument value, a
 * failed call is recorded as such, and the audit write never breaks the tool call.
 */
class McpAuditTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/mcp';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mcp.http.enabled', true);
    }

    public function test_a_successful_call_writes_exactly_one_audit_row_without_the_secret(): void
    {
        $org = Organization::factory()->create();
        ['token' => $token, 'model' => $model] = app(CreateMcpTokenAction::class)($org, 'Audited connection');

        $this->withToken($token)->postJson(self::ENDPOINT, $this->payload())->assertOk();

        $this->assertSame(1, McpCallLog::query()->count());

        $log = McpCallLog::query()->sole();
        $this->assertSame($org->id, $log->org_id);
        $this->assertSame($model->id, $log->mcp_token_id);
        $this->assertSame('list_clients', $log->tool);
        $this->assertSame('success', $log->outcome);
        $this->assertSame([], $log->argument_keys);

        // The plain token must never appear in any audit column.
        $this->assertStringNotContainsString($token, (string) json_encode($log->getAttributes()));
    }

    public function test_a_scope_denied_call_is_audited_as_an_error(): void
    {
        $org = Organization::factory()->create();
        // A token without the `read` scope cannot list clients — but the attempt is still audited.
        ['token' => $token] = app(CreateMcpTokenAction::class)($org, 'AI-only connection', ['ai']);

        $this->withToken($token)->postJson(self::ENDPOINT, $this->payload())->assertOk();

        $log = McpCallLog::query()->sole();
        $this->assertSame('error', $log->outcome);
        $this->assertSame('list_clients', $log->tool);
    }

    public function test_the_audit_write_never_breaks_the_call_and_logs_its_own_failure(): void
    {
        // The call's own log line (info), then the swallowed-write log line (error).
        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once();

        // An unsaved organization has a null id, so the audit insert violates the not-null org_id
        // and throws — the action must swallow it (log-and-continue), never rethrow.
        $context = new McpContext(
            organization: Organization::factory()->make(),
            scopes: ['read'],
        );

        app(RecordMcpCallAction::class)($context, 'list_clients', [], succeeded: true, durationMs: 3);

        $this->assertSame(0, McpCallLog::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'list_clients', 'arguments' => []],
        ];
    }
}
