<?php

namespace App\Http\Controllers;

use App\Actions\Grids\DecideGridPlanAction;
use App\Actions\Posts\RecordPreviewFeedbackAction;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\GridPlanStatus;
use App\Enums\PostStatus;
use App\Http\Requests\Grids\GridDecisionRequest;
use App\Http\Requests\PreviewFeedbackRequest;
use App\Http\Requests\ReviewDecisionRequest;
use App\Models\GridPlan;
use App\Models\Post;
use App\Models\ReviewToken;
use App\Support\Grids\GridPlanPresenter;
use App\Support\Posts\PostMediaPresenter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Step 0.13 client review portal (`/review/:token`): a light, unauthenticated view scoped
 * strictly to the token's client/post, with no money fields and only Approve / Request changes
 * actions. Access is granted purely by possession of a valid, unexpired, unrevoked `ReviewToken`
 * — never by a logged-in session — per spec §10/§12.
 *
 * Step 18.8: a token carrying a grid plan shows that grid instead (`review/grid`), checked the
 * same way (hash, expiry, revoked, client scope → 404).
 */
class ReviewController extends Controller
{
    /**
     * Show the post (or grid) waiting on this token's client for review.
     */
    public function show(string $token, GridPlanPresenter $grids): Response
    {
        $reviewToken = $this->resolveToken($token);

        if ($reviewToken->grid_plan_id !== null) {
            return $this->showGrid($token, $this->resolveGrid($reviewToken), $grids);
        }

        $post = $this->resolvePost($reviewToken);

        // Step B-3.4: a "Share preview" link (a partner/sponsor) reads differently — brand copy,
        // no agency "studio" voice, decisions only if the owner enabled them, and no status move.
        $isPreview = $this->isPreview($reviewToken, $post);

        return Inertia::render('review/show', [
            'token' => $token,
            'client' => ['name' => $post->client->name],
            'mode' => $isPreview ? 'preview' : 'client',
            'post' => [
                'id' => $post->id,
                'status' => $post->status->value,
                'status_label' => $post->status->label(),
                'master_caption' => $post->master_caption,
                'review_message' => $isPreview ? null : $post->review_message,
                'hashtags' => $post->hashtags ?? [],
                'music' => $post->music,
                'location' => $post->location,
                'media_format' => PostMediaPresenter::mediaFormat($post),
                'assets' => $post->assets->map(fn ($asset) => [
                    'id' => $asset->id,
                    'url' => $asset->url,
                    'thumbnail_url' => $asset->thumbnailUrl(),
                    'original_filename' => $asset->original_filename,
                    'type' => $asset->type?->value,
                    'source' => $asset->source->value,
                    'mime_type' => $asset->mime_type,
                    ...PostMediaPresenter::framing($asset),
                ])->values(),
                'targets' => $post->targets->map(fn ($target) => [
                    'platform' => $target->socialAccount?->platform?->value,
                    'handle' => $target->socialAccount?->handle,
                    'scheduled_local_date' => $target->scheduled_local_date?->toDateString(),
                    'scheduled_local_time' => $target->scheduled_local_time,
                    ...PostMediaPresenter::channelPreview($target->setRelation('post', $post)),
                ])->values(),
                'can_decide' => $isPreview
                    ? $reviewToken->allow_approval
                    : $post->status === PostStatus::WaitingClient,
                // Step B-3.4: a comment-only / approval-enabled preview lets the viewer leave a note.
                'can_comment' => $isPreview && $reviewToken->allow_comments,
                // Step 18.9: the look was already approved in a grid, so the client only signs off the words.
                'grid_name' => $post->gridTile?->plan?->name,
                'comments' => $post->comments
                    ->where('internal_only', false)
                    ->sortByDesc('created_at')
                    ->map(fn ($comment) => [
                        'id' => $comment->id,
                        'user_name' => $comment->user?->name,
                        'body' => $comment->body,
                        'created_at' => $comment->created_at?->toIso8601String(),
                    ])
                    ->values(),
            ],
        ]);
    }

    /**
     * Approve or request changes on the post waiting on this token's client.
     */
    public function decide(
        ReviewDecisionRequest $request,
        string $token,
        TransitionPostStatusAction $action,
    ): RedirectResponse {
        $reviewToken = $this->resolveToken($token);

        abort_if($reviewToken->grid_plan_id !== null, 404);

        $post = $this->resolvePost($reviewToken);

        // Step B-3.4: a share-preview link (and any business-post link) never runs the status
        // machine — its feedback goes through `previewFeedback()` so this stays the agency path.
        abort_if($this->isPreview($reviewToken, $post), 404);

        $action(
            $post,
            PostStatus::from($request->validated('decision')),
            null,
            $request->validated('comment'),
        );

        return to_route('review.show', $token);
    }

