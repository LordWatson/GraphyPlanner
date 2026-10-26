<?php

namespace Tests\Feature\Mcp;

use App\Actions\Mcp\CreateMcpTokenAction;
use App\Models\Client;
use App\Models\McpToken;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step MCP-1.2 — authentication, tenancy and per-token org resolution for the remote HTTP
 * transport. Proves the three "done when" guarantees: an unauthenticated `/mcp` call is rejected,
 * an authenticated one resolves to exactly one org, and `list_clients` returns ONLY that token's
 * org — the two-org isolation proof (mirrors the review-portal isolation test).
 */
class McpAuthTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/mcp';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mcp.http.enabled', true);
    }

    public function test_an_unauthenticated_post_is_rejected_with_a_bearer_challenge(): void
    {
        $response = $this->postJson(self::ENDPOINT, $this->callListClientsPayload());

        $response->assertStatus(401);
        $this->assertStringContainsString('Bearer', (string) $response->headers->get('WWW-Authenticate'));
    }

    public function test_an_unknown_bearer_token_is_rejected(): void
    {
        $this->withToken('not-a-real-token')
            ->postJson(self::ENDPOINT, $this->callListClientsPayload())
            ->assertStatus(401);
    }

    public function test_a_revoked_token_is_rejected(): void
    {
        $org = Organization::factory()->create();
        ['token' => $token, 'model' => $model] = app(CreateMcpTokenAction::class)($org, 'Revoked connection');
        $model->forceFill(['revoked_at' => now()])->save();

        $this->withToken($token)
            ->postJson(self::ENDPOINT, $this->callListClientsPayload())
            ->assertStatus(401);
    }

    public function test_list_clients_returns_only_the_tokens_organization(): void
    {
        $orgA = Organization::factory()->create(['name' => 'Agency Alpha']);
        $orgB = Organization::factory()->create(['name' => 'Agency Beta']);

        Client::factory()->for($orgA, 'organization')->create(['name' => 'Alpha Client']);
        Client::factory()->for($orgB, 'organization')->create(['name' => 'Beta Client']);

        ['token' => $tokenA] = app(CreateMcpTokenAction::class)($orgA, 'Alpha connection');
        ['token' => $tokenB] = app(CreateMcpTokenAction::class)($orgB, 'Beta connection');

        // Org A's token sees only org A's client — never org B's.
        $this->withToken($tokenA)
            ->postJson(self::ENDPOINT, $this->callListClientsPayload())
            ->assertOk()
            ->assertSee('Alpha Client', false)
            ->assertDontSee('Beta Client', false);

        // And symmetrically for org B. Cross-org access is impossible by construction.
        $this->withToken($tokenB)
            ->postJson(self::ENDPOINT, $this->callListClientsPayload())
            ->assertOk()
            ->assertSee('Beta Client', false)
            ->assertDontSee('Alpha Client', false);
    }

    public function test_an_authenticated_call_stamps_last_used_at(): void
    {
        $org = Organization::factory()->create();
        ['token' => $token, 'model' => $model] = app(CreateMcpTokenAction::class)($org, 'Active connection');

        $this->assertNull($model->last_used_at);

        $this->withToken($token)
            ->postJson(self::ENDPOINT, $this->callListClientsPayload())
            ->assertOk();

        $this->assertNotNull($model->fresh()->last_used_at);
    }

    public function test_the_create_action_never_persists_the_plain_token(): void
    {
        $org = Organization::factory()->create();
        ['token' => $plain, 'model' => $model] = app(CreateMcpTokenAction::class)($org, 'Secret-safety connection');

        // Only the SHA-256 is stored; the plain token never touches the database.
        $this->assertSame(McpToken::hashToken($plain), $model->token_hash);
        $this->assertDatabaseMissing('mcp_tokens', ['token_hash' => $plain]);
    }

    /**
     * A legacy-style `tools/call` for `list_clients` (no `_meta`, no initialize handshake).
     *
     * @return array<string, mixed>
     */
    private function callListClientsPayload(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_clients',
                'arguments' => [],
            ],
        ];
    }
}
