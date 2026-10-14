<?php

namespace App\Actions\AI;

use App\Contracts\AiProvider;
use App\Models\Post;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Wraps App\Contracts\AiProvider::suggestCaptions() (Step 2.3). By default this only returns
 * the raw AiCaptionSuggestions for the editor's "Suggest with AI" panel to display; applying a
 * chosen suggestion to the post itself only happens via `apply()`, and only when the user
 * explicitly confirms it — the AI never overwrites a post's caption on its own.
 */
class SuggestCaptionsForPostAction
{
    public function __construct(
        private readonly AiProvider $ai,
    ) {}

    public function __invoke(Post $post): AiCaptionSuggestions
    {
        Log::info('AI caption suggestions requested', [
            'post_id' => $post->id,
            'client_id' => $post->client_id,
            'org_id' => $post->org_id,
        ]);

        return $this->ai->suggestCaptions($post);
    }

    /**
     * Write a user-confirmed suggestion's caption and hashtags into the post. Wrapped in a DB
     * transaction since it writes the post plus (implicitly, via `Post::booted()`) its
     * normalized hashtags in one step.
     */
    public function apply(Post $post, AiCaptionSuggestion $suggestion): Post
    {
        return DB::transaction(function () use ($post, $suggestion) {
            $post->update([
                'master_caption' => $suggestion->caption,
                'hashtags' => $suggestion->hashtags,
            ]);

            Log::info('AI caption suggestion applied to post', [
                'post_id' => $post->id,
                'client_id' => $post->client_id,
                'org_id' => $post->org_id,
            ]);

            return $post->refresh();
        });
    }
}
