<?php

namespace App\Actions\AI;

use App\Contracts\AiProvider;
use App\Enums\PostStatus;
use App\Models\Client;
use App\Models\Post;
use App\Services\AI\AiGuardrailService;
use App\Support\AI\AiPostIdea;
use App\Support\AI\AiPostIdeas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Wraps App\Contracts\AiProvider::recommendPosts() (Step 2.3). By default this only returns
 * the raw AiPostIdeas for display (e.g. a "Trends & Ideas" panel); callers that want to turn a
 * specific idea into a real draft post must explicitly opt in via `persist()`.
 */
class RecommendPostsAction
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly AiGuardrailService $guardrail,
    ) {}

    public function __invoke(Client $client): AiPostIdeas
    {
        Log::info('AI post ideas requested', [
            'client_id' => $client->id,
            'org_id' => $client->org_id,
        ]);

        $ideas = $this->ai->recommendPosts($client);

        return $this->guardrail->filterPostIdeas($client->brandBrain, $ideas, [
            'client_id' => $client->id,
            'org_id' => $client->org_id,
        ]);
    }

    /**
     * Persist a single, explicitly selected AiPostIdea as a draft Post (status `idea`), only
     * when a user action requests it — never automatically. Wrapped in a DB transaction since
     * it writes a Post plus its implied hashtags in one step.
     */
    public function persist(Client $client, AiPostIdea $idea): Post
    {
        return DB::transaction(function () use ($client, $idea) {
            $post = Post::create([
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'status' => PostStatus::Idea,
                'master_caption' => $idea->suggestedCaption,
            ]);

            Log::info('AI post idea persisted as draft post', [
                'client_id' => $client->id,
                'org_id' => $client->org_id,
                'post_id' => $post->id,
            ]);

            return $post;
        });
    }
}