    /**
     * Step B-3.4 — record a "Share preview" viewer's feedback (a partner/sponsor comment and/or a
     * sponsor sign-off), strictly within what the token allows. It never transitions the post: a
     * business post has no `waiting_client` stage, so a decision is recorded, not acted on.
     */
    public function previewFeedback(
        PreviewFeedbackRequest $request,
        string $token,
        RecordPreviewFeedbackAction $action,
    ): RedirectResponse {
        $reviewToken = $this->resolveToken($token);

        abort_if($reviewToken->grid_plan_id !== null, 404);

        $post = $this->resolvePost($reviewToken);

        abort_unless($this->isPreview($reviewToken, $post), 404);

        $decision = $request->filled('decision') ? PostStatus::from($request->validated('decision')) : null;

        // Honour only what the token granted; a decision on a comment-only link, or a comment on a
        // view-only link, is silently ignored by the action but rejected loudly here first.
        abort_if($decision !== null && ! $reviewToken->allow_approval, 403);
        abort_if($request->filled('comment') && ! $reviewToken->allow_comments, 403);

        $action($reviewToken, $post, $decision, $request->validated('comment'));

        return to_route('review.show', $token);
    }

    /**
     * Step B-3.4 — whether this link is a read-only "Share preview" rather than the agency
     * client-review flow. True for any widened token, and for any token on a business post (a
     * business workspace has no `waiting_client` stage, so its links never run the status machine).
     */
    private function isPreview(ReviewToken $reviewToken, Post $post): bool
    {
        return $reviewToken->isSharePreview() || $post->organization->isBusiness();
    }

    /**
     * Step 18.8 — approve the grid or ask for changes (with flagged tiles) through the link.
     */
    public function decideGrid(GridDecisionRequest $request, string $token, DecideGridPlanAction $action): RedirectResponse
    {
        $reviewToken = $this->resolveToken($token);

        abort_if($reviewToken->grid_plan_id === null, 404);

        $plan = $this->resolveGrid($reviewToken);

        // A grid that's no longer waiting on the client is refused by the transition itself.
        $action(
            $plan,
            null,
            GridPlanStatus::from((string) $request->validated('decision')),
            (int) $request->validated('version'),
            $request->validated('comment'),
            $request->flaggedTiles($plan),
        );

        return to_route('review.show', $token);
    }

    private function showGrid(string $token, GridPlan $plan, GridPlanPresenter $grids): Response
    {
        return Inertia::render('review/grid', [
            'token' => $token,
            'client' => ['name' => $plan->client->name],
            'grid' => $grids->plan($plan, forClient: true),
            'feed' => $grids->feed($plan),
            'comments' => $grids->comments($plan, forClient: true),
            'canDecide' => $plan->status === GridPlanStatus::WaitingClient,
        ]);
    }

    /**
     * A valid, unexpired, unrevoked token whose client isn't archived — else a 404 (spec §12).
     */
    private function resolveToken(string $token): ReviewToken
    {
        $reviewToken = ReviewToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        // Step I-1.5: links for an archived client stop working until it's restored.
        if (! $reviewToken || ! $reviewToken->isValid() || $reviewToken->client?->trashed() !== false) {
            throw new NotFoundHttpException;
        }

        return $reviewToken;
    }

    /**
     * The token's grid, only while it belongs to the token's client and has been sent to them.
     */
    private function resolveGrid(ReviewToken $reviewToken): GridPlan
    {
        $plan = GridPlan::query()->withoutGlobalScopes()->whereNull('deleted_at')->with('client')->find($reviewToken->grid_plan_id);

        if ($plan === null || $plan->client_id !== $reviewToken->client_id || ! $plan->status->isClientVisible()) {
            throw new NotFoundHttpException;
        }

        return $plan;
    }

    /**
     * Resolve the post scoped strictly to the token's `client_id` (and `post_id`, when the token
     * was issued for a single post) — never any other client's data (spec §12).
     */
    private function resolvePost(ReviewToken $reviewToken): Post
    {
        $with = ['client', 'organization', 'targets.socialAccount', 'assets', 'comments.user', 'gridTile.plan'];

        $post = $reviewToken->post_id
            ? $reviewToken->post()->with($with)->first()
            : $reviewToken->client->posts()
                ->with($with)
                ->where('status', PostStatus::WaitingClient)
                ->latest()
                ->first();

        if (! $post || $post->client_id !== $reviewToken->client_id) {
            throw new NotFoundHttpException;
        }

        return $post;
    }
}
