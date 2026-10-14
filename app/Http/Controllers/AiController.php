<?php

namespace App\Http\Controllers;

use App\Actions\AI\DiscoverTrendsAction;
use App\Actions\AI\RecommendPostsAction;
use App\Actions\AI\SuggestCaptionsForPostAction;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Post;
use App\Services\AI\AiGuardrailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

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

        $platformValues = array_values(Arr::wrap($request->query('platforms', [])));
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
