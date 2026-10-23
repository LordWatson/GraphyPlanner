<?php

namespace App\Http\Controllers\Business;

use App\Actions\AI\AcceptWeeklyPlanItemsAction;
use App\Actions\AI\DiscardWeeklyPlanItemsAction;
use App\Actions\AI\GenerateBrandIdeasAction;
use App\Actions\AI\GenerateWeeklyPlanAction;
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
 * this idea" turning one into a draft (the shared `ai-post-ideas.persist`). Step B-6.1 wires the
 * "More ideas" button to the local/seasonal {@see GenerateBrandIdeasAction}, so a fresh set is
 * grounded in the brand's place, season and upcoming local dates.
 */
class IdeaController extends Controller
{
    public function index(Request $request, CurrentWorkspace $workspace, GenerateWeeklyPlanAction $plan): Response
    {
        $brand = $workspace->brand();
        $this->authorize('view', $brand);
        $this->authorizeIdeas($request);

        return Inertia::render('business/ideas', [
            'aiEnabled' => (bool) config('ai.enabled', false),
            'hasGoal' => $brand->postingGoal()->isSet(),
            'pillars' => $brand->brandBrain->content_pillars ?? [],
            // The week's current (un-accepted) plan items; streams in after first paint.
            'plan' => Inertia::defer(fn () => $plan->currentPlan($brand)),
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
    public function generate(Request $request, CurrentWorkspace $workspace, GenerateBrandIdeasAction $action): JsonResponse
    {
        abort_unless((bool) config('ai.enabled', false), 404);
        $brand = $workspace->brand();
        $this->authorize('view', $brand);
        $this->authorizeIdeas($request);

        return response()->json(['ideas' => $action($brand)]);
    }

    /**
     * Step B-6.3 — "Plan my week": fill the posting-goal gaps for the next 7 days with draft plan
     * items. Returns the current plan so the page can render it.
     */
    public function generatePlan(Request $request, CurrentWorkspace $workspace, GenerateWeeklyPlanAction $action): JsonResponse
    {
        abort_unless((bool) config('ai.enabled', false), 404);
        $brand = $workspace->brand();
        $this->authorize('view', $brand);
        $this->authorizeIdeas($request);

        return response()->json(['plan' => $action($brand)]);
    }

    /**
     * Accept chosen plan items — each becomes a draft post. Not gated on `ai.enabled`, so a plan can
     * still be actioned if AI is later switched off.
     */
    public function acceptPlan(Request $request, CurrentWorkspace $workspace, AcceptWeeklyPlanItemsAction $action): JsonResponse
    {
        $brand = $workspace->brand();
        $this->authorize('view', $brand);
        $this->authorizeIdeas($request);

        $ids = $this->validateIds($request);

        return response()->json(['accepted' => count($action($brand, $ids))]);
    }

    /**
     * Discard chosen plan items.
     */
    public function discardPlan(Request $request, CurrentWorkspace $workspace, DiscardWeeklyPlanItemsAction $action): JsonResponse
    {
        $brand = $workspace->brand();
        $this->authorize('view', $brand);
        $this->authorizeIdeas($request);

        $ids = $this->validateIds($request);

        return response()->json(['discarded' => $action($brand, $ids)]);
    }

    /**
     * @return list<int>
     */
    private function validateIds(Request $request): array
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        return array_values(array_map('intval', $validated['ids']));
    }

    /**
     * Ideas and the weekly plan are an Owner/Manager tool (same roles the agency AI panels use;
     * "Manager" is the business label for Strategist).
     */
    private function authorizeIdeas(Request $request): void
    {
        abort_unless(in_array($request->user()->role, [Role::Owner, Role::Strategist], true), 403);
    }
}
