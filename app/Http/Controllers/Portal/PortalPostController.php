<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Posts\Approvals\GenerateApprovalCertificatePdfAction;
use App\Actions\Posts\Approvals\SubmitClientDecisionAction;
use App\Actions\Posts\CreatePostCommentAction;
use App\Actions\Posts\Feedback\ResolvePostCommentAction;
use App\Enums\ApprovalDecision;
use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\PortalPostDecisionRequest;
use App\Http\Requests\StorePostCommentRequest;
use App\Models\Post;
use App\Models\PostComment;
use App\Services\PostNotificationService;
use App\Services\Posts\PostRevisionService;
use App\Support\Posts\ApprovalProgress;
use App\Support\Posts\PostFeedbackPresenter;
use App\Support\Posts\PostMediaPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 6.5 — the client portal's posts list and detail/approval view: a real, authenticated
 * counterpart to the token-based `/review/:token` link (`ReviewController`), scoped to the
 * logged-in contact's own `client_id` by `EnsureClientPortalAccess`. Approve / request-changes and
 * commenting reuse the same `TransitionPostStatusAction`/`CreatePostCommentAction` and
 * `PostPolicy` rules as the internal editor and the review portal — no new transition logic.
 */
class PortalPostController extends Controller
{
    public function index(Request $request): Response
    {
        // An internal-only status in the query string is ignored rather than honoured, so
        // `?status=draft` can never surface pre-review posts.
        $status = PostStatus::tryFrom((string) $request->query('status'));
        $status = $status?->isClientVisible() ? $status : null;

        $posts = Post::query()
            ->where('client_id', $request->user()->client_id)
            ->when(
                $status,
                fn ($query) => $query->where('status', $status),
                fn ($query) => $query->whereIn('status', PostStatus::clientVisible()),
            )
            ->with(['targets.socialAccount'])
            ->latest()
            ->get()
            ->map(fn (Post $post) => $this->transformForList($post))
            ->values();

        return Inertia::render('portal/posts/index', [
            'client' => ['name' => $request->user()->client->name],
            'posts' => $posts,
            'filters' => ['status' => $status?->value],
            'statuses' => array_map(
                fn (PostStatus $s) => ['value' => $s->value, 'label' => $s->label()],
                PostStatus::clientVisible(),
            ),
        ]);
    }

