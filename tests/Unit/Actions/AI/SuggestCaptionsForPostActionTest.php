<?php

namespace Tests\Unit\Actions\AI;

use App\Actions\AI\SuggestCaptionsForPostAction;
use App\Contracts\AiProvider;
use App\Models\Post;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use Mockery as m;
use Tests\TestCase;

class SuggestCaptionsForPostActionTest extends TestCase
{
    public function test_it_delegates_to_provider_and_returns_suggestions(): void
    {
        $post = new Post(['id' => 10]);
        $suggestions = new AiCaptionSuggestions([
            new AiCaptionSuggestion('A caption', ['#x'], 'alt', 'first'),
        ]);

        $provider = m::mock(AiProvider::class);
        $provider->shouldReceive('suggestCaptions')->once()->with($post)->andReturn($suggestions);

        $action = new SuggestCaptionsForPostAction($provider);
        $res = $action($post);

        $this->assertCount(1, $res);
        $this->assertSame('A caption', $res->toArray()[0]['caption']);
    }
}
