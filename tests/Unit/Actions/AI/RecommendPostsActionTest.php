<?php

namespace Tests\Unit\Actions\AI;

use App\Actions\AI\RecommendPostsAction;
use App\Contracts\AiProvider;
use App\Models\Client;
use App\Support\AI\AiPostIdea;
use App\Enums\Platform;
use App\Support\AI\AiPostIdeas;
use Mockery as m;
use Tests\TestCase;

class RecommendPostsActionTest extends TestCase
{
    public function test_it_delegates_to_provider_and_returns_dtos(): void
    {
        $client = new Client(['id' => 1]);
        $ideas = new AiPostIdeas([new AiPostIdea('Behind the scenes', null, [Platform::Instagram], ['bts'])]);

        $provider = m::mock(AiProvider::class);
        $provider->shouldReceive('recommendPosts')->once()->with($client)->andReturn($ideas);

        $action = new RecommendPostsAction($provider);
        $result = $action($client);

        $this->assertCount(1, $result);
        $this->assertSame('Behind the scenes', $result->toArray()[0]['title']);
    }
}
