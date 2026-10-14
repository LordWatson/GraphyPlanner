<?php

namespace App\Actions\AI;

use App\Contracts\AiProvider;
use App\Models\Client;
use App\Support\AI\AiPostIdeas;

class RecommendPostsAction
{
    public function __construct(protected AiProvider $ai) {}

    public function __invoke(Client $client): AiPostIdeas
    {
        return $this->ai->recommendPosts($client);
    }
}
