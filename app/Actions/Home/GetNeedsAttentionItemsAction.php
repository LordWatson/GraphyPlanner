<?php

namespace App\Actions\Home;

use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Enums\ConnectionStatus;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;

class GetNeedsAttentionItemsAction
{
    public function __construct(
        private readonly EvaluatePostChecklistAction $evaluatePostChecklist,
    ) {}

    /**
     * Build the Step 0.12 "Home / needs-attention" payload: failed publishes, posts waiting on
     * client approval, posts missing required media, and disconnected social accounts. Every row
     * carries enough context (client, platform/handle) plus a `post_id` (or `client_id` for
     * account rows, which have no single post) so the UI can link straight to the relevant post.
     *
     * Scoped like `CalendarController`/`GetUpcomingPostOccurrencesAction`: a `Role::ClientReviewer`
     * only ever sees their own client's rows.
     *
     * @return array{
     *     failedPublishes: array<int, array<string, mixed>>,
     *     waitingApprovals: array<int, array<string, mixed>>,
     *     missingMedia: array<int, array<string, mixed>>,
     *     disconnectedAccounts: array<int, array<string, mixed>>,
     * }
     */
    public function __invoke(User $user): array
    {
        $isClientReviewer = $user->role === Role::ClientReviewer;

        $postsQuery = Post::query()
            ->where('org_id', $user->org_id)
            ->with(['client:id,name', 'targets.socialAccount', 'assets']);

        if ($isClientReviewer) {
            $postsQuery->where('client_id', $user->client_id);
        }

        $posts = $postsQuery->get();

        $failedPublishes = $posts
            ->filter(fn (Post $post) => $post->status === PostStatus::Failed)
            ->map(fn (Post $post) => $this->transformPost($post, 'Publish failed'))
            ->values()
            ->all();

        $waitingApprovals = $posts
            ->filter(fn (Post $post) => $post->status === PostStatus::WaitingClient)
            ->map(fn (Post $post) => $this->transformPost($post, 'Waiting on client approval'))
            ->values()
            ->all();

        $activeStatuses = [
            PostStatus::Idea, PostStatus::Draft, PostStatus::InternalReview,
            PostStatus::WaitingClient, PostStatus::ChangesRequested, PostStatus::Approved,
        ];

        $missingMedia = $posts
            ->filter(fn (Post $post) => in_array($post->status, $activeStatuses, true))
            ->filter(function (Post $post) {
                $checklist = ($this->evaluatePostChecklist)($post);
                $mediaItem = collect($checklist['items'])->firstWhere('key', 'media');

                return $mediaItem !== null && $mediaItem['passed'] === false;
            })
            ->map(fn (Post $post) => $this->transformPost($post, 'Missing required media'))
            ->values()
            ->all();

        $accountsQuery = SocialAccount::query()
            ->where('org_id', $user->org_id)
            ->whereIn('connection_status', [ConnectionStatus::NotConnected, ConnectionStatus::TokenExpired])
            ->with('client:id,name');

        if ($isClientReviewer) {
            $accountsQuery->where('client_id', $user->client_id);
        }

        $disconnectedAccounts = $accountsQuery->get()
            ->map(fn (SocialAccount $account) => [
                'account_id' => $account->id,
                'client_id' => $account->client_id,
                'client_name' => $account->client?->name,
                'platform' => $account->platform->value,
                'platform_label' => $account->platform->label(),
                'handle' => $account->handle,
                'connection_status' => $account->connection_status->value,
                'connection_status_label' => $account->connection_status->label(),
            ])
            ->values()
            ->all();

        return [
            'failedPublishes' => $failedPublishes,
            'waitingApprovals' => $waitingApprovals,
            'missingMedia' => $missingMedia,
            'disconnectedAccounts' => $disconnectedAccounts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformPost(Post $post, string $reason): array
    {
        $target = $post->targets->first();
        $account = $target?->socialAccount;

        return [
            'post_id' => $post->id,
            'client_id' => $post->client_id,
            'client_name' => $post->client?->name,
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'reason' => $reason,
            'master_caption' => $post->master_caption,
            'platform' => $account?->platform?->value,
            'handle' => $account?->handle,
        ];
    }
}
