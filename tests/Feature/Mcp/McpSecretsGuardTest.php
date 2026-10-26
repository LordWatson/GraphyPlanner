<?php

namespace Tests\Feature\Mcp;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Mcp\Prompts\FillCalendarGapsPrompt;
use App\Mcp\Prompts\PlanWeekPrompt;
use App\Mcp\Resources\BrandBrainResource;
use App\Mcp\Servers\GraphyServer;
use App\Mcp\Tools\CancelScheduledPostTool;
use App\Mcp\Tools\CreateDraftPostTool;
use App\Mcp\Tools\DiscoverTrendsTool;
use App\Mcp\Tools\GetAnalyticsSummaryTool;
use App\Mcp\Tools\GetBrandBrainTool;
use App\Mcp\Tools\GetCalendarTool;
use App\Mcp\Tools\GetCampaignTool;
use App\Mcp\Tools\GetClientTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\ListAssetsTool;
use App\Mcp\Tools\ListCampaignsTool;
use App\Mcp\Tools\ListClientsTool;
use App\Mcp\Tools\ListPostsTool;
use App\Mcp\Tools\RecommendPostsTool;
use App\Mcp\Tools\SchedulePostTool;
use App\Mcp\Tools\StartAccountConnectionTool;
use App\Mcp\Tools\SuggestCaptionsTool;
use App\Mcp\Tools\TransitionPostStatusTool;
use App\Mcp\Tools\UpdatePostTool;
use App\Models\Asset;
use App\Models\BrandBrain;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Primitive;
use ReflectionClass;
use Tests\TestCase;

/**
 * Step MCP-1.6 (extended through MCP-2) — the automated secrets-exposure guard (the MCP analogue of
 * the review-portal props-payload test). It enumerates every tool/resource registered on
 * `GraphyServer` and asserts (a) a new primitive forces this guard to be revisited, (b) no declared
 * input/output schema field can carry a secret, and (c) the real output of each read primitive —
 * invoked against seeded data that deliberately includes a connected account's access token — leaks
 * neither that token nor any secret-shaped field name. Runs in `composer test` as part of CI.
 */
class McpSecretsGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Field-name fragments that must never appear in an MCP schema or output. Specific on purpose
     * (e.g. `access_token`, not bare `token`) so legitimate copy doesn't trip the guard.
     *
     * @var list<string>
     */
    private const FORBIDDEN = [
        'meta_access_token', 'access_token', 'refresh_token', 'token_hash',
        'api_key', 'apikey', 'client_secret', 'password', 'webhook_secret',
        'private_key', 'review_token',
    ];

    public function test_a_new_tool_or_resource_forces_this_guard_to_be_revisited(): void
    {
        // If this fails, a primitive was added/removed — extend the coverage below for it, then
        // update this list. The guard must consciously cover every registered primitive.
        $this->assertEqualsCanonicalizing([
            ListClientsTool::class,
            GetClientTool::class,
            GetBrandBrainTool::class,
            ListPostsTool::class,
            GetPostTool::class,
            GetCalendarTool::class,
            ListCampaignsTool::class,
            GetCampaignTool::class,
            ListAssetsTool::class,
            GetAnalyticsSummaryTool::class,
            CreateDraftPostTool::class,
            UpdatePostTool::class,
            TransitionPostStatusTool::class,
            SchedulePostTool::class,
            StartAccountConnectionTool::class,
            CancelScheduledPostTool::class,
            DiscoverTrendsTool::class,
            RecommendPostsTool::class,
            SuggestCaptionsTool::class,
        ], $this->registered('tools'));
        $this->assertEqualsCanonicalizing([BrandBrainResource::class], $this->registered('resources'));
        $this->assertEqualsCanonicalizing([
            PlanWeekPrompt::class,
            FillCalendarGapsPrompt::class,
        ], $this->registered('prompts'));
    }

    public function test_no_registered_schema_declares_a_secret_bearing_field(): void
    {
        $classes = [...$this->registered('tools'), ...$this->registered('resources'), ...$this->registered('prompts')];
        $this->assertNotEmpty($classes, 'GraphyServer should register at least one primitive.');

        foreach ($classes as $class) {
            // Resolve from the container — several tools constructor-inject their backing Actions.
            /** @var Primitive $primitive */
            $primitive = app($class);
            $definition = $primitive->toArray();

            // The declared schema field names must not look secret-bearing...
            $names = [
                ...$this->propertyNames($definition['inputSchema'] ?? []),
                ...$this->propertyNames($definition['outputSchema'] ?? []),
            ];

            foreach ($names as $name) {
                foreach (self::FORBIDDEN as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        strtolower($name),
                        "Schema field [{$name}] on [{$class}] looks like it could carry a secret.",
                    );
                }
            }

            // ...and neither must anything else in the advertised definition (name/description/schemas).
            $serialized = strtolower((string) json_encode($definition));

            foreach (self::FORBIDDEN as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $serialized,
                    "The advertised definition of [{$class}] mentions a secret-shaped field [{$needle}].",
                );
            }
        }
    }

    public function test_read_primitive_output_leaks_no_token_or_secret_field(): void
    {
        $org = Organization::factory()->create();
        config()->set('mcp.spike.dev_org_id', $org->id);

        // An Owner for the dev org so the member-bound read tools (which reuse the web Actions and
        // their policies) resolve a member and scope exactly as the HTTP transport does.
        User::factory()->create(['org_id' => $org->id, 'role' => Role::Owner]);

        $client = Client::factory()->for($org, 'organization')->create(['name' => 'Northwind Coffee']);
        BrandBrain::factory()->for($client)->create();
        $campaign = Campaign::factory()->for($org, 'organization')->for($client)->create();
        Asset::factory()->for($org, 'organization')->for($client)->create();

        $post = Post::factory()->for($org, 'organization')->for($client)->create(['status' => PostStatus::Draft]);
        $account = SocialAccount::factory()->for($client)->connected()->create([
            // Adversarial: a known sentinel in the account's (encrypted) access token. No read tool
            // reads account tokens, so it must never surface — this proves that.
            'meta_access_token' => 'SENTINEL-SECRET-9f3a2b7c1d',
        ]);
        PostTarget::factory()->for($post)->for($account, 'socialAccount')->create();

        $outputs = [
            GraphyServer::tool(ListClientsTool::class, [])->assertOk(),
            GraphyServer::tool(GetClientTool::class, ['client_id' => $client->id])->assertOk(),
            GraphyServer::tool(GetBrandBrainTool::class, ['client_id' => $client->id])->assertOk(),
            GraphyServer::tool(ListPostsTool::class, [])->assertOk(),
            GraphyServer::tool(GetPostTool::class, ['post_id' => $post->id])->assertOk(),
            GraphyServer::tool(GetCalendarTool::class, [])->assertOk(),
            GraphyServer::tool(ListCampaignsTool::class, [])->assertOk(),
            GraphyServer::tool(GetCampaignTool::class, ['campaign_id' => $campaign->id])->assertOk(),
            GraphyServer::tool(ListAssetsTool::class, ['client_id' => $client->id])->assertOk(),
            GraphyServer::tool(GetAnalyticsSummaryTool::class, [])->assertOk(),
            GraphyServer::resource(BrandBrainResource::class)->assertOk(),
            // MCP-4.2: the connect tool returns only a browser-completable invite URL — never a
            // vendor OAuth token. (It is a write tool, but proving its output carries no secret is
            // exactly the guard's job.)
            GraphyServer::tool(StartAccountConnectionTool::class, ['client_id' => $client->id])->assertOk(),
        ];

        foreach (['SENTINEL-SECRET-9f3a2b7c1d', ...self::FORBIDDEN] as $needle) {
            foreach ($outputs as $output) {
                $output->assertDontSee($needle);
            }
        }
    }

    /**
     * The class-strings registered on the server for a given primitive kind, read straight off the
     * server definition so the guard tracks the real registration.
     *
     * @return list<class-string<Primitive>>
     */
    private function registered(string $kind): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = (new ReflectionClass(GraphyServer::class))->getDefaultProperties();

        /** @var list<class-string<Primitive>> $classes */
        $classes = $defaults[$kind] ?? [];

        return $classes;
    }

    /**
     * All property names declared anywhere in a JSON schema array (recursing into nested objects).
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function propertyNames(array $schema): array
    {
        $names = [];

        foreach ($schema['properties'] ?? [] as $name => $child) {
            $names[] = (string) $name;

            if (is_array($child)) {
                $names = [...$names, ...$this->propertyNames($child)];
            }
        }

        return $names;
    }
}
