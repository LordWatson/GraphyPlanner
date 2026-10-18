<?php

namespace App\Actions\Home;

use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class GetNeedsAttentionItemsAction
{
    /**
     * Rows returned per bucket. The bucket's `total` is always the full count.
     */
    public const DEFAULT_LIMIT = 20;

    /**
     * Statuses a post can still be worked on in — the "missing media" bucket only looks at these.
     *
     * @var list<PostStatus>
     */
    private const ACTIVE_STATUSES = [
        PostStatus::Idea, PostStatus::Draft, PostStatus::InternalReview,
        PostStatus::WaitingClient, PostStatus::ChangesRequested, PostStatus::Approved,
    ];

    /**
     * Build the Step 0.12 "Home / needs-attention" payload: failed publishes, posts waiting on
     * internal (studio) review, posts waiting on client approval, posts missing required media, and
     * disconnected social accounts. Every row carries enough context (client, platform/handle) plus
     * a `post_id` (or `client_id` for account rows, which have no single post) so the UI can link
     * straight to the relevant post.
     *
     * Step I-2.2: every bucket is one bounded query (`limit`) plus a count, so the query count is
     * fixed no matter how many posts the org has. "Missing media" is the SQL form of the
     * `EvaluatePostChecklistAction` media rule: no assets, and either no targets or at least one
     * target on a platform outside `Platform::textOnlyCapableValues()`.
     *
     * Scoped like `CalendarController`/`GetUpcomingPostOccurrencesAction`: a `Role::ClientReviewer`
     * only ever sees their own client's rows.
     *
     * @return array{
     *     failedPublishes: array{items: list<array<string, mixed>>, total: int},
     *     internalReviews: array{items: list<array<string, mixed>>, total: int},
     *     waitingApprovals: array{items: list<array<string, mixed>>, total: int},
     *     missingMedia: array{items: list<array<string, mixed>>, total: int},
     *     disconnectedAccounts: array{items: list<array<string, mixed>>, total: int},
     * }
     */
    public function __invoke(User $user, int $limit = self::DEFAULT_LIMIT): array
    {
        $statusCounts = $this->posts($user)
            ->whereIn('status', [PostStatus::Failed, PostStatus::PartiallyPublished, PostStatus::InternalReview, PostStatus::WaitingClient])
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $count = fn (PostStatus ...$statuses): int => (int) collect($statuses)->sum(fn (PostStatus $status) => $statusCounts[$status->value] ?? 0);

        $missingMediaQuery = $this->missingMedia($this->posts($user));

        $accountsQuery = SocialAccount::query()
            ->where('org_id', $user->org_id)
            ->whereIn('connection_status', [ConnectionStatus::NotConnected, ConnectionStatus::TokenExpired])
            ->when($user->role === Role::ClientReviewer, fn (Builder $query) => $query->where('client_id', $user->client_id));

        return [
            'failedPublishes' => [
                'items' => $this->rows(
                    $this->posts($user)->whereIn('status', [PostStatus::Failed, PostStatus::PartiallyPublished]),
                    $limit,
                    // Step I-1.3: a partial publish needs the same attention (retry the failed channels).
                    fn (Post $post) => $post->status === PostStatus::PartiallyPublished ? 'Partly published — some channels failed' : 'Publish failed',
                ),
                'total' => $count(PostStatus::Failed, PostStatus::PartiallyPublished),
            ],
            'internalReviews' => [
                'items' => $this->rows($this->posts($user)->where('status', PostStatus::InternalReview), $limit, fn () => 'Waiting on internal review'),
                'total' => $count(PostStatus::InternalReview),
            ],
            'waitingApprovals' => [
                'items' => $this->rows($this->posts($user)->where('status', PostStatus::WaitingClient), $limit, fn () => 'Waiting on client approval'),
                'total' => $count(PostStatus::WaitingClient),
            ],
            'missingMedia' => [
                'items' => $this->rows(clone $missingMediaQuery, $limit, fn () => 'Missing required media'),
                'total' => $missingMediaQuery->count(),
            ],
            'disconnectedAccounts' => [
                'items' => array_values((clone $accountsQuery)
                    ->with('client:id,name')
                    ->orderBy('id')
                    ->limit($limit)
                    ->get()
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
                    ->all()),
                'total' => $accountsQuery->count(),
            ],
        ];
    }

    /**
     * @return Builder<Post>
     */
    private function posts(User $user): Builder
    {
        return Post::query()
            ->forActiveClients()
            ->where('org_id', $user->org_id)
            ->when($user->role === Role::ClientReviewer, fn (Builder $query) => $query->where('client_id', $user->client_id));
    }

    /**
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    private function missingMedia(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereDoesntHave('assets')
            ->where(fn (Builder $query) => $query
                ->whereDoesntHave('targets')
                ->orWhereHas('targets.socialAccount', fn (Builder $account) => $account->whereNotIn('platform', Platform::textOnlyCapableValues())));
    }

    /**
     * @param  Builder<Post>  $query
     * @param  callable(Post): string  $reason
     * @return list<array<string, mixed>>
     */
    private function rows(Builder $query, int $limit, callable $reason): array
    {
        return array_values($query
            ->with(['client:id,name', 'targets:id,post_id,social_account_id', 'targets.socialAccount:id,platform,handle'])
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'org_id', 'client_id', 'status', 'master_caption'])
            ->map(fn (Post $post) => $this->transformPost($post, $reason($post)))
            ->all());
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
