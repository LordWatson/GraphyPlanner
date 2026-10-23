<?php

namespace App\Actions\AI;

use App\Contracts\AiProvider;
use App\Enums\ApprovalMode;
use App\Enums\PostStatus;
use App\Models\AiPostIdea as PersistedAiPostIdea;
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

        return $this->filterAndPersist($client, $this->ai->recommendPosts($client));
    }

    /**
     * Run a freshly generated set of ideas through the guardrail and upsert the survivors. Shared by
     * the agency path ({@see __invoke()}) and the business local/seasonal engine
     * ({@see GenerateBrandIdeasAction}, Step B-6.1) so both filter and persist
     * identically — only the provider call that produced the ideas differs.
     */
    public function filterAndPersist(Client $client, AiPostIdeas $ideas): AiPostIdeas
    {
        $filtered = $this->guardrail->filterPostIdeas($client->brandBrain, $ideas, [
            'client_id' => $client->id,
            'org_id' => $client->org_id,
        ]);

        $this->persistIdeas($client, $filtered);

        return $filtered;
    }

    /**
     * Upserts the recommended ideas (keyed on client_id+title) so the Trends & Ideas panel
     * survives a page refresh instead of only existing in the request-scoped DTO.
     */
    private function persistIdeas(Client $client, AiPostIdeas $ideas): void
    {
        if ($ideas->ideas === []) {
            return;
        }

        DB::transaction(function () use ($client, $ideas) {
            foreach ($ideas->ideas as $idea) {
                PersistedAiPostIdea::query()->updateOrCreate(
                    ['client_id' => $client->id, 'title' => $idea->title],
                    [
                        'org_id' => $client->org_id,
                        'description' => $idea->description,
                        'platforms' => $idea->platforms,
                        'suggested_caption' => $idea->suggestedCaption,
                        'suggested_post_at' => $idea->suggestedPostAt,
                    ],
                );
            }
        });
    }

    /**
     * Persist a single, explicitly selected AiPostIdea as a draft Post (status `idea`), only
     * when a user action requests it — never automatically. Wrapped in a DB transaction since
     * it writes a Post plus its implied hashtags in one step.
     */
    public function persist(Client $client, AiPostIdea $idea): Post
    {
        [$caption, $hashtags] = $this->splitHashtags($idea->suggestedCaption);

        return DB::transaction(function () use ($client, $idea, $caption, $hashtags) {
            $post = Post::create([
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'status' => PostStatus::Idea,
                'approval_mode' => $client->default_approval_mode ?? ApprovalMode::ClientRequired,
                'master_caption' => $caption,
                'hashtags' => $hashtags === [] ? null : $hashtags,
            ]);

            PersistedAiPostIdea::query()
                ->where('client_id', $client->id)
                ->where('title', $idea->title)
                ->update(['post_id' => $post->id]);

            Log::info('AI post idea persisted as draft post', [
                'client_id' => $client->id,
                'org_id' => $client->org_id,
                'post_id' => $post->id,
            ]);

            return $post;
        });
    }

    /**
     * The AI returns ideas with hashtags inline in the suggested caption ("... #launch #summer").
     * Pull them out into bare tags (no "#") for the post's hashtags field, and tidy the caption
     * whitespace left behind once they're removed.
     *
     * @return array{0: ?string, 1: array<int, string>}
     */
    private function splitHashtags(?string $caption): array
    {
        if ($caption === null) {
            return [null, []];
        }

        preg_match_all('/#([\p{L}\p{N}_]+)/u', $caption, $matches);

        $hashtags = array_values(array_unique($matches[1]));

        $cleaned = (string) preg_replace('/[ \t]*#[\p{L}\p{N}_]+/u', '', $caption);
        $cleaned = (string) preg_replace('/[ \t]+$/m', '', $cleaned);
        $cleaned = trim((string) preg_replace("/\n{3,}/", "\n\n", $cleaned));

        return [$cleaned === '' ? null : $cleaned, $hashtags];
    }
}
