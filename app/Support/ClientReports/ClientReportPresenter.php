<?php

namespace App\Support\ClientReports;

use App\Models\ClientReport;
use App\Models\Post;
use App\Models\Scopes\OrganizationScope;

/**
 * Step M-3.3 — the shapes a monthly client report travels in. `document()` is everything the
 * report page draws (the snapshot, the commentary and fresh thumbnails for its top posts — the
 * snapshot never stores signed URLs); `row()` is the list entry. Identical for staff and the
 * portal: the snapshot is client-safe by construction.
 */
final class ClientReportPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(ClientReport $report): array
    {
        $snapshot = $report->snapshot ?? [];

        return [
            'id' => $report->id,
            'period_start' => $report->period_start->toDateString(),
            'period_label' => $report->periodLabel(),
            'status' => $report->status->value,
            'status_label' => $report->status->label(),
            'published_at' => $report->published_at?->toIso8601String(),
            'sent_at' => $report->sent_at?->toIso8601String(),
            'recipients_count' => $report->recipients_count,
            'posts_published' => $snapshot['posts']['published'] ?? null,
            'engagement' => $snapshot['engagement']['totals']['engagement'] ?? null,
            'follower_growth' => $snapshot['engagement']['followers']['growth'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(ClientReport $report): array
    {
        $snapshot = $report->snapshot ?? [];
        $postIds = array_values(array_filter(array_map(fn ($post) => $post['post_id'] ?? null, (array) ($snapshot['top_posts'] ?? []))));

        $thumbnails = $postIds === [] ? [] : Post::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('client_id', $report->client_id)
            ->whereIn('id', $postIds)
            ->with('assets')
            ->get()
            ->mapWithKeys(fn (Post $post) => [$post->id => $post->assets->first()?->thumbnailUrl()])
            ->all();

        return [
            ...self::row($report),
            'commentary' => $report->commentary,
            'snapshot_at' => $report->snapshot_at?->toIso8601String(),
            'snapshot' => $snapshot === [] ? null : $snapshot,
            'thumbnails' => (object) $thumbnails,
        ];
    }
}
