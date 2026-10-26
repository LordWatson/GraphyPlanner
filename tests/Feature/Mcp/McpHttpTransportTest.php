<?php

namespace Tests\Feature\Mcp;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step MCP-1.1 — the remote Streamable HTTP transport (`POST /mcp`).
 *
 * Proves the server is reachable over HTTP and lists itself, and that the endpoint is OFF by
 * default so it is not internet-reachable before authentication lands in MCP-1.2. This is NOT a
 * tenancy/auth test (still one hardcoded dev org, no OAuth) — isolation is proven in MCP-1.2.
 */
class McpHttpTransportTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/mcp';

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        config()->set('mcp.spike.dev_org_id', $this->org->id);
    }

    public function test_the_http_endpoint_404s_for_every_verb_while_disabled(): void
    {
        config()->set('mcp.http.enabled', false);

        $this->get(self::ENDPOINT)->assertNotFound();
        $this->delete(self::ENDPOINT)->assertNotFound();
        $this->postJson(self::ENDPOINT, $this->listToolsPayload())->assertNotFound();
    }

    public function test_a_get_request_reports_the_transport_is_mounted_when_enabled(): void
    {
        config()->set('mcp.http.enabled', true);

        // The Streamable HTTP spec answers a plain GET (no SSE stream) with 405 + Allow: POST.
        $this->get(self::ENDPOINT)
            ->assertStatus(405)
            ->assertHeader('Allow', 'POST');
    }

    public function test_the_server_lists_its_tools_over_http_when_enabled(): void
    {
        config()->set('mcp.http.enabled', true);

        Client::factory()->for($this->org, 'organization')->create([
            'name' => 'Northwind Coffee',
            'status' => ClientStatus::Active,
        ]);

        $response = $this->postJson(self::ENDPOINT, $this->listToolsPayload());

        $response->assertOk();

        $body = $response->json();

        $this->assertSame('2.0', $body['jsonrpc']);
        $this->assertSame(1, $body['id']);

        $toolNames = array_column($body['result']['tools'] ?? [], 'name');
        $this->assertContains('list_clients', $toolNames);
    }

    /**
     * A legacy-style `tools/list` request (no `_meta`) — reaches the dispatcher without an
     * initialize handshake, which is enough to prove the HTTP transport advertises the server.
     *
     * @return array<string, mixed>
     */
    private function listToolsPayload(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ];
    }
}
