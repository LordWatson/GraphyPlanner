<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Step M-5.1 — the org-wide content hub query (`/content`). Pages through every client's posts for
 * the signed-in organization (the `OrganizationScope` pins the tenant), newest-updated first, with
 * server-side filters so a strategist can find work across the whole roster in one place. The
 * campaign board already groups a single campaign's posts; this is the roster-wide equivalent.
 *
 * Archived posts are dropped work: hidden unless the status filter is explicitly "Archived".
 */
class ListPostsAction
{
    public const PER_PAGE = 20;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Post>
     */
    public function __invoke(array $filters = []): LengthAwarePaginator
    {
        return $this->query($filters)
            ->with(['client:id,name', 'campaign', 'creator:id,name', 'assignee:id,name', 'targets.socialAccount', 'assets:id'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * The filtered base query, shared by the paginated table and the (capped) board.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Post>
     */
    public function query(array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        return Post::query()
            ->when($search !== '', function (Builder $query) use ($search) {
                // The user's input is literal — `%` / `_` are not wildcards here.
                $escaped = addcslashes($search, '\%_');
                $query->whereRaw("lower(master_caption) like ? escape '\'", ['%'.mb_strtolower($escaped).'%']);
            })
            ->when(filled($status), fn (Builder $query) => $query->where('status', $status))
            // Archived posts surface only when asked for by name.
            ->when(blank($status), fn (Builder $query) => $query->where('status', '!=', PostStatus::Archived->value))
            ->when(filled($filters['client'] ?? null), fn (Builder $query) => $query->where('client_id', $filters['client']))
            ->when(filled($filters['campaign'] ?? null), fn (Builder $query) => $query->where('campaign_id', $filters['campaign']))
            // Step M-5.4: filter by assignee (0 = unassigned).
            ->when(($filters['assignee'] ?? null) === 0, fn (Builder $query) => $query->whereNull('assignee_id'))
            ->when(filled($filters['assignee'] ?? null), fn (Builder $query) => $query->where('assignee_id', $filters['assignee']))
            ->when(filled($filters['platform'] ?? null), fn (Builder $query) => $query->whereHas(
                'targets.socialAccount',
                fn (Builder $account) => $account->where('platform', $filters['platform']),
            ))
            ->when(($filters['has_media'] ?? null) === true, fn (Builder $query) => $query->whereHas('assets'))
            ->when(($filters['has_media'] ?? null) === false, fn (Builder $query) => $query->whereDoesntHave('assets'))
            ->when(filled($from) || filled($to), fn (Builder $query) => $query->whereHas(
                'targets',
                fn (Builder $target) => $target
                    ->when(filled($from), fn (Builder $q) => $q->whereDate('scheduled_local_date', '>=', $from))
                    ->when(filled($to), fn (Builder $q) => $q->whereDate('scheduled_local_date', '<=', $to)),
            ));
    }
}
