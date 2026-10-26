<?php

namespace Tests\Feature\Mcp;

use App\Actions\Mcp\CreateMcpTokenAction;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step MCP-1.4 — per-connection rate limiting. Proves the "done when": once the per-minute budget is
 * spent, a tool call is refused with a clean MCP error (HTTP 200 + an `isError` result), never a
 * 500, and a call back under the budget succeeds again.
 */
class McpRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/mcp';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mcp.http.enabled', true);
        config()->set('mcp.rate_limit.per_minute', 2);
    }

    public function test_calls_over_the_per_minute_budget_are_refused_with_a_clean_mcp_error(): void
    {
        $org = Organization::factory()->create();
        ['token' => $token] = app(CreateMcpTokenAction::class)($org, 'Busy connection');

        // The first two calls are within budget.
        $this->withToken($token)->postJson(self::ENDPOINT, $this->payload())->assertOk();
        $this->withToken($token)->postJson(self::ENDPOINT, $this->payload())->assertOk();

        // The third is over budget: a clean MCP error, not a 500.
        $this->withToken($token)
            ->postJson(self::ENDPOINT, $this->payload())
            ->assertOk()
            ->assertSee('Rate limit reached', false);
    }

    public function test_the_limit_is_disabled_when_the_budget_is_zero(): void
    {
        config()->set('mcp.rate_limit.per_minute', 0);

        $org = Organization::factory()->create();
        ['token' => $token] = app(CreateMcpTokenAction::class)($org, 'Unlimited connection');

        for ($i = 0; $i < 5; $i++) {
            $this->withToken($token)
                ->postJson(self::ENDPOINT, $this->payload())
                ->assertOk()
                ->assertDontSee('Rate limit', false);
        }
    }

    public function test_the_limit_is_keyed_per_token_so_one_connection_does_not_throttle_another(): void
    {
        $org = Organization::factory()->create();
        ['token' => $tokenA] = app(CreateMcpTokenAction::class)($org, 'Connection A');
        ['token' => $tokenB] = app(CreateMcpTokenAction::class)($org, 'Connection B');

        // Spend token A's budget.
        $this->withToken($tokenA)->postJson(self::ENDPOINT, $this->payload())->assertOk();
        $this->withToken($tokenA)->postJson(self::ENDPOINT, $this->payload())->assertOk();
        $this->withToken($tokenA)->postJson(self::ENDPOINT, $this->payload())->assertSee('Rate limit reached', false);

        // Token B still has its own full budget.
        $this->withToken($tokenB)
            ->postJson(self::ENDPOINT, $this->payload())
            ->assertOk()
            ->assertDontSee('Rate limit', false);
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
