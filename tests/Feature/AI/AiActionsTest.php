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
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                return new AiCaptionSuggestions();
            }

            public function recommendPosts(Client $client): AiPostIdeas
            {
                return new AiPostIdeas();
            }

            public function discoverTrends(Client $client, array $platforms): AiTrends
            {
                return new AiTrends([new AiTrend(title: 'Trend', summary: 'Summary', platforms: $platforms, confidence: 0.9)]);
            }
        });

        $trends = $this->app->make(DiscoverTrendsAction::class)($client, ['instagram']);

        $this->assertCount(1, $trends->trends);
        $this->assertSame('Trend', $trends->trends[0]->title);
    }

    public function test_recommend_posts_action_can_persist_a_chosen_idea_as_a_draft_post(): void
    {
        $client = Client::factory()->create();

        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function suggestCaptions(Post $post): AiCaptionSuggestions
            {
                return new AiCaptionSuggestions();
            }

            public function recommendPosts(Client $client): AiPostIdeas
            {
                return new AiPostIdeas([new AiPostIdea(title: 'Idea', description: 'Desc', suggestedCaption: 'Caption')]);
            }

            public function discoverTrends(Client $client, array $platforms): AiTrends
            {
                return new AiTrends();
            }
        });

        $action = $this->app->make(RecommendPostsAction::class);
        $ideas = $action($client);

        $post = $action->persist($client, $ideas->ideas[0]);

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'client_id' => $client->id,
            'status' => PostStatus::Idea->value,
            'master_caption' => 'Caption',
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
                return new AiPostIdeas();
            }

            public function discoverTrends(Client $client, array $platforms): AiTrends
            {
                return new AiTrends();
            }
        });

        $action = $this->app->make(SuggestCaptionsForPostAction::class);
        $suggestions = $action($post);

        $updated = $action->apply($post, $suggestions->suggestions[0]);

        $this->assertSame('Fresh caption', $updated->master_caption);
        $this->assertSame(['a', 'b'], $updated->hashtags);
    }
}