    public function show(Request $request, Post $post, PostNotificationService $notifications, PostRevisionService $revisions): Response
    {
        $user = $request->user();
        $post->load(['targets.socialAccount', 'assets', 'comments.user', 'comments.mentionedUsers', 'gridTile.plan', 'approvalSteps.contact']);

        return Inertia::render('portal/posts/show', [
            'post' => [
                'id' => $post->id,
                'status' => $post->status->value,
                'status_label' => $post->status->label(),
                'master_caption' => $post->master_caption,
                'review_message' => $post->review_message,
                // Step 18.9: the look was approved in a grid; this review is about the words.
                'grid_name' => $post->gridTile?->plan?->name,
                // Step M-5.3: what changed since the client last reviewed it (null on a first review).
                'changes_since_review' => $post->status === PostStatus::WaitingClient
                    ? $revisions->changesSinceLastReview($post)
                    : null,
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
                    'social_account_id' => $target->social_account_id,
                    'platform' => $target->socialAccount?->platform?->value,
                    'handle' => $target->socialAccount?->handle,
                    'scheduled_local_date' => $target->scheduled_local_date?->toDateString(),
                    'scheduled_local_time' => $target->scheduled_local_time,
                    ...PostMediaPresenter::channelPreview($target->setRelation('post', $post)),
                ])->values(),
                'comments' => $post->comments
                    ->where('internal_only', false)
                    ->sortByDesc('created_at')
                    ->map(fn ($comment) => [
                        'id' => $comment->id,
                        'user_name' => $comment->user?->name,
                        'body' => $comment->body,
                        'mentioned_user_ids' => $comment->mentionedUsers->pluck('id')->values(),
                        'created_at' => $comment->created_at?->toIso8601String(),
                    ])
                    ->values(),
            ],
            // Step M-6.3: the approval-steps progress, and whether it's this reviewer's turn.
            'approval' => ApprovalProgress::present($post),
            'certificate_available' => $post->status === PostStatus::Approved,
            // Step M-6.4: visual-feedback threads (pins, caption notes, suggested edits).
            'feedback' => PostFeedbackPresenter::threads($post, $user, forClient: true),
            'can' => [
                'decide' => ($user->can('transition', [$post, PostStatus::Approved])
                    || $user->can('transition', [$post, PostStatus::ChangesRequested]))
                    && ApprovalProgress::canDecide($post, $user),
                'comment' => $user->can('comment', $post),
            ],
            'mentionableUsers' => $notifications->mentionableRecipients($post, exclude: $user)
                ->map(fn ($mentionable) => ['id' => $mentionable->id, 'name' => $mentionable->name])
                ->values(),
        ]);
    }

    /**
     * Approve or request changes on a `waiting_client` post — the authenticated-portal
     * counterpart to `ReviewController::decide`.
     */
    public function decide(
        PortalPostDecisionRequest $request,
        Post $post,
        SubmitClientDecisionAction $action,
    ): RedirectResponse {
        // Step M-6.3: the decision goes through the approval-steps orchestrator, which releases the
        // post only once the client's approval policy (any / all / sequential) is satisfied.
        $action(
            $post,
            $request->user(),
            PostStatus::from($request->validated('decision')) === PostStatus::Approved
                ? ApprovalDecision::Approved
                : ApprovalDecision::ChangesRequested,
            $request->validated('comment'),
        );

        return to_route('portal.posts.show', $post);
    }

    /**
     * Step M-6.3 — approve every post currently awaiting this reviewer in one go ("Approve all"),
     * honouring the client's approval policy. Posts it can't release (not their turn, already
     * decided, or policy not yet satisfied) are simply left; the count approved is flashed back.
     */
    public function decideAll(Request $request, SubmitClientDecisionAction $action): RedirectResponse
    {
        $comment = $request->string('comment')->trim()->value() ?: null;
        $user = $request->user();

        $posts = Post::query()
            ->where('client_id', $user->client_id)
            ->where('status', PostStatus::WaitingClient)
            ->with('approvalSteps.contact', 'client')
            ->get();

        $approved = 0;

        foreach ($posts as $post) {
            if (! ApprovalProgress::canDecide($post, $user) || ! $user->can('transition', [$post, PostStatus::Approved])) {
                continue;
            }

            $action($post, $user, ApprovalDecision::Approved, $comment);
            $approved++;
        }

        return to_route('portal.posts.index')->with('toast', [
            'type' => $approved > 0 ? 'success' : 'info',
            'message' => $approved > 0
                ? ($approved === 1 ? 'One post approved.' : "{$approved} posts approved.")
                : 'Nothing was waiting on you to approve.',
        ]);
    }

    /**
     * Step M-6.3 — download this post's approval certificate (only once it's approved).
     */
    public function certificate(Post $post, GenerateApprovalCertificatePdfAction $action): \Illuminate\Http\Response
    {
        abort_unless($post->status === PostStatus::Approved, 404);

        return response($action($post), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="approval-certificate-post-'.$post->id.'.pdf"',
        ]);
    }

    /**
     * Add a (never internal-only) comment to a post from the portal.
     */
    public function comment(
        StorePostCommentRequest $request,
        Post $post,
        CreatePostCommentAction $action,
    ): RedirectResponse {
        // Step M-6.4: a reviewer can pin a comment to the media/caption or suggest a caption edit.
        $action(
            $post,
            $request->user(),
            $request->validated('body'),
            false,
            anchorType: $request->anchorType(),
            anchor: $request->anchorPayload(),
            parentId: $request->validated('parent_id') !== null ? (int) $request->validated('parent_id') : null,
            suggestedEdit: $request->validated('suggested_edit'),
        );

        return to_route('portal.posts.show', $post);
    }

    /**
     * Step M-6.4 — a reviewer resolves or re-opens one of the post's feedback threads.
     */
    public function resolveComment(Request $request, PostComment $comment, ResolvePostCommentAction $action): RedirectResponse
    {
        $comment->loadMissing('post.client');
        $this->authorize('comment', $comment->post);
        abort_unless($comment->parent_id === null, 404);

        $action($comment, $request->user(), $request->boolean('resolved', true));

        return to_route('portal.posts.show', $comment->post_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformForList(Post $post): array
    {
        $target = $post->targets->first();
        $account = $target?->socialAccount;

        return [
            'id' => $post->id,
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'master_caption' => $post->master_caption,
            'platform' => $account?->platform?->value,
            'handle' => $account?->handle,
        ];
    }
}
