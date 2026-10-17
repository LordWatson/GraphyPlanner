<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DiscoverTrendsAction;
use App\Actions\AI\RecommendPostsAction;
use App\Actions\AI\SuggestCaptionsForPostAction;
use App\Contracts\AiProvider;
use App\Enums\PostStatus;
use App\Models\Client;
use App\Models\Post;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdea;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrend;
use App\Support\AI\AiTrends;
use App\Support\AI\ClientContextBuilder;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\ImageBriefInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Feature-level coverage for the Step 2.3 Actions, resolving each through the container (so the
 * bound App\Contracts\AiProvider swap is exercised exactly as a controller would use it) against
 * a fake implementation rather than mocking method-by-method like the unit tests do.
 */
class AiActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_discover_trends_action_resolves_through_the_container_and_returns_trends(): void
    {
        $client = Client::factory()->create();

        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function suggestCaptions(Post $post): AiCaptionSuggestions
            {
                return new AiCaptionSuggestions;
            }

            public function recommendPosts(Client $client): AiPostIdeas
            {
                return new AiPostIdeas;
            }

            public function discoverTrends(Client $client, array $platforms): AiTrends
            {
                return new AiTrends([new AiTrend(title: 'Trend', summary: 'Summary', platforms: $platforms, confidence: 0.9)]);
            }

            public function draftImageBrief(Client $client, ImageBriefInput $input): AiImageBrief
            {
                return new AiImageBrief(subject: $input->title);
            }
        });

        $trends = $this->app->make(DiscoverTrendsAction::class)($client, ['instagram']);

        $this->assertCount(1, $trends->trends);
        $this->assertSame('Trend', $trends->trends[0]->title);

        // Trends survive a page refresh: they're persisted (keyed on client_id+title) so the
        // "Trends & Ideas" panel isn't empty until Refresh is clicked again.
        $this->assertDatabaseHas('ai_trends', [
            'client_id' => $client->id,
            'title' => 'Trend',
            'summary' => 'Summary',
        ]);
    }

    public function test_discover_trends_action_discards_a_stale_or_incompatible_cached_value(): void
    {
        $client = Client::factory()->create();

        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function suggestCaptions(Post $post): AiCaptionSuggestions
            {
                return new AiCaptionSuggestions;
            }

            public function recommendPosts(Client $client): AiPostIdeas
            {
                return new AiPostIdeas;
            }

            public function discoverTrends(Client $client, array $platforms): AiTrends
            {
                return new AiTrends([new AiTrend(title: 'Fresh', summary: 'Summary', platforms: $platforms, confidence: 0.9)]);
            }

            public function draftImageBrief(Client $client, ImageBriefInput $input): AiImageBrief
            {
                return new AiImageBrief(subject: $input->title);
            }
        });

        // Simulate a stale/incompatible cache entry (e.g. one unserialized as
        // __PHP_Incomplete_Class after the AiTrends DTO changed shape) by seeding the
        // exact cache key the action will look up with a value that isn't an AiTrends.
        $sorted = ['instagram'];
        $fingerprint = (new ClientContextBuilder)->fingerprint($client);
        Cache::put(sprintf('ai:trends:%d:%s:%s', $client->id, implode(',', $sorted), $fingerprint), 'not-a-valid-trends-object', 3600);

        $trends = $this->app->make(DiscoverTrendsAction::class)($client, ['instagram']);

        $this->assertInstanceOf(AiTrends::class, $trends);
        $this->assertCount(1, $trends->trends);
        $this->assertSame('Fresh', $trends->trends[0]->title);
    }

    public function test_recommend_posts_action_can_persist_a_chosen_idea_as_a_draft_post(): void
    {
        $client = Client::factory()->create();

        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function suggestCaptions(Post $post): AiCaptionSuggestions
            {
                return new AiCaptionSuggestions;
            }

            public function recommendPosts(Client $client): AiPostIdeas
            {
                return new AiPostIdeas([new AiPostIdea(title: 'Idea', description: 'Desc', suggestedCaption: 'Caption')]);
            }

            public function discoverTrends(Client $client, array $platforms): AiTrends
            {
                return new AiTrends;
            }

            public function draftImageBrief(Client $client, ImageBriefInput $input): AiImageBrief
            {
                return new AiImageBrief(subject: $input->title);
            }
        });

        $action = $this->app->make(RecommendPostsAction::class);
        $ideas = $action($client);

        // Ideas survive a page refresh too, the same way trends do.
        $this->assertDatabaseHas('ai_post_ideas', [
            'client_id' => $client->id,
            'title' => 'Idea',
            'description' => 'Desc',
        ]);

        $post = $action->persist($client, $ideas->ideas[0]);

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'client_id' => $client->id,
            'status' => PostStatus::Idea->value,
            'master_caption' => 'Caption',
        ]);

        // Turning the idea into a draft post links the two rows together.
        $this->assertDatabaseHas('ai_post_ideas', [
            'client_id' => $client->id,
            'title' => 'Idea',
            'post_id' => $post->id,
        ]);
    }

    public function test_suggest_captions_action_can_apply_a_chosen_suggestion_to_the_post(): void
    {
        $post = Post::factory()->for(Client::factory())->create(['master_caption' => 'Old']);

        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function suggestCaptions(Post $post): AiCaptionSuggestions
            {
                return new AiCaptionSuggestions([new AiCaptionSuggestion(caption: 'Fresh caption', hashtags: ['a', 'b'])]);
            }

            public function recommendPosts(Client $client): AiPostIdeas
            {
                return new AiPostIdeas;
            }

            public function discoverTrends(Client $client, array $platforms): AiTrends
            {
                return new AiTrends;
            }

            public function draftImageBrief(Client $client, ImageBriefInput $input): AiImageBrief
            {
                return new AiImageBrief(subject: $input->title);
            }
        });

        $action = $this->app->make(SuggestCaptionsForPostAction::class);
        $suggestions = $action($post);

        $updated = $action->apply($post, $suggestions->suggestions[0]);

        $this->assertSame('Fresh caption', $updated->master_caption);
        $this->assertSame(['a', 'b'], $updated->hashtags);
    }
}
