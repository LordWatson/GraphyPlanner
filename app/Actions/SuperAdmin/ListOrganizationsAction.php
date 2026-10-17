<?php

namespace App\Actions\SuperAdmin;

use App\Enums\PostStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Step 15.3 — the super admin's searchable, filterable, paginated organization directory.
 * Search matches the org name/slug or any member's name/email (so support can find an org from
 * whoever wrote in).
 */
class ListOrganizationsAction
{
    public const STATUSES = ['all', 'active', 'suspended'];

    public const SORTS = ['newest', 'oldest', 'name', 'clients', 'published'];

    private const PER_PAGE = 15;

    /**
     * @param  array{search?: string|null, status?: string|null, sort?: string|null}  $filters
     * @return LengthAwarePaginator<int, Organization>
     */
    public function __invoke(array $filters = []): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? 'all';
        $sort = in_array($filters['sort'] ?? null, self::SORTS, true) ? $filters['sort'] : 'newest';

        $query = Organization::query()
            ->with('owner')
            ->withCount([
                'users',
                'clients',
                'posts as published_posts_count' => fn (Builder $posts) => $posts->where('status', PostStatus::Published),
            ])
            ->when($status === 'active', fn (Builder $query) => $query->active())
            ->when($status === 'suspended', fn (Builder $query) => $query->suspended())
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.mb_strtolower($search).'%';

                $query->where(function (Builder $query) use ($like) {
                    $query->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(slug) LIKE ?', [$like])
                        ->orWhereHas('users', fn (Builder $users) => $users->where(fn (Builder $users) => $users
                            ->whereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$like])));
                });
            });

        match ($sort) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'name' => $query->orderBy('name')->orderBy('id'),
            'clients' => $query->orderByDesc('clients_count')->orderBy('name'),
            'published' => $query->orderByDesc('published_posts_count')->orderBy('name'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        return $query->paginate(self::PER_PAGE)->withQueryString();
    }
}
