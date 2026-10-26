<?php

namespace Tests\Feature\Mcp;

use App\Contracts\AiProvider;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Mcp\Prompts\PlanWeekPrompt;
use App\Mcp\Servers\GraphyServer;
use App\Mcp\Tools\DiscoverTrendsTool;
use App\Mcp\Tools\RecommendPostsTool;
use App\Mcp\Tools\SuggestCaptionsTool;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Support\AI\AiBrandProfileDraft;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdea;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrend;
use App\Support\AI\AiTrends;
use App\Support\AI\BrandProfileInput;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\ImageBriefInput;
use App\Support\Mcp\McpContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Phase MCP-5 — the AI tools reuse the exact Phase-2 Actions (guardrails, caching, budget) and the
 * prompts are pure orchestration over the other tools. These tests prove each AI tool returns
 * structured suggestions, that AI-disabled / over-budget orgs get a clean non-technical message, that
 * the `Ai` scope is required, and that a prompt renders its workflow scoped to the client.
 */
class McpAiToolsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.enabled', true);

        $this->org = Organization::factory()->create(['ai_enabled' => true, 'ai_monthly_token_budget' => null]);
        config()->set('mcp.spike.dev_org_id', $this->org->id);

        User::factory()->create(['org_id' => $this->org->id, 'role' => Role::Owner]);
        $this->client = Client::factory()->for($this->org, 'organization')->create();

        $this->fakeAi();
    }

    private function fakeAi(): void
    {
        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function suggestCaptions(Post $post): AiCaptionSuggestions
            {
                return new AiCaptionSuggestions([new AiCaptionSuggestion(caption: 'A bright, on-brand caption', hashtags: ['coffee'])]);
            }

            public function recommendPosts(Client $client): AiPostIdeas
            {
                return new AiPostIdeas([new AiPostIdea(title: 'Behind the beans', description: 'A sourcing story', platforms: ['instagram'])]);
            }

            public function discoverTrends(Client $client, array $platforms): AiTrends
            {
                return new AiTrends([new AiTrend(title: 'Cold brew season', summary: 'Rising interest', platforms: $platforms, confidence: 0.8)]);
            }

            public function draftImageBrief(Client $client, ImageBriefInput $input): AiImageBrief
            {
                return new AiImageBrief(subject: $input->title);
            }

            public function draftBrandProfile(BrandProfileInput $input): AiBrandProfileDraft
            {
                return AiBrandProfileDraft::empty();
            }
        });
    }

    public function test_discover_trends_returns_structured_trends(): void
    {
        GraphyServer::tool(DiscoverTrendsTool::class, ['client_id' => $this->client->id, 'platforms' => ['instagram']])
            ->assertOk()
            ->assertSee('Cold brew season');
    }

    public function test_recommend_posts_returns_structured_ideas(): void
    {
        GraphyServer::tool(RecommendPostsTool::class, ['client_id' => $this->client->id])
            ->assertOk()
            ->assertSee('Behind the beans');
    }

    public function test_suggest_captions_returns_structured_suggestions(): void
    {
        $post = Post::factory()->for($this->org, 'organization')->for($this->client)->create(['status' => PostStatus::Draft]);

        GraphyServer::tool(SuggestCaptionsTool::class, ['post_id' => $post->id])
            ->assertOk()
            ->assertSee('on-brand caption');
    }

    public function test_ai_disabled_org_gets_a_clean_message(): void
    {
        $this->org->update(['ai_enabled' => false]);

        GraphyServer::tool(DiscoverTrendsTool::class, ['client_id' => $this->client->id])
            ->assertHasErrors()
            ->assertSee('not enabled');
    }

    public function test_over_budget_returns_the_non_technical_limit_message(): void
    {
        $this->org->update(['ai_monthly_token_budget' => 0]);

        GraphyServer::tool(RecommendPostsTool::class, ['client_id' => $this->client->id])
            ->assertHasErrors()
            ->assertSee('Monthly AI limit reached');
    }

    public function test_ai_scope_is_required(): void
    {
        $this->app->instance(McpContext::class, new McpContext(
            organization: $this->org,
            scopes: ['read', 'write:drafts'],
        ));

        GraphyServer::tool(DiscoverTrendsTool::class, ['client_id' => $this->client->id])
            ->assertHasErrors()
            ->assertSee('ai');
    }

    public function test_plan_week_prompt_renders_the_workflow_for_the_client(): void
    {
        GraphyServer::prompt(PlanWeekPrompt::class, ['client_id' => $this->client->id])
            ->assertOk()
            ->assertSee($this->client->name)
            ->assertSee('create_draft_post')
            ->assertSee('DRAFTS');
    }

    public function test_plan_week_prompt_rejects_an_unknown_client(): void
    {
        GraphyServer::prompt(PlanWeekPrompt::class, ['client_id' => 999999])
            ->assertSee('No client with that id');
    }
}
