<?php

namespace Tests\Feature\Mcp;

use App\Actions\Mcp\CreateMcpTokenAction;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase MCP-6.1 / MCP-7.2 — the Owner-only "AI connections" settings surface and the per-org master
 * switch. Proves an Owner can issue a scoped token (shown once), revoke it, and toggle the org
 * switch; a non-Owner cannot; no token hash reaches the page props; and flipping the org switch off
 * makes every `/mcp` call for that org reject immediately.
 */
class McpConnectionSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The connections page sits behind `password.confirm` (it issues secrets); confirm it here.
        $this->confirmPassword();
    }

    public function test_owner_can_view_the_connections_page(): void
    {
        $user = User::factory()->role(Role::Owner)->create();

        $this->actingAs($user)
            ->get(route('mcp-connections.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/ai-connections')
                ->has('tokens')
                ->has('scopes')
                ->has('usage')
                ->where('settings.mcp_enabled', true));
    }

    public function test_non_owner_cannot_view_the_connections_page(): void
    {
        $user = User::factory()->role(Role::Strategist)->create();

        $this->actingAs($user)
            ->get(route('mcp-connections.edit'))
            ->assertForbidden();
    }

    public function test_owner_can_issue_a_scoped_token_shown_once(): void
    {
        $user = User::factory()->role(Role::Owner)->create();

        $response = $this->actingAs($user)->post(route('mcp-connections.store'), [
            'name' => 'Claude Desktop',
            'scopes' => ['read'],
        ]);

        $response->assertRedirect(route('mcp-connections.edit'));
        $response->assertSessionHas('newMcpToken');

        $this->assertDatabaseHas('mcp_tokens', [
            'org_id' => $user->org_id,
            'name' => 'Claude Desktop',
        ]);
    }

    public function test_owner_can_revoke_a_token(): void
    {
        $user = User::factory()->role(Role::Owner)->create();
        ['model' => $token] = app(CreateMcpTokenAction::class)($user->organization, 'Old connection', null, $user);

        $this->actingAs($user)
            ->delete(route('mcp-connections.destroy', $token))
            ->assertRedirect(route('mcp-connections.edit'));

        $this->assertNotNull($token->fresh()->revoked_at);
    }

    public function test_an_owner_cannot_revoke_another_orgs_token(): void
    {
        $user = User::factory()->role(Role::Owner)->create();
        $otherOrg = Organization::factory()->create();
        ['model' => $foreign] = app(CreateMcpTokenAction::class)($otherOrg, 'Foreign connection');

        $this->actingAs($user)
            ->delete(route('mcp-connections.destroy', $foreign))
            ->assertNotFound();

        $this->assertNull($foreign->fresh()->revoked_at);
    }

    public function test_owner_can_toggle_the_org_master_switch(): void
    {
        $user = User::factory()->role(Role::Owner)->create();

        $this->actingAs($user)
            ->put(route('mcp-connections.update'), ['mcp_enabled' => false])
            ->assertRedirect(route('mcp-connections.edit'));

        $this->assertFalse($user->organization->fresh()->mcp_enabled);
    }

    public function test_the_page_props_never_carry_a_token_hash(): void
    {
        $user = User::factory()->role(Role::Owner)->create();
        app(CreateMcpTokenAction::class)($user->organization, 'A connection', null, $user);

        $response = $this->actingAs($user)->get(route('mcp-connections.edit'));

        $response->assertDontSee('token_hash');
        $response->assertDontSee('"token"');
    }

    public function test_the_org_master_switch_rejects_every_mcp_call_when_off(): void
    {
        config()->set('mcp.http.enabled', true);

        $org = Organization::factory()->mcpDisabled()->create();
        ['token' => $token] = app(CreateMcpTokenAction::class)($org, 'Disabled-org connection');

        $this->withToken($token)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'list_clients', 'arguments' => []],
            ])
            ->assertStatus(403);
    }
}
