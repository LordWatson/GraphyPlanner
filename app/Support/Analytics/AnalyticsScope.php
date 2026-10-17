<?php

namespace App\Support\Analytics;

use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;

/**
 * Step 17.1 — *which* rows an analytics query covers: a list of organizations (the platform
 * console passes many, an organization passes its own id), optionally narrowed to clients,
 * campaigns and target platforms (Step 17.2's org filters).
 *
 * Every metric service takes one of these plus an `AnalyticsRange`, so the platform and org
 * surfaces share the same queries and can't drift. Narrowing only applies where a dimension
 * exists: client ids narrow posts, invoices and AI usage; campaigns and platforms narrow posts only.
 */
final readonly class AnalyticsScope
{
    /**
     * @param  list<int>  $orgIds
     * @param  list<int>  $clientIds  empty = every client
     * @param  list<int>  $campaignIds  empty = every campaign
     * @param  list<string>  $platforms  `App\Enums\Platform` values; empty = every platform
     */
    public function __construct(
        public array $orgIds,
        public array $clientIds = [],
        public array $campaignIds = [],
        public array $platforms = [],
    ) {}

    /**
     * @param  list<int>  $orgIds
     */
    public static function organizations(array $orgIds): self
    {
        return new self($orgIds);
    }

    public function narrowsPosts(): bool
    {
        return $this->clientIds !== [] || $this->campaignIds !== [] || $this->platforms !== [];
    }

    /**
     * The in-scope posts (for `whereIn('post_id', …)` subqueries, select `id`).
     *
     * @return Builder<Post>
     */
    public function posts(): Builder
    {
        return Post::query()
            ->whereIn('posts.org_id', $this->orgIds)
            ->when($this->clientIds !== [], fn (Builder $query) => $query->whereIn('posts.client_id', $this->clientIds))
            ->when($this->campaignIds !== [], fn (Builder $query) => $query->whereIn('posts.campaign_id', $this->campaignIds))
            ->when($this->platforms !== [], fn (Builder $query) => $query->whereHas(
                'targets.socialAccount',
                fn (Builder $account) => $account->whereIn('platform', $this->platforms),
            ));
    }

    /**
     * Apply the org + client narrowing to any query on a table with `org_id` and `client_id`
     * (invoices, AI usage logs, clients).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyToClientOwned(Builder $query, string $clientColumn = 'client_id', string $orgColumn = 'org_id'): Builder
    {
        return $query
            ->whereIn($orgColumn, $this->orgIds)
            ->when($this->clientIds !== [], fn (Builder $query) => $query->whereIn($clientColumn, $this->clientIds));
    }
}
