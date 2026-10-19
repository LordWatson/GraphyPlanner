<?php

namespace Tests\Unit\Actions\AI;

use App\Actions\AI\SuggestCaptionsForPostAction;
use App\Contracts\AiProvider;
use App\Models\Client;
use App\Models\Post;
use App\Services\AI\AiGuardrailService;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SuggestCaptionsForPostActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_delegates_to_the_ai_provider_and_returns_its_suggestions(): void
    {
        $post = Post::factory()->for(Client::factory())->create();
        $suggestions = new AiCaptionSuggestions([new AiCaptionSuggestion(caption: 'Caption')]);

        $ai = Mockery::mock(AiProvider::class);
        $ai->shouldReceive('suggestCaptions')
            ->once()
            ->withArgs(fn (Post $p) => $p->is($post))
            ->andReturn($suggestions);

        $result = (new SuggestCaptionsForPostAction($ai, new AiGuardrailService))($post);

        $this->assertEquals($suggestions, $result);
    }

    public function test_apply_writes_the_chosen_suggestion_caption_and_hashtags_into_the_post(): void
    {
        $post = Post::factory()->for(Client::factory())->create(['master_caption' => 'Old caption']);
        $suggestion = new AiCaptionSuggestion(caption: 'New caption', hashtags: ['launch', 'new']);

        $ai = Mockery::mock(AiProvider::class);
        $ai->shouldNotReceive('suggestCaptions');

        $updated = (new SuggestCaptionsForPostAction($ai, new AiGuardrailService))->apply($post, $suggestion);

        $this->assertSame('New caption', $updated->master_caption);
        $this->assertSame(['launch', 'new'], $updated->hashtags);
        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'master_caption' => 'New caption',
        ]);
    }

    public function test_apply_never_runs_automatically_from_the_invoke_path(): void
    {
        $post = Post::factory()->for(Client::factory())->create(['master_caption' => 'Old caption']);
        $suggestions = new AiCaptionSuggestions([new AiCaptionSuggestion(caption: 'New caption')]);

        $ai = Mockery::mock(AiProvider::class);
        $ai->shouldReceive('suggestCaptions')->once()->andReturn($suggestions);

        (new SuggestCaptionsForPostAction($ai, new AiGuardrailService))($post);

        $this->assertSame('Old caption', $post->refresh()->master_caption);
    }
}
