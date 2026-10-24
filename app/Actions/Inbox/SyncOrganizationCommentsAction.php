<?php

namespace App\Actions\Inbox;

use App\Enums\ConnectionStatus;
use App\Enums\PostTargetStatus;
use App\Exceptions\Inbox\CommentSourceException;
use App\Models\Organization;
use App\Models\PostTarget;
use App\Models\Scopes\OrganizationScope;
use App\Services\Publishing\PlatformCapabilities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Step M-9.1 — one organization's comment sweep (run hourly by `SyncOrganizationCommentsJob`):
 * every channel published in the last `inbox.sync.window_days` on a comment-supporting platform gets
 * its comments pulled by {@see IngestCommentsForTargetAction}, oldest-polled first, at most
 * `max_targets_per_org` per run (vendor rate limits) — the rest go next run.
 *
 * A target polled within `min_interval_minutes` is skipped unless `$force`. Suspended organizations
 * and organizations with no Upload-Post key are skipped entirely. `comments_synced_at` is stamped
 * whether a target succeeds or fails, so a persistently-failing post can't starve the others; a
 * failure is logged (never the body) and the sweep continues.
 *
 * @phpstan-type SyncSummary array{targets: int, conversations: int, messages: int, failed: int, skipped: string|null}
 */
class SyncOrganizationCommentsAction
{
    public function __construct(
        private readonly IngestCommentsForTargetAction $ingest,
        private readonly PlatformCapabilities $platforms = new PlatformCapabilities,
    ) {}

    /**
     * @return SyncSummary
     */
    public function __invoke(Organization $organization, bool $force = false): array
    {
        $summary = ['targets' => 0, 'conversations' => 0, 'messages' => 0, 'failed' => 0, 'skipped' => null];

        if ($organization->isSuspended() || blank($organization->upload_post_key)) {
            $summary['skipped'] = $organization->isSuspended() ? 'suspended' : 'no_vendor_key';
            Log::info('Inbox sync skipped', ['org_id' => $organization->id, 'reason' => $summary['skipped']]);

            return $summary;
        }

        $commentable = $this->platforms->commentablePlatformValues();

        if ($commentable === []) {
            return $summary;
        }

        $staleBefore = now()->subMinutes(max(0, (int) config('inbox.sync.min_interval_minutes', 55)));
        $windowStart = now()->subDays(max(1, (int) config('inbox.sync.window_days', 30)));

        Log::info('Inbox sync started', ['org_id' => $organization->id, 'force' => $force]);

        PostTarget::query()
            ->where('status', PostTargetStatus::Published)
            ->where('published_at', '>=', $windowStart)
            ->whereNotNull('platform_post_id')
            ->whereHas('post', fn (Builder $query) => $query->withoutGlobalScope(OrganizationScope::class)->where('org_id', $organization->id))
            ->whereHas('socialAccount', fn (Builder $query) => $query
                ->withoutGlobalScope(OrganizationScope::class)
                ->where('connection_status', ConnectionStatus::Connected)
                ->whereIn('platform', $commentable)
                ->whereHas('client', fn (Builder $client) => $client->whereNull('deleted_at')))
            ->when(! $force, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->whereNull('comments_synced_at')
                ->orWhere('comments_synced_at', '<', $staleBefore)))
            ->with(['socialAccount.organization'])
            ->orderByRaw('CASE WHEN comments_synced_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('comments_synced_at')
            ->orderBy('id')
            ->limit(max(1, (int) config('inbox.sync.max_targets_per_org', 200)))
            ->get()
            ->each(function (PostTarget $target) use (&$summary) {
                $summary['targets']++;

                try {
                    $result = ($this->ingest)($target);
                    $summary['conversations'] += $result['conversations'];
                    $summary['messages'] += $result['messages'];
                } catch (CommentSourceException $e) {
                    $summary['failed']++;
                    Log::warning('Inbox sync target failed', [
                        'post_target_id' => $target->id,
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    // Stamp whether it succeeded or failed so the sweep rotates through every target.
                    $target->forceFill(['comments_synced_at' => now()])->saveQuietly();
                }
            });

        Log::info('Inbox sync finished', ['org_id' => $organization->id, ...$summary]);

        return $summary;
    }
}
