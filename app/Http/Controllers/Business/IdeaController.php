<?php

namespace App\Http\Controllers\Business;

use App\Actions\AI\RecommendPostsAction;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AiPostIdea;
use App\Models\AiTrend;
use App\Services\Workspace\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step B-4.1 — the business shell's "Ideas" page: the brand's AI trends and post ideas, with "Use
 * this idea" turning one into a draft (the shared `ai-post-ideas.persist`). The local/seasonal idea
 * engine and the richer "More ideas" generator land in Phase B-6; here the page lists what's been
 * discovered and can ask for more through the existing {@see RecommendPostsAction}.
 */
class IdeaController extends Controller
{
    public function index(Request $request, CurrentWorkspace $workspace): Response
    {
        $brand = $workspace->brand();
        $this->authorize('view', $brand);
        $this->authorizeIdeas($request);

        return Inertia::render('business/ideas', [
            'aiEnabled' => (bool) config('ai.enabled', false),
            'pillars' => $brand->brandBrain->content_pillars ?? [],
            // Deferred: the shell paints immediately, the (brand-scoped) lists stream in after.
            'ideas' => Inertia::defer(fn () => $brand->aiPostIdeas()->latest()->latest('id')->get()->map(fn (AiPostIdea $idea) => [
                'id' => $idea->id,
                'title' => $idea->title,
                'description' => $idea->description,
                'platforms' => $idea->platforms ?? [],
                'suggestedCaption' => $idea->suggested_caption,
                'post_id' => $idea->post_id,
            ])->all()),
            'trends' => Inertia::defer(fn () => $brand->aiTrends()->latest()->latest('id')->get()->map(fn (AiTrend $trend) => [
                'id' => $trend->id,
                'title' => $trend->title,
                'summary' => $trend->summary,
                'platforms' => $trend->platforms ?? [],
                'confidence' => $trend->confidence,
            ])->all()),
        ]);
    }

    /**
     * Ask the AI for a fresh set of ideas for the brand (the "More ideas" button). Returns JSON so
     * the page can append them without a full reload.
     */
    public function generate(Request $request, CurrentWorkspace $workspace, RecommendPostsAction $action): JsonResponse
    {
        abort_unless((bool) config('ai.enabled', false), 404);
        $brand = $workspace->brand();
        $this->authorize('view', $brand);
        $this->authorizeIdeas($request);

        return response()->json(['ideas' => $action($brand)]);
    }

    /**
     * Ideas are an Owner/Manager tool (same roles the agency AI panels use; "Manager" is the
     * business label for Strategist).
     */
    private function authorizeIdeas(Request $request): void
    {
        abort_unless(in_array($request->user()->role, [Role::Owner, Role::Strategist], true), 403);
    }
}
