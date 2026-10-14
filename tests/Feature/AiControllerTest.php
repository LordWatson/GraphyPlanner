<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdea;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrend;
use App\Support\AI\AiTrends;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoints_are_hidden_when_feature_flag_disabled(): void
    {
        config()->set('features.ai', false);

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $this->actingAs($owner)
            ->get(route('clients.ai.ideas', $client))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('clients.ai.trends', [$client, 'platforms' => ['instagram']]))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('posts.ai.captions', $post))
            ->assertNotFound();
    }

    public function test_endpoints_return_data_when_feature_flag_enabled(): void
    {
        config()->set('features.ai', true);

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $fake = new class implements AiProvider {
            public function suggestCaptions(Post $post): AiCaptionSuggestions
            {
                return new AiCaptionSuggestions([new AiCaptionSuggestion('Hi', ['#a'], 'alt', 'c1')]);
            }
            public function recommendPosts(Client $client): AiPostIdeas
            {
                return new AiPostIdeas([new AiPostIdea('Idea', null, [Platform::Instagram], ['tag'])]);
            }
            public function discoverTrends(Client $client, array $platforms): AiTrends
            {
                return new AiTrends([new AiTrend('UGC', null, [Platform::Instagram], 0.8)]);
            }
        };

        $this->app->instance(AiProvider::class, $fake);

        $this->actingAs($owner)
            ->get(route('clients.ai.ideas', $client))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Idea']);

        $this->actingAs($owner)
            ->get(route('clients.ai.trends', [$client, 'platforms' => ['instagram']]))
            ->assertOk()
            ->assertJsonFragment(['topic' => 'UGC']);

        $this->actingAs($owner)
            ->get(route('posts.ai.captions', $post))
            ->assertOk()
            ->assertJsonFragment(['caption' => 'Hi']);
    }
}
