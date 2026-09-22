<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Posts\CreatePostCommentAction;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\PortalPostDecisionRequest;
use App\Http\Requests\StorePostCommentRequest;
use App\Models\Post;
use App\Services\PostNotificationService;
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
    /**
     * Statuses a client contact is allowed to see at all — pre-review internal stages (idea,
     * draft, internal review) never reach the portal.
     *
     * @var list<PostStatus>
     */
    private const VISIBLE_STATUSES = [
        PostStatus::WaitingClient,
        PostStatus::ChangesRequested,
        PostStatus::Approved,
        PostStatus::Scheduled,
        PostStatus::Publishing,
        PostStatus::Published,
        PostStatus::Failed,
        PostStatus::Archived,
    ];

    public function index(Request $request): Response
    {
        $status = PostStatus::tryFrom((string) $request->query('status'));

        $posts = Post::query()
            ->where('client_id', $request->user()->client_id)
            ->when(
                $status,
                fn ($query) => $query->where('status', $status),
                fn ($query) => $query->whereIn('status', self::VISIBLE_STATUSES),
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
                self::VISIBLE_STATUSES,
            ),
        ]);
    }

    public function show(Request $request, Post $post, PostNotificationService $notifications): Response
    {
        $user = $request->user();
        $post->load(['targets.socialAccount', 'assets', 'comments.user', 'comments.mentionedUsers']);

        return Inertia::render('portal/posts/show', [
            'post' => [
                'id' => $post->id,
                'status' => $post->status->value,
                'status_label' => $post->status->label(),
                'master_caption' => $post->master_caption,
                'review_message' => $post->review_message,
                'hashtags' => $post->hashtags ?? [],
                'music' => $post->music,
                'location' => $post->location,
                'assets' => $post->assets->map(fn ($asset) => [
                    'id' => $asset->id,
                    'url' => $asset->url,
                    'original_filename' => $asset->original_filename,
                    'type' => $asset->type?->value,
                    'source' => $asset->source?->value,
                    'mime_type' => $asset->mime_type,
                ])->values(),
                'targets' => $post->targets->map(fn ($target) => [
                    'social_account_id' => $target->social_account_id,
                    'platform' => $target->socialAccount?->platform?->value,
                    'handle' => $target->socialAccount?->handle,
                    'scheduled_local_date' => $target->scheduled_local_date?->toDateString(),
                    'scheduled_local_time' => $target->scheduled_local_time,
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
            'can' => [
                'decide' => $user->can('transition', [$post, PostStatus::Approved])
                    || $user->can('transition', [$post, PostStatus::ChangesRequested]),
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
        TransitionPostStatusAction $action,
    ): RedirectResponse {
        $action(
            $post,
            PostStatus::from($request->validated('decision')),
            $request->user(),
            $request->validated('comment'),
        );

        return to_route('portal.posts.show', $post);
    }

    /**
     * Add a (never internal-only) comment to a post from the portal.
     */
    public function comment(
        StorePostCommentRequest $request,
        Post $post,
        CreatePostCommentAction $action,
    ): RedirectResponse {
        $action($post, $request->user(), $request->validated('body'), false);

        return to_route('portal.posts.show', $post);
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
