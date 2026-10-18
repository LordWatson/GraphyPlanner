<?php

namespace App\Http\Controllers;

use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\CreatePostCommentAction;
use App\Actions\Posts\DeletePostAction;
use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Actions\Posts\PostStatusTransitionMap;
use App\Actions\Posts\ReschedulePostAction;
use App\Actions\Posts\ResendClientReviewEmailAction;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Actions\Posts\UpdatePostAction;
use App\Actions\Publishing\RetryFailedPostTargetsAction;
use App\Actions\Publishing\SearchMusicAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Exceptions\Publishing\MusicProviderUnavailableException;
use App\Http\Requests\ReschedulePostRequest;
use App\Http\Requests\StorePostCommentRequest;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\TransitionPostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Models\AiImageGeneration;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Services\ClientHashtagService;
use App\Services\PostNotificationService;
use App\Support\AI\Images\ImageStudioPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    /**
     * Create a new post (with its per-account targets) for the given client.
     */
    public function store(StorePostRequest $request, Client $client, CreatePostAction $action): RedirectResponse
    {
        $action($client, $request->validated());

        return to_route('clients.posts.index', $client);
    }

    /**
     * Show the full editor UI for a post (Step 0.10): caption/hashtags/music/location, per-target
     * schedule rows, attached media, the §6 checklist, transition controls, comments, and the
     * activity log.
     */
    public function edit(
        Request $request,
        Post $post,
        EvaluatePostChecklistAction $evaluateChecklist,
        PostNotificationService $notifications,
        ImageStudioPresenter $studio,
        ClientHashtagService $hashtags,
    ): Response {
        $this->authorize('view', $post);

        $user = $request->user();
        $post->load([
            // Step I-2.6: the editor reads the client and its brand brain (hashtag policy).
            'client.brandBrain',
            'targets.socialAccount',
            'assets',
            'campaign',
            'activityLogs.user',
            'approvals.user',
            'comments.user',
            'comments.mentionedUsers',
        ]);

        $client = $post->client;
        $allowedTransitions = array_values(array_filter(
            PostStatusTransitionMap::allowedFrom($post->status),
            fn (PostStatus $to) => $user->can('transition', [$post, $to]),
        ));

        $hashtagSuggestions = $this->hashtagSuggestions($client, $hashtags);
        $mentionableUsers = $notifications->mentionableRecipients($post, exclude: $user);

        return Inertia::render('posts/edit', [
            'post' => $this->transformPostForEditor($post, $evaluateChecklist),
            'client' => ['id' => $client->id, 'name' => $client->name],
            // Per-platform limits travel with every selectable account (not just the post's saved
            // targets) so the editor can show caption/hashtag usage and a live readiness check the
            // moment a new channel is toggled on, before anything is saved.
            'targetAccounts' => $client->socialAccounts()->get()->map(fn ($account) => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'platform_label' => $account->platform->label(),
                'handle' => $account->handle,
                'timezone' => $account->timezone,
                'caption_limit' => $account->platform->captionLimit(),
                'max_hashtags' => $account->platform->maxHashtags(),
                'is_text_only_capable' => $account->platform->isTextOnlyCapable(),
            ]),
            'allowedTransitions' => array_map(
                fn (PostStatus $to) => ['value' => $to->value, 'label' => $to->label()],
                $allowedTransitions,
            ),
            'can' => [
                'update' => $user->can('update', $post),
                'comment' => $user->can('comment', $post),
                'resend_review_email' => $user->can('resendReviewEmail', $post),
                'retry_failed_targets' => $user->can('retryFailedTargets', $post),
                'delete' => $user->can('delete', $post),
                'create_asset' => $user->can('create', [Asset::class, $client]),
            ],
            'hashtagSuggestions' => $hashtagSuggestions,
            'mentionableUsers' => $mentionableUsers->map(fn ($mentionable) => [
                'id' => $mentionable->id,
                'name' => $mentionable->name,
            ])->values(),
            'ai' => [
                'enabled' => (bool) config('ai.enabled', false),
                'canUse' => in_array($user->role, [Role::Owner, Role::Strategist], true),
            ],
            // Phase 16: the AI Image Studio tab (Owner/Strategist generate, Designer read-only).
            // A closure so the studio's partial reloads (`only: ['imageStudio']`) skip the rest.
            'imageStudio' => fn () => (bool) config('ai.enabled', false) && $user->can('viewAnyForClient', [AiImageGeneration::class, $client])
                ? $studio->forPost($post, $user)
                : null,
        ]);
    }

    /**
     * Update a post's content, per-target schedule, and attached media.
     */
    public function update(UpdatePostRequest $request, Post $post, UpdatePostAction $action): RedirectResponse
    {
        $action($post, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Post saved.')]);

        return to_route('posts.edit', $post);
    }

    /**
     * Move a post to a new status per the explicit `PostStatusTransitionMap` state machine.
     */
    public function transition(
        TransitionPostRequest $request,
        Post $post,
        TransitionPostStatusAction $action,
    ): RedirectResponse {
        $to = PostStatus::from($request->validated('to'));

        $action($post, $to, $request->user(), $request->validated('comment'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Post moved to :status.', ['status' => $to->label()]),
        ]);

        return to_route('posts.edit', $post);
    }

    /**
     * Shift a post's targets by a number of days — the calendar's (confirmed) drag-and-drop move.
     * Redirects back so the calendar keeps its filters.
     */
    public function reschedule(ReschedulePostRequest $request, Post $post, ReschedulePostAction $action): RedirectResponse
    {
        $action($post, $request->integer('days'), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Post moved.')]);

        return back();
    }

    /**
     * Step I-1.5 — delete (soft) a post that never went out. Published posts can only be
     * archived, through the regular `archived` transition.
     */
    public function destroy(Request $request, Post $post, DeletePostAction $action): RedirectResponse
    {
        $this->authorize('delete', $post);

        $clientId = $post->client_id;
        $action($post, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Post deleted.')]);

        return to_route('clients.posts.index', $clientId);
    }

    /**
     * Step I-1.3 — resend only the failed targets of a `failed`/`partially_published` post.
     */
    public function retryFailedTargets(Request $request, Post $post, RetryFailedPostTargetsAction $action): RedirectResponse
    {
        $this->authorize('retryFailedTargets', $post);

        $action($post, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Retrying the failed channels.')]);

        return to_route('posts.edit', $post);
    }

    /**
     * Add a comment (optionally internal-only) to a post.
     */
    public function comment(
        StorePostCommentRequest $request,
        Post $post,
        CreatePostCommentAction $action,
    ): RedirectResponse {
        $action(
            $post,
            $request->user(),
            $request->validated('body'),
            (bool) $request->validated('internal_only'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Comment posted.')]);

        return to_route('posts.edit', $post);
    }

    /**
     * Re-send the review-portal link email recorded on a `waiting_client` activity log entry, so
     * an org user can help a client who lost/never received the original message.
     */
    public function resendReviewEmail(
        Post $post,
        PostActivityLog $activityLog,
        ResendClientReviewEmailAction $action,
    ): RedirectResponse {
        $this->authorize('resendReviewEmail', $post);

        abort_unless($activityLog->post_id === $post->id, 404);

        $action($activityLog);

        return to_route('posts.edit', $post);
    }

    /**
     * Step 1.8.4 — the editor's per-platform music/sound search field calls this to look up real
     * tracks via SearchMusicAction (bound to MusicProvider). Only Instagram/TikTok have any real
     * vendor lookup (Step 1.8.2) — any other platform is rejected before ever reaching the action.
     */
    public function musicSearch(Request $request, Post $post, SearchMusicAction $action): JsonResponse
    {
        $this->authorize('view', $post);

        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:200'],
            'platform' => ['required', new Enum(Platform::class)],
        ]);

        $platform = Platform::from($validated['platform']);

        if (! in_array($platform, [Platform::Instagram, Platform::TikTok], true)) {
            return response()->json(['tracks' => []]);
        }

        try {
            $tracks = $action($validated['query'], $platform);
        } catch (MusicProviderUnavailableException $e) {
            // Surface the real reason (no connected account, vendor rejection, etc.) to the
            // editor instead of silently returning an unexplained empty result — see
            // UploadPostMusicProvider/MetaGraphMusicProvider for what raises this.
            // `error_code` (Step 1.9.5) lets the frontend key off a specific reason (e.g.
            // `meta_not_connected`) to show an actionable connect link instead of just text.
            return response()->json([
                'tracks' => [],
                'error' => $e->getMessage(),
                'error_code' => $e->errorCode(),
            ]);
        }

        return response()->json([
            'tracks' => $tracks->map(fn ($track) => [
                'id' => $track->id,
                'name' => $track->name,
                'artist' => $track->artist,
                'preview_url' => $track->previewUrl,
            ])->values(),
        ]);
    }

    /**
     * Build the hashtag autocomplete list (Instagram-style tag picker) shown in the editor: the
     * client's `BrandBrain` `hashtag_policy.always_use` list, then (Step I-2.6) the client's
     * top tags by usage and recency from `client_hashtags`, deduped and capped at 50.
     *
     * @return array<int, string>
     */
    private function hashtagSuggestions(Client $client, ClientHashtagService $hashtags): array
    {
        $fromBrandBrain = collect($client->brandBrain?->hashtag_policy['always_use'] ?? []);

        return $fromBrandBrain->merge($hashtags->suggestions($client))
            ->map(fn ($tag) => ltrim(trim((string) $tag), '#'))
            ->filter(fn ($tag) => $tag !== '')
            ->unique()
            ->values()
            ->take(50)
            ->all();
    }

    /**
     * Transform a post model into the full payload expected by the `posts/edit` editor UI.
     *
     * @return array<string, mixed>
     */
    private function transformPostForEditor(Post $post, EvaluatePostChecklistAction $evaluateChecklist): array
    {
        return [
            'id' => $post->id,
            'client_id' => $post->client_id,
            'campaign_id' => $post->campaign_id,
            'campaign_name' => $post->campaign?->name,
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'approval_mode' => $post->approval_mode->value,
            'master_caption' => $post->master_caption,
            'review_message' => $post->review_message,
            'hashtags' => $post->hashtags ?? [],
            'music' => $post->music,
            'location' => $post->location,
            'checklist' => $evaluateChecklist($post),
            'assets' => $post->assets->map(fn ($asset) => [
                'id' => $asset->id,
                'url' => $asset->url,
                'original_filename' => $asset->original_filename,
                'type' => $asset->type?->value,
                'is_ai_generated' => $asset->is_ai_generated,
            ])->values(),
            'targets' => $post->targets->map(fn ($target) => [
                'id' => $target->id,
                'social_account_id' => $target->social_account_id,
                'platform' => $target->socialAccount?->platform?->value,
                'handle' => $target->socialAccount?->handle,
                'caption_limit' => $target->socialAccount?->platform?->captionLimit(),
                'is_text_only_capable' => $target->socialAccount?->platform?->isTextOnlyCapable() ?? false,
                'scheduled_local_date' => $target->scheduled_local_date?->toDateString(),
                'scheduled_local_time' => $target->scheduled_local_time,
                'scheduled_at_utc' => $target->scheduled_at_utc?->toIso8601String(),
                'status' => $target->status->value,
                'status_label' => $target->status->label(),
                'external_post_id' => $target->external_post_id,
                'error' => $target->error,
            ])->values(),
            'activity_logs' => $post->activityLogs->sortByDesc('created_at')->map(fn ($log) => [
                'id' => $log->id,
                'user_name' => $log->user?->name,
                'from_status' => $log->from_status?->value,
                'to_status' => $log->to_status->value,
                'note' => $log->note,
                // Step I-1.6: the link itself is never stored — only whether one was sent.
                'review_link_sent' => $log->review_token_id !== null,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'approvals' => $post->approvals->sortByDesc('created_at')->map(fn ($approval) => [
                'id' => $approval->id,
                'user_name' => $approval->user?->name,
                'decision' => $approval->decision->value,
                'comment' => $approval->comment,
                'created_at' => $approval->created_at?->toIso8601String(),
            ])->values(),
            'comments' => $post->comments->sortByDesc('created_at')->map(fn ($comment) => [
                'id' => $comment->id,
                'user_name' => $comment->user?->name,
                'body' => $comment->body,
                'internal_only' => $comment->internal_only,
                'mentioned_user_ids' => $comment->mentionedUsers->pluck('id')->values(),
                'created_at' => $comment->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
