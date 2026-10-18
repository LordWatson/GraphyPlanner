<?php

namespace App\Actions\Posts;

use App\Models\Client;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ListClientPostsAction
{
    public const PER_PAGE = 8;

    /**
     * Page through a client's posts, newest first, filtered by caption search, status, campaign
     * and/or target channel. Backs the client page's Posts tab.
     *
     * @param  array{search?: string|null, status?: string|null, campaign?: int|null, platform?: string|null}  $filters
     * @return LengthAwarePaginator<int, Post>
     */
    public function __invoke(Client $client, array $filters = []): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $platform = $filters['platform'] ?? null;

        return $client->posts()
            ->with(['targets.socialAccount', 'campaign'])
            ->when($search !== '', function (Builder $query) use ($search) {
                // Treat the user's input literally: `%` / `_` are not wildcards here.
                $escaped = addcslashes($search, '\%_');
                $query->whereRaw("lower(master_caption) like ? escape '\'", ['%'.mb_strtolower($escaped).'%']);
            })
            ->when(filled($filters['status'] ?? null), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(filled($filters['campaign'] ?? null), fn (Builder $query) => $query->where('campaign_id', $filters['campaign']))
            ->when(filled($platform), fn (Builder $query) => $query->whereHas(
                'targets.socialAccount',
                fn (Builder $account) => $account->where('platform', $platform),
            ))
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }
}
