<?php

namespace Tests\Feature\Mcp;

use App\Enums\ClientStatus;
use App\Mcp\Resources\BrandBrainResource;
use App\Mcp\Servers\GraphyServer;
use App\Mcp\Tools\ListClientsTool;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase MCP-0 (learning spike) — proves the round-trip through Graphy's MCP server: an assistant
 * can list the dev org's clients and load a client's brand brain as context.
 *
 * NOT a tenancy test: MCP-0 has no auth and resolves one hardcoded dev org (McpDevOrg). Real
 * two-org isolation is proven in Phase MCP-1 once token/org resolution exists. The second org
 * here only documents that the spike reads a single configured org, not every org.
 */
class GraphyMcpSpikeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        config()->set('mcp.spike.dev_org_id', $this->org->id);
    }

    public function test_the_server_advertises_the_spike_tool_and_resource(): void
    {
        GraphyServer::tools()->assertRegistered(ListClientsTool::class);
        GraphyServer::resources()->assertRegistered(BrandBrainResource::class);
    }

    public function test_list_clients_returns_the_dev_orgs_clients(): void
    {
        Client::factory()->for($this->org, 'organization')->create(['name' => 'Northwind Coffee', 'status' => ClientStatus::Active]);
        Client::factory()->for($this->org, 'organization')->create(['name' => 'Acme Interiors', 'status' => ClientStatus::Paused]);

        // A different org's client must not appear in the dev org's listing.
        $otherOrg = Organization::factory()->create();
        Client::factory()->for($otherOrg, 'organization')->create(['name' => 'Rival Studio']);

        GraphyServer::tool(ListClientsTool::class, [])
            ->assertOk()
            ->assertSee('Northwind Coffee')
            ->assertSee('Acme Interiors')
            ->assertSee($this->org->name)
            ->assertDontSee('Rival Studio');
    }

    public function test_brand_brain_resource_returns_markdown_context(): void
    {
        $client = Client::factory()->for($this->org, 'organization')->create(['name' => 'Northwind Coffee']);
        BrandBrain::factory()->for($client)->create([
            'voice' => ['tone' => 'Friendly and confident', 'personality' => 'Approachable expert', 'do_nots' => ['Avoid slang']],
        ]);

        $response = GraphyServer::resource(BrandBrainResource::class);

        $response
            ->assertOk()
            ->assertSee('# Brand Brain: Northwind Coffee')
            ->assertSee('Friendly and confident');
    }

    public function test_list_clients_reports_an_empty_list_when_the_dev_org_has_no_clients(): void
    {
        GraphyServer::tool(ListClientsTool::class, [])
            ->assertOk()
            ->assertStructuredContent([
                'organization' => $this->org->name,
                'count' => 0,
                'clients' => [],
            ]);
    }
}
