<?php

namespace App\Http\Controllers;

use App\Actions\AI\DiscoverTrendsAction;
use App\Actions\AI\RecommendPostsAction;
use App\Actions\AI\RepurposeCaptionsForPostAction;
use App\Actions\AI\SuggestCaptionsForPostAction;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\AiPostIdea;
use App\Models\AiTrend;
use App\Models\Client;
use App\Models\Post;
use App\Services\AI\AiGuardrailService;
use App\Support\AI\AiPostIdea as AiPostIdeaDto;
use App\Support\AI\Images\ImageStudioPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin controller wiring the Step 2.3 AI Actions up to the editor's "Suggest with AI" panel
 * and the client dashboard's "Trends & Ideas" panel (Step 2.4). Every endpoint is gated behind
 * `config('ai.enabled')` (404 when the feature is off entirely) and restricted to
 * Owner/Strategist users within the resource's own organization (403 otherwise) — the same
 * access-control shape described in Step 2.4's acceptance criteria. All three methods only
 * ever call into the constructor-injected Actions, never a concrete AiProvider.
 */
class AiController extends Controller
{
    /**
     * Discover trends for a client across the requested platforms (Trends & Ideas panel).
     */
    public function trends(Request $request, Client $client, DiscoverTrendsAction $action): JsonResponse
    {
        abort_unless((bool) config('ai.enabled', false), 404);
        $this->authorize('view', $client);
        $this->authorizeAiUse($request, $client->org_id);

        $platformValues = array_values(Arr::wrap($request->input('platforms', [])));
        $platforms = array_values(array_filter(array_map(function ($value) {
            try {
                return Platform::from((string) $value)->value;
            } catch (\ValueError) {
                return null;
            }
        }, $platformValues)));

        $trends = $action($client, $platforms);

        return response()->json(['trends' => $trends]);
    }

    /**
     * Recommend fresh post ideas for a client (Trends & Ideas panel).
     */
    public function ideas(Request $request, Client $client, RecommendPostsAction $action): JsonResponse
    {
        abort_unless((bool) config('ai.enabled', false), 404);
        $this->authorize('view', $client);
        $this->authorizeAiUse($request, $client->org_id);

        $ideas = $action($client);

        return response()->json(['ideas' => $ideas]);
    }

    /**
     * Suggest captions/hashtags/alt text/first comment for a post (editor's "Suggest with AI"
     * panel). Never writes anything to the post — applying a suggestion is a separate,
     * explicit, user-confirmed step entirely on the frontend/normal post-update route.
     */
    public function captions(Request $request, Post $post, SuggestCaptionsForPostAction $action, AiGuardrailService $guardrail): JsonResponse
    {
        abort_unless((bool) config('ai.enabled', false), 404);
        $this->authorize('view', $post);
        $this->authorizeAiUse($request, $post->org_id);

        $suggestions = $action($post);
        $platforms = $post->targetPlatforms();

        $policyWarnings = collect($suggestions->suggestions)
            ->map(fn ($suggestion) => collect($platforms)
                ->flatMap(fn (Platform $platform) => $guardrail->platformWarnings($platform, $suggestion))
                ->values()
                ->all())
            ->values()
            ->all();

        return response()->json(['suggestions' => $suggestions, 'policyWarnings' => $policyWarnings]);
    }

    /**
     * Step B-6.4 — repurpose the post's caption natively for each of its platforms. Same gate as
     * captions; returns one variant per platform for the editor's per-platform override fields.
     */
    public function repurpose(Request $request, Post $post, RepurposeCaptionsForPostAction $action): JsonResponse
    {
        abort_unless((bool) config('ai.enabled', false), 404);
        $this->authorize('view', $post);
        $this->authorizeAiUse($request, $post->org_id);

        return response()->json(['variants' => $action($post)]);
    }

    /**
     * Detail page for a single persisted trend, giving the "Trends & Ideas" panel the same
     * list → detail pattern used by the rest of the app instead of a dense inline card.
     */
    public function showTrend(Request $request, AiTrend $aiTrend): Response
    {
        abort_unless((bool) config('ai.enabled', false), 404);
        $this->authorize('view', $aiTrend->client);
        $this->authorizeAiUse($request, $aiTrend->org_id);

        return Inertia::render('clients/ai-trend', [
            'client' => ['id' => $aiTrend->client->id, 'name' => $aiTrend->client->name],
            'trend' => [
                'id' => $aiTrend->id,
                'title' => $aiTrend->title,
                'summary' => $aiTrend->summary,
                'platforms' => $aiTrend->platforms ?? [],
                'confidence' => $aiTrend->confidence,
                'created_at' => $aiTrend->created_at?->toDateTimeString(),
            ],
        ]);
    }

    /**
     * Detail page for a single persisted idea, with a "Turn into draft post" action
     * (`RecommendPostsAction::persist()`) only ever triggered by this explicit, confirmed click.
     */
    public function showIdea(Request $request, AiPostIdea $aiPostIdea, ImageStudioPresenter $studio): Response
    {
        abort_unless((bool) config('ai.enabled', false), 404);
        $this->authorize('view', $aiPostIdea->client);
        $this->authorizeAiUse($request, $aiPostIdea->org_id);
        $user = $request->user();

        return Inertia::render('clients/ai-post-idea', [
            'client' => ['id' => $aiPostIdea->client->id, 'name' => $aiPostIdea->client->name],
            'idea' => [
                'id' => $aiPostIdea->id,
                'title' => $aiPostIdea->title,
                'description' => $aiPostIdea->description,
                'platforms' => $aiPostIdea->platforms ?? [],
                'suggestedCaption' => $aiPostIdea->suggested_caption,
                'suggestedPostAt' => $aiPostIdea->suggested_post_at,
                'post_id' => $aiPostIdea->post_id,
                'created_at' => $aiPostIdea->created_at?->toDateTimeString(),
            ],
            // Phase 16: the AI Image Studio lives on the idea page. A closure so the studio's
            // partial reloads (`only: ['imageStudio']`) stay cheap.
            'imageStudio' => fn () => $studio->forIdea($aiPostIdea, $user),
        ]);
    }

    /**
     * Turns a single persisted idea into a real draft Post, only when explicitly requested from
     * its detail page — mirrors `RecommendPostsAction::persist()`'s "never automatic" rule.
     */
    public function persistIdea(Request $request, AiPostIdea $aiPostIdea, RecommendPostsAction $action): RedirectResponse
    {
        abort_unless((bool) config('ai.enabled', false), 404);
        $this->authorize('view', $aiPostIdea->client);
        $this->authorizeAiUse($request, $aiPostIdea->org_id);

        if ($aiPostIdea->post_id === null) {
            $dto = new AiPostIdeaDto(
                title: $aiPostIdea->title,
                description: (string) $aiPostIdea->description,
                platforms: $aiPostIdea->platforms ?? [],
                suggestedCaption: $aiPostIdea->suggested_caption,
                suggestedPostAt: $aiPostIdea->suggested_post_at,
            );

            $action->persist($aiPostIdea->client, $dto);
            $aiPostIdea->refresh();
        }

        return to_route('posts.edit', $aiPostIdea->post_id);
    }

    /**
     * AI features are Owner/Strategist only by default (Step 2.4), on top of whatever
     * resource-level policy already ran above.
     */
    private function authorizeAiUse(Request $request, int $orgId): void
    {
        $user = $request->user();

        abort_unless(
            $user && $user->org_id === $orgId && in_array($user->role, [Role::Owner, Role::Strategist], true),
            403,
        );
    }
}
