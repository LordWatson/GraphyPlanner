<?php

namespace App\Actions\AI;

use App\Contracts\AiProvider;
use App\Models\Post;
use App\Support\AI\AiCaptionSuggestions;

class SuggestCaptionsForPostAction
{
    public function __construct(protected AiProvider $ai) {}

    public function __invoke(Post $post): AiCaptionSuggestions
    {
        return $this->ai->suggestCaptions($post);
    }
}
