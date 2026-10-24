<?php

namespace App\Actions\SuperAdmin;

use App\Models\ProductFeedback;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Step M-8.2 — the platform feedback inbox: every in-app note, newest first, optionally narrowed to
 * one organization. Super admins are org-less, so the `OrganizationScope` safety net doesn't filter
 * here; the `org_id` filter is an explicit choice, not a tenant boundary.
 */
class ListProductFeedbackAction
{
    private const PER_PAGE = 20;

    /**
     * @return LengthAwarePaginator<int, ProductFeedback>
     */
    public function __invoke(?int $orgId = null): LengthAwarePaginator
    {
        return ProductFeedback::query()
            ->with(['organization:id,name,slug', 'user:id,name,email'])
            ->when($orgId !== null, fn (Builder $query) => $query->where('org_id', $orgId))
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * The newest notes for the partners overview panel.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 6): array
    {
        return array_values(ProductFeedback::query()
            ->with(['organization:id,name,slug', 'user:id,name,email'])
            ->latest()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(self::row(...))
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(ProductFeedback $feedback): array
    {
        return [
            'id' => $feedback->id,
            'message' => $feedback->message,
            'page' => $feedback->page,
            'created_at' => $feedback->created_at?->toIso8601String(),
            'organization' => $feedback->organization === null ? null : [
                'id' => $feedback->organization->id,
                'name' => $feedback->organization->name,
                'slug' => $feedback->organization->slug,
            ],
            'user' => $feedback->user === null ? null : [
                'id' => $feedback->user->id,
                'name' => $feedback->user->name,
                'email' => $feedback->user->email,
            ],
        ];
    }
}
