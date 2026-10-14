<?php

namespace Tests\Unit\Actions\AI;

use App\Actions\AI\RecommendPostsAction;
use App\Contracts\AiProvider;
use App\Enums\PostStatus;
use App\Models\Client;
use App\Services\AI\AiGuardrailService;
use App\Support\AI\AiPostIdea;
use App\Support\AI\AiPostIdeas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RecommendPostsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_delegates_to_the_ai_provider_and_returns_its_ideas(): void
    {
        $client = Client::factory()->create();
        $ideas = new AiPostIdeas([new AiPostIdea(title: 'Idea', description: 'Description')]);

        $ai = Mockery::mock(AiProvider::class);
        $ai->shouldReceive('recommendPosts')
            ->once()
            ->withArgs(fn (Client $c) => $c->is($client))
            ->andReturn($ideas);

        $result = (new RecommendPostsAction($ai, new AiGuardrailService()))($client);

        $this->assertEquals($ideas, $result);
    }

    public function test_persist_creates_a_draft_idea_post_from_the_chosen_idea(): void
    {
        $client = Client::factory()->create();
        $idea = new AiPostIdea(
            title: 'Idea',
            description: 'Description',
            platforms: ['instagram'],
            suggestedCaption: 'Suggested caption text',
        );

        $ai = Mockery::mock(AiProvider::class);
        $ai->shouldNotReceive('recommendPosts');

        $post = (new RecommendPostsAction($ai, new AiGuardrailService()))->persist($client, $idea);

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'client_id' => $client->id,
            'org_id' => $client->org_id,
            'status' => PostStatus::Idea->value,
            'master_caption' => 'Suggested caption text',
        ]);
    }

    public function test_persist_never_runs_automatically_from_the_invoke_path(): void
    {
        $client = Client::factory()->create();
        $ideas = new AiPostIdeas([new AiPostIdea(title: 'Idea', description: 'Description')]);

        $ai = Mockery::mock(AiProvider::class);
        $ai->shouldReceive('recommendPosts')->once()->andReturn($ideas);

        (new RecommendPostsAction($ai, new AiGuardrailService()))($client);

        $this->assertDatabaseCount('posts', 0);
    }
}
