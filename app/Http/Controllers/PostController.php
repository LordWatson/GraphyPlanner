<?php

namespace App\Http\Controllers;

use App\Actions\Campaigns\ApplyCampaignDefaultsAction;
use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\CreatePostCommentAction;
use App\Actions\Posts\CreateSharePreviewAction;
use App\Actions\Posts\DeletePostAction;
use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Actions\Posts\QuickSchedulePostAction;
use App\Actions\Posts\ReschedulePostAction;
use App\Actions\Posts\ResendClientReviewEmailAction;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Actions\Posts\UpdatePostAction;
use App\Actions\Publishing\RetryFailedPostTargetsAction;
use App\Actions\Publishing\SearchMusicAction;
use App\Enums\CampaignStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\PublishFailureReason;
use App\Enums\Role;
use App\Exceptions\Publishing\MusicProviderUnavailableException;
use App\Http\Requests\CreateSharePreviewRequest;
use App\Http\Requests\OverridePostApprovalRequest;
use App\Http\Requests\QuickSchedulePostRequest;
use App\Http\Requests\ReschedulePostRequest;
use App\Http\Requests\StorePostCommentRequest;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\TransitionPostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Models\AiImageGeneration;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Analytics\BestTimeService;
use App\Services\ClientHashtagService;
use App\Services\Grids\GridPlanService;
use App\Services\PostNotificationService;
use App\Services\Posts\PostWorkflowResolver;
use App\Services\Publishing\PlatformRolloutGate;
use App\Support\AI\Images\ImageStudioPresenter;
use App\Support\Campaigns\CampaignPresenter;
use App\Support\Grids\GridLibrary;
use App\Support\Posts\PostMediaPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    /**
     * Create a new post (with its per-account targets) for the given client, then open it in the
     * editor so media, hashtags and per-target times can be added straight away.
     */
    public function store(StorePostRequest $request, Client $client, CreatePostAction $action): RedirectResponse
    {
        $post = $action($client, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Post created.')]);

        return to_route('posts.edit', $post);
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
        ApplyCampaignDefaultsAction $defaults,
        PlatformRolloutGate $rollout,
        GridPlanService $grids,
        BestTimeService $bestTime,
        PostWorkflowResolver $workflows,
    ): Response {
        $this->authorize('view', $post);

        $user = $request->user();
        $post->load([
            // Step B-3.1: the workspace workflow needs the org type (business vs agency).
            'organization',
            // Step I-2.6: the editor reads the client and its brand brain (hashtag policy).
            'client.brandBrain',
            'targets.socialAccount',
            'assets',
            'coverAsset',
            'campaign',
            'activityLogs.user',
            'approvals.user',
            'comments.user',
            'comments.mentionedUsers',
        ]);

        $client = $post->client;
        // Step B-3.1: the legal next steps come from this workspace's workflow (agency keeps the
        // full client-approval lifecycle; a business workspace self-approves or opts into review),
        // then narrowed to the ones this user is authorized to make.
        $allowedTransitions = array_values(array_filter(
            $workflows->forPost($post)->allowedTransitions($post, $user),
            fn (PostStatus $to) => $user->can('transition', [$post, $to]),
        ));

        $hashtagSuggestions = $this->hashtagSuggestions($client, $hashtags);
        $mentionableUsers = $notifications->mentionableRecipients($post, exclude: $user);

        // Step M-2.6: an account on a platform that isn't switched on for publishing yet is only
        // listed when the post already targets it, so it can be removed.
        $accounts = $client->socialAccounts()->get()
            ->filter(fn (SocialAccount $account) => $rollout->platformEnabled($account->platform)
                || $post->targets->contains('social_account_id', $account->id))
            ->values();
        $bestTimes = $bestTime->forAccounts(array_values(array_map('intval', $accounts->modelKeys())));

        return Inertia::render('posts/edit', [
            'post' => $this->transformPostForEditor($post, $evaluateChecklist),
            'client' => ['id' => $client->id, 'name' => $client->name],
            // Per-platform limits travel with every selectable account (not just the post's saved
            // targets) so the editor can show caption/hashtag usage and a live readiness check the
            // moment a new channel is toggled on, before anything is saved.
            'targetAccounts' => $accounts->map(fn (SocialAccount $account) => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'platform_label' => $account->platform->label(),
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'timezone' => $account->timezone,
                'caption_limit' => $account->platform->captionLimit(),
                'max_hashtags' => $account->platform->maxHashtags(),
                'is_text_only_capable' => $account->platform->isTextOnlyCapable(),
                'enabled' => $rollout->platformEnabled($account->platform),
                'connection_status' => $account->connection_status->value,
                // Step M-2.6: registry `required_settings` this account hasn't filled in.
                'missing_settings' => array_values(array_filter(
                    $account->platform->spec()->requiredSettings,
                    fn (string $setting) => blank($account->platform_settings[$setting] ?? null),
                )),
                // Step M-3.2: best slots from this account's own engagement (or "learning").
                'best_time' => $bestTimes[$account->id] ?? null,
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
                // Step M-2.9: approve without the client (Owner, with a reason).
                'override_approval' => $user->can('overrideApproval', $post),
                'delete' => $user->can('delete', $post),
                'create_asset' => $user->can('create', [Asset::class, $client]),
                // Step B-3.2 / B-3.4: the one-tap schedule and the share-preview link.
                'quick_schedule' => $user->can('transition', [$post, PostStatus::Scheduled]),
                'share_preview' => $user->can('sharePreview', $post),
            ],
            // Step B-3.2: the business editor's one-tap primary action reads this to pick its label
            // ("Schedule"/"Post now" when review is off, "Send for review" when it's on).
            'workflow' => [
                'is_business' => $post->organization->isBusiness(),
                'requires_review' => $workflows->forPost($post)->requiresReview($post->organization),
                // "Schedule" needs a time on every target; "Post now" doesn't. Lets the bar disable
                // "Schedule" with a hint rather than bounce the user off a checklist failure.
                'has_times' => $post->targets->isNotEmpty()
                    && $post->targets->every(fn (PostTarget $target) => $target->scheduled_at_utc !== null),
            ],
            'hashtagSuggestions' => $hashtagSuggestions,
            // The image formats each channel offers (the preview's "Format" picker).
            'mediaFormatOptions' => PostMediaPresenter::formatOptions(),
            // Campaigns enhancement C-1.3 / C-4.2: the client's open campaigns (plus the post's
            // own, whatever its status) with what joining each offers the post.
            'campaignOptions' => $this->campaignOptions($post, $defaults),
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
            // Step 18.9: "From grid: {name} · Tile 4 · Goes out 3rd", for a post made from a tile.
            'grid' => GridLibrary::postChip($post, $grids),
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
     * Step B-3.2 — the solo owner's one-tap schedule. Thin: it hands the post, actor and "when"
     * ("schedule" uses the post's own times, "now" retimes every target to publish immediately) to
     * `QuickSchedulePostAction`, which self-approves + schedules (review off) or sends for review
     * (review on). A not-ready post surfaces as a normal validation error on the editor.
     */
    public function quickSchedule(QuickSchedulePostRequest $request, Post $post, QuickSchedulePostAction $action): RedirectResponse
    {
        $post = $action($post, $request->user(), $request->validated('when') ?? QuickSchedulePostAction::WHEN_SCHEDULED);

        $message = $post->status === PostStatus::InternalReview
            ? __('Sent for review.')
            : __('Scheduled. We’ll post it for you.');

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('posts.edit', $post);
    }

    /**
     * Step B-3.4 — mint a read-only "Share preview" link (a partner/sponsor link) for this post and
     * return the plain URL once as JSON (it's hashed at rest, so it's never recoverable afterwards).
     */
    public function sharePreview(CreateSharePreviewRequest $request, Post $post, CreateSharePreviewAction $action): JsonResponse
    {
        $days = $request->integer('expires_in_days') ?: 14;

        $result = $action(
            $post,
            $request->boolean('allow_comments'),
            $request->boolean('allow_approval'),
            now()->addDays($days),
        );

        return response()->json(['url' => route('review.show', $result['token'])]);
    }

    /**
     * Step I-3.8 / M-2.9 — an Owner approves a `client_required` post without the client, with a
     * reason that's recorded on the approval and the activity log.
     */
    public function overrideApproval(OverridePostApprovalRequest $request, Post $post, TransitionPostStatusAction $action): RedirectResponse
    {
        $action->overrideApproval($post, $request->user(), $request->validated('reason'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Approved without the client. The reason is on the activity log.')]);

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

        // Step B-5.3: an optional `target` retries just that one failed channel, leaving the
        // others. The id must belong to this post (a channel from another post can't be retried).
        $validated = $request->validate([
            'target' => ['nullable', 'integer'],
        ]);

        $only = isset($validated['target'])
            ? ($post->targets()->whereKey($validated['target'])->first() ?? abort(404))
            : null;

        $action($post, $request->user(), $only);

        Inertia::flash('toast', ['type' => 'success', 'message' => $only !== null
            ? __('Retrying this channel.')
            : __('Retrying the failed channels.')]);

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
     * Campaigns enhancement C-1.3 / C-4.2 — the campaigns a post can join (the client's open ones,
     * plus its current campaign), each with its chip and what it offers: its hashtag set and its
     * default link with UTM parameters for the post's first platform.
     *
     * @return list<array<string, mixed>>
     */
    private function campaignOptions(Post $post, ApplyCampaignDefaultsAction $defaults): array
    {
        $platform = $post->targets->first()?->socialAccount?->platform->value;
        // Step M-2.2: each channel gets the link tagged with its own platform.
        $platforms = $post->client->socialAccounts()->pluck('platform')
            ->map(fn ($value) => $value instanceof Platform ? $value->value : (string) $value)
            ->all();

        return array_values(Campaign::query()
            ->where('client_id', $post->client_id)
            ->where(fn ($query) => $query->whereIn('status', CampaignStatus::open())->orWhere('id', $post->campaign_id))
            ->orderBy('name')
            ->get()
            ->map(fn (Campaign $campaign) => [
                ...CampaignPresenter::chip($campaign),
                'status' => $campaign->status->value,
                'hashtags' => $campaign->hashtags ?? [],
                'link' => $defaults->link($campaign, $platform),
                'links' => (object) $defaults->linksFor($campaign, array_values($platforms)),
            ])
            ->all());
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
            'campaign' => $post->campaign ? CampaignPresenter::chip($post->campaign) : null,
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'approval_mode' => $post->approval_mode->value,
            'master_caption' => $post->master_caption,
            'review_message' => $post->review_message,
            'hashtags' => $post->hashtags ?? [],
            'link_url' => $post->link_url,
            'first_comment' => $post->first_comment,
            'cover_asset_id' => $post->cover_asset_id,
            'thumb_offset_ms' => $post->thumb_offset_ms,
            'music' => $post->music,
            'location' => $post->location,
            'media_format' => PostMediaPresenter::mediaFormat($post),
            'checklist' => $evaluateChecklist($post),
            'assets' => $post->assets->map(fn ($asset) => PostMediaPresenter::asset($asset))->values(),
            'targets' => $post->targets->map(fn (PostTarget $target) => [
                'id' => $target->id,
                'social_account_id' => $target->social_account_id,
                'platform' => $target->socialAccount?->platform?->value,
                'handle' => $target->socialAccount?->handle,
                'display_name' => $target->socialAccount?->display_name,
                'caption_limit' => $target->socialAccount?->platform?->captionLimit(),
                'is_text_only_capable' => $target->socialAccount?->platform?->isTextOnlyCapable() ?? false,
                ...PostMediaPresenter::channel($target->setRelation('post', $post)),
                'scheduled_local_date' => $target->scheduled_local_date?->toDateString(),
                'scheduled_local_time' => $target->scheduled_local_time,
                'scheduled_at_utc' => $target->scheduled_at_utc?->toIso8601String(),
                'status' => $target->status->value,
                'status_label' => $target->status->label(),
                'external_post_id' => $target->external_post_id,
                'error' => $target->error,
                // Step B-5.3: a plain-language reading of a failed channel's vendor error, with a
                // fix action and whether a bare retry will help.
                'failure' => $target->status === PostTargetStatus::Failed && filled($target->error)
                    ? PublishFailureReason::classify($target->error, $target->socialAccount?->platform)
                        ->describe($target->socialAccount?->platform)
                    : null,
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
