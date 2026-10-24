<?php

namespace App\Actions\Inbox;

use App\Enums\InboxStatus;
use App\Models\InboxConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Step M-9.2 — the inbox list, server-side filtered and paginated. Eager-loads the client, account,
 * assignee and latest message so the triage list renders without an N+1, and leans on the
 * `inbox_conversations` composite indexes for every filter. The org scope is applied globally
 * (`BelongsToOrganization`), so this only ever sees the signed-in member's organization.
 *
 * @phpstan-type InboxFilters array{status?: string|null, sentiment?: string|null, client_id?: int|null, platform?: string|null, assignee?: string|null, reply_needed?: bool, search?: string|null}
 */
class ListInboxConversationsAction
{
    /**
     * @param  InboxFilters  $filters
     * @return LengthAwarePaginator<int, InboxConversation>
     */
    public function __invoke(User $user, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($user, $filters)
            ->with([
                'client:id,name',
                'socialAccount:id,platform,handle,display_name',
                'assignee:id,name',
                'latestMessage',
            ])
            ->orderByDesc('is_reply_needed')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The filtered query, shared by the list and the unread/attention counts.
     *
     * @param  InboxFilters  $filters
     * @return Builder<InboxConversation>
     */
    public function query(User $user, array $filters): Builder
    {
        return InboxConversation::query()
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(blank($filters['status'] ?? null), fn (Builder $q) => $q->whereIn('status', InboxStatus::activeValues()))
            ->when(filled($filters['sentiment'] ?? null), fn (Builder $q) => $q->where('sentiment', $filters['sentiment']))
            ->when(filled($filters['client_id'] ?? null), fn (Builder $q) => $q->where('client_id', (int) $filters['client_id']))
            ->when(filled($filters['platform'] ?? null), fn (Builder $q) => $q->where('platform', $filters['platform']))
            ->when(($filters['reply_needed'] ?? false) === true, fn (Builder $q) => $q->where('is_reply_needed', true))
            ->when(($filters['assignee'] ?? null) === 'me', fn (Builder $q) => $q->where('assigned_to', $user->id))
            ->when(($filters['assignee'] ?? null) === 'unassigned', fn (Builder $q) => $q->whereNull('assigned_to'))
            ->when(
                filled($filters['assignee'] ?? null) && ! in_array($filters['assignee'], ['me', 'unassigned'], true),
                fn (Builder $q) => $q->where('assigned_to', (int) $filters['assignee']),
            )
            ->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $q->where(fn (Builder $inner) => $inner
                    ->where('author_name', 'like', $term)
                    ->orWhere('author_handle', 'like', $term)
                    ->orWhereHas('messages', fn (Builder $m) => $m->where('body', 'like', $term)));
            });
    }
}
