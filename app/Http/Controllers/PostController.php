<?php

namespace App\Http\Controllers;

use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\CreatePostCommentAction;
use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Actions\Posts\PostStatusTransitionMap;
use App\Actions\Posts\ResendClientReviewEmailAction;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Actions\Posts\UpdatePostAction;
use App\Enums\PostStatus;
use App\Http\Requests\StorePostCommentRequest;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\TransitionPostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Models\Client;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Services\PostNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return to_route('clients.show', $client);
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
    ): Response {
        $this->authorize('view', $post);

        $user = $request->user();
        $post->load([
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

        $hashtagSuggestions = $this->hashtagSuggestions($client);
        $mentionableUsers = $notifications->mentionableRecipients($post, exclude: $user);

        return Inertia::render('posts/edit', [
            'post' => $this->transformPostForEditor($post, $evaluateChecklist),
            'client' => ['id' => $client->id, 'name' => $client->name],
            'targetAccounts' => $client->socialAccounts()->get()->map(fn ($account) => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'handle' => $account->handle,
                'timezone' => $account->timezone,
            ]),
            'availableAssets' => $client->assets()->latest()->get()->map(fn ($asset) => [
                'id' => $asset->id,
                'url' => $asset->url,
                'original_filename' => $asset->original_filename,
                'type' => $asset->type?->value,
            ]),
            'allowedTransitions' => array_map(
                fn (PostStatus $to) => ['value' => $to->value, 'label' => $to->label()],
                $allowedTransitions,
            ),
            'can' => [
                'update' => $user->can('update', $post),
                'comment' => $user->can('comment', $post),
                'resend_review_email' => $user->can('resendReviewEmail', $post),
            ],
            'hashtagSuggestions' => $hashtagSuggestions,
            'mentionableUsers' => $mentionableUsers->map(fn ($mentionable) => [
                'id' => $mentionable->id,
                'name' => $mentionable->name,
            ])->values(),
        ]);
    }

    /**
     * Update a post's content, per-target schedule, and attached media.
     */
    public function update(UpdatePostRequest $request, Post $post, UpdatePostAction $action): RedirectResponse
    {
        $action($post, $request->validated());

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
        $action(
            $post,
            PostStatus::from($request->validated('to')),
            $request->user(),
            $request->validated('comment'),
        );

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
     * Build the hashtag autocomplete list (Instagram-style tag picker) shown in the editor: the
     * client's `BrandBrain` `hashtag_policy.always_use` list plus every hashtag already used
     * across the client's other posts, deduped and capped so the suggestion list stays useful.
     *
     * @return array<int, string>
     */
    private function hashtagSuggestions(Client $client): array
    {
        $fromBrandBrain = collect($client->brandBrain?->hashtag_policy['always_use'] ?? []);

        $fromPosts = $client->posts()
            ->whereNotNull('hashtags')
            ->pluck('hashtags')
            ->flatten();

        return $fromBrandBrain->merge($fromPosts)
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
                'review_url' => $log->review_url,
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
