<?php

namespace App\Http\Controllers;

use App\Actions\AI\GenerateAltTextForAssetAction;
use App\Actions\AI\RecommendPostsAction;
use App\Actions\AI\RecordAiFeedbackAction;
use App\Actions\AI\RepurposeCaptionsForPostAction;
use App\Actions\AI\RewriteCaptionAction;
use App\Enums\AiRating;
use App\Enums\AiTextJobKind;
use App\Enums\AiTextJobStatus;
use App\Enums\Platform;
use App\Enums\WritingTool;
use App\Exceptions\AI\AiBudgetExceededException;
use App\Jobs\RunAiTextJob;
use App\Models\AiPostIdea;
use App\Models\AiTextJob;
use App\Models\AiTrend;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Post;
use App\Services\AI\AiAccess;
use App\Services\AI\AiUsageGuard;
use App\Support\AI\AiPostIdea as AiPostIdeaDto;
use App\Support\AI\Images\ImageStudioPresenter;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
    public function __construct(
        private readonly AiAccess $access,
        private readonly RecordAiFeedbackAction $feedback,
        private readonly AiUsageGuard $budget,
    ) {}

    /**
     * Step M-7.2 — queue a trends run and return its request id. The Trends & Ideas panel polls
     * `jobStatus` and reloads its persisted props once the job settles.
     */
    public function trends(Request $request, Client $client): JsonResponse
    {
        $this->access->authorize($request->user());
        $this->authorize('view', $client);

        $platformValues = array_values(Arr::wrap($request->input('platforms', [])));
        $platforms = array_values(array_filter(array_map(function ($value) {
            try {
                return Platform::from((string) $value)->value;
            } catch (\ValueError) {
                return null;
            }
        }, $platformValues)));

        return $this->dispatchTextJob($request, AiTextJobKind::Trends, $client, null, ['platforms' => $platforms]);
    }

    /**
     * Step M-7.2 — queue an ideas run (Trends & Ideas panel), returning its request id.
     */
    public function ideas(Request $request, Client $client): JsonResponse
    {
        $this->access->authorize($request->user());
        $this->authorize('view', $client);

        return $this->dispatchTextJob($request, AiTextJobKind::Ideas, $client, null, []);
    }

    /**
     * Step M-7.2 — queue a caption-suggestion run for a post (editor's "Suggest with AI" panel),
     * returning its request id. Never writes to the post; applying a suggestion stays a separate,
     * user-confirmed step on the normal post-update route.
     */
    public function captions(Request $request, Post $post): JsonResponse
    {
        $this->access->authorize($request->user());
        $this->authorize('view', $post);

        return $this->dispatchTextJob($request, AiTextJobKind::Captions, $post->client, $post, []);
    }

    /**
     * Step M-7.2 — poll a queued text job. Returns its status and, once completed, the result
     * payload (the same shape the sync endpoints used to return).
     */
    public function jobStatus(Request $request, AiTextJob $aiTextJob): JsonResponse
    {
        $this->access->authorize($request->user());
        $this->authorizeJob($aiTextJob);

        return response()->json([
            'request_id' => $aiTextJob->id,
            'status' => $aiTextJob->status->value,
            'result' => $aiTextJob->result,
            'error' => $aiTextJob->error,
        ]);
    }

    /**
     * Step M-7.2 — cancel a queued/running text job (best effort; the worker checks before storing).
     */
    public function cancelJob(Request $request, AiTextJob $aiTextJob): JsonResponse
    {
        $this->access->authorize($request->user());
        $this->authorizeJob($aiTextJob);

        if (! $aiTextJob->status->isSettled()) {
            $aiTextJob->update(['status' => AiTextJobStatus::Cancelled]);
        }

        return response()->json(['status' => $aiTextJob->status->value]);
    }

    /**
     * Step B-6.4 — repurpose the post's caption natively for each of its platforms. Same gate as
     * captions; returns one variant per platform for the editor's per-platform override fields.
     * Kept synchronous: it's one short call the editor awaits inline.
     */
    public function repurpose(Request $request, Post $post, RepurposeCaptionsForPostAction $action): JsonResponse
    {
        $this->access->authorize($request->user());
        $this->authorize('view', $post);

        return $this->guardingBudget(fn () => response()->json(['variants' => $action($post)]));
    }

    /**
     * Step M-7.3 — run one inline writing tool (shorten / change tone / adapt to platform) over the
     * caption text the editor sends, returning the rewrite for the user to confirm. Synchronous:
     * it's one short call the editor awaits. Budget-gated like every text call.
     */
    public function rewrite(Request $request, Post $post, RewriteCaptionAction $action): JsonResponse
    {
        $this->access->authorize($request->user());
        $this->authorize('view', $post);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:10000'],
            'tool' => ['required', Rule::enum(WritingTool::class)],
            'platform' => ['nullable', Rule::enum(Platform::class)],
            'tone' => ['nullable', 'string', 'max:60'],
        ]);

        $client = $post->client;

        if ($client === null) {
            return response()->json(['text' => $validated['text']]);
        }

        return $this->guardingBudget(fn () => response()->json([
            'text' => $action(
                $client,
                $validated['text'],
                WritingTool::from($validated['tool']),
                isset($validated['platform']) ? Platform::from($validated['platform']) : null,
                $validated['tone'] ?? null,
            ),
        ]));
    }

    /**
     * Create (idempotent on `request_id`) and dispatch a queued text job, refusing up front when
     * the org is over its monthly budget so the panel gets a clean 422 rather than a dead job.
     *
     * @param  array<string, mixed>  $params
     */
    private function dispatchTextJob(Request $request, AiTextJobKind $kind, ?Client $client, ?Post $post, array $params): JsonResponse
    {
        return $this->guardingBudget(function () use ($request, $kind, $client, $post, $params) {
            $user = $request->user();
            $this->budget->ensureWithinBudget($user?->organization);

            $requestId = (string) $request->input('request_id', (string) Str::uuid());

            $job = AiTextJob::query()->firstOrCreate(
                ['id' => $requestId],
                [
                    'org_id' => $user->org_id,
                    'user_id' => $user->id,
                    'client_id' => $client?->id,
                    'post_id' => $post?->id,
                    'kind' => $kind,
                    'params' => $params,
                    'status' => AiTextJobStatus::Queued,
                ],
            );

            if ($job->wasRecentlyCreated) {
                RunAiTextJob::dispatch($job->id)->afterCommit();
            }

            return response()->json(['request_id' => $job->id, 'status' => $job->status->value], 202);
        });
    }

    /**
     * A text job is viewable/cancellable by whoever can view its owning post/client — which also
     * refuses another organization's job (cross-tenant). Falls back to an org match for a job with
     * neither bound (never created today, but safe).
     */
    private function authorizeJob(AiTextJob $job): void
    {
        if ($job->post !== null) {
            $this->authorize('view', $job->post);
        } elseif ($job->client !== null) {
            $this->authorize('view', $job->client);
        } else {
            abort_unless(request()->user()?->org_id === $job->org_id, 403);
        }
    }

    /**
     * Step M-7.3 — suggest alt text for one of a post's image assets (editor media panel). The
     * asset must belong to the post's client; returns `{altText}` (null when nothing usable came
     * back). Synchronous and budget-gated.
     */
    public function altText(Request $request, Post $post, Asset $asset, GenerateAltTextForAssetAction $action): JsonResponse
    {
        $this->access->authorize($request->user());
        $this->authorize('view', $post);
        abort_unless($asset->client_id === $post->client_id && $asset->org_id === $post->org_id, 404);

        $client = $post->client;

        if ($client === null) {
            return response()->json(['altText' => null]);
        }

        return $this->guardingBudget(fn () => response()->json(['altText' => $action($client, $asset)]));
    }

    /**
     * Detail page for a single persisted trend, giving the "Trends & Ideas" panel the same
     * list → detail pattern used by the rest of the app instead of a dense inline card.
     */
    public function showTrend(Request $request, AiTrend $aiTrend): Response
    {
        $this->access->authorize($request->user());
        $this->authorize('view', $aiTrend->client);

        return Inertia::render('clients/ai-trend', [
            'client' => ['id' => $aiTrend->client->id, 'name' => $aiTrend->client->name],
            'trend' => [
                'id' => $aiTrend->id,
                'title' => $aiTrend->title,
                'summary' => $aiTrend->summary,
                'platforms' => $aiTrend->platforms ?? [],
                'confidence' => $aiTrend->confidence,
                'source' => $aiTrend->source,
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
        $this->access->authorize($request->user());
        $this->authorize('view', $aiPostIdea->client);
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
        $this->access->authorize($request->user());
        $this->authorize('view', $aiPostIdea->client);

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

            // Step M-7.1 (dev plan Step 2.9): turning an idea into a draft is an implicit positive
            // signal, tracked alongside explicit thumbs so acceptance rate needs no extra click.
            ($this->feedback)($aiPostIdea, $request->user(), AiRating::Applied);
        }

        return to_route('posts.edit', $aiPostIdea->post_id);
    }

    /**
     * Run an AI endpoint that may hit the vendor, turning an over-budget refusal
     * ({@see AiBudgetExceededException}, Step M-7.1) into a clean 422 the panel can show verbatim
     * rather than a generic 500.
     *
     * @param  Closure(): JsonResponse  $callback
     */
    private function guardingBudget(Closure $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (AiBudgetExceededException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'budgetExceeded' => true,
            ], 422);
        }
    }
}
