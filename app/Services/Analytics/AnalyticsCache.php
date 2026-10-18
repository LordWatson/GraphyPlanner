<?php

namespace App\Services\Analytics;

use App\Models\AiUsageLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Post;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Step I-2.7 — caches an organization's analytics summaries for five minutes.
 *
 * Invalidation is a per-org version number inside every key: bumping it orphans all of that
 * org's cached summaries at once. That works on every cache store (the database/file stores have
 * no tags), and stale entries simply age out. The version is bumped by the writes the summaries
 * read — post creates/status changes/deletes (every real transition saves the post's status, so
 * its activity log entry is covered too), invoices, clients, AI usage —
 * via `registerInvalidation()` (called from `AppServiceProvider`), plus
 * `RecomputeClientHealthAction` when stored health changes.
 */
class AnalyticsCache
{
    public const TTL_SECONDS = 300;

    /**
     * @template T
     *
     * @param  array<string, mixed>  $parts  everything the cached value depends on (filters, viewer…)
     * @param  Closure(): T  $build
     * @return T
     */
    public function remember(int $orgId, string $name, array $parts, Closure $build): mixed
    {
        $key = sprintf('analytics:org:%d:v%d:%s:%s', $orgId, self::version($orgId), $name, md5((string) json_encode($parts)));

        return Cache::remember($key, self::TTL_SECONDS, $build);
    }

    public static function bust(?int $orgId): void
    {
        if ($orgId === null) {
            return;
        }

        Cache::forever(self::versionKey($orgId), self::version($orgId) + 1);
    }

    public static function registerInvalidation(): void
    {
        Post::saved(function (Post $post) {
            if ($post->wasRecentlyCreated || $post->wasChanged(['status', 'client_id', 'campaign_id'])) {
                self::bust($post->org_id);
            }
        });
        Post::deleted(fn (Post $post) => self::bust($post->org_id));
        Post::restored(fn (Post $post) => self::bust($post->org_id));

        foreach ([Invoice::class, Client::class] as $model) {
            $model::saved(fn ($record) => self::bust($record->org_id));
            $model::deleted(fn ($record) => self::bust($record->org_id));
        }

        AiUsageLog::created(fn (AiUsageLog $log) => self::bust($log->org_id));
    }

    private static function version(int $orgId): int
    {
        return (int) Cache::get(self::versionKey($orgId), 0);
    }

    private static function versionKey(int $orgId): string
    {
        return "analytics:org:{$orgId}:version";
    }
}
