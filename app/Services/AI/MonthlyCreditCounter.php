<?php

namespace App\Services\AI;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-2.3 / B-9.2 — claims AI credits *before* they are spent, so concurrent requests cannot
 * all pass the same allowance check.
 *
 * The guards used to count what had already been recorded and compare that to the plan's limit.
 * Because a call is only recorded *after* the vendor returns, every request that started inside the
 * same moment read the same low number and every one of them was let through: an organization with
 * two image credits left could click "fill grid with AI", fan out nine jobs, and have all nine read
 * "48 of 50". Nine images were paid for and two were billed.
 *
 * This replaces the read with an atomic claim. `Cache::add()` seeds the month's counter only if it
 * is absent (so two processes racing to seed it cannot both win), and `Cache::increment()` is a
 * single atomic operation on Redis — twenty callers get twenty different numbers, and the ones past
 * the limit hand their claim straight back.
 *
 * The month's recorded usage stays the source of truth for billing and for the usage pages: the
 * counter is only ever a reservation ledger, seeded from the records whenever it is missing, so a
 * flushed cache self-corrects rather than granting a free month.
 *
 * Known limit — `shortcut: a process killed between claiming and recording leaves the counter one
 * ahead of the records, costing that organization one credit until the key expires or the cache is
 * flushed. Fail-closed is the right direction for a paid allowance, and the alternative (a
 * durable two-phase reservation) is a lot of machinery for a rare crash. Upgrade it if anyone
 * ever reports a credit they did not use.`
 */
class MonthlyCreditCounter
{
    /**
     * Try to claim `$amount` units against `$limit`.
     *
     * A null limit is unlimited and always allowed. Returns false when the claim would exceed the
     * limit, having already given it back.
     *
     * @param  Closure(): int  $recorded  the authoritative count for this month, used to seed the
     *                                    counter when the cache has no value for it yet
     */
    public function claim(string $bucket, int $orgId, int $amount, ?int $limit, Closure $recorded, ?CarbonImmutable $now = null): bool
    {
        if ($limit === null || $amount < 1) {
            return true;
        }

        $key = self::key($bucket, $orgId, $now);

        // Atomic: only the first caller of the month seeds it, from what has actually been recorded.
        Cache::add($key, $recorded(), self::ttlSeconds($now));

        $used = (int) Cache::increment($key, $amount);

        if ($used <= $limit) {
            return true;
        }

        Cache::decrement($key, $amount);

        Log::warning('AI credit claim refused', [
            'org_id' => $orgId,
            'bucket' => $bucket,
            'limit' => $limit,
            'requested' => $amount,
            'would_be' => $used,
        ]);

        return false;
    }

    /**
     * Hand back `$amount` units, because what they were claimed for did not happen — a failed image
     * render, which is never billed. Floored at zero, so a double release cannot create credits.
     */
    public function release(string $bucket, int $orgId, int $amount = 1, ?CarbonImmutable $now = null): void
    {
        $key = self::key($bucket, $orgId, $now);

        if (Cache::get($key) === null) {
            // Nothing claimed in this process's lifetime; the next claim reseeds from the records,
            // which already exclude whatever failed.
            return;
        }

        if ((int) Cache::decrement($key, $amount) < 0) {
            Cache::put($key, 0, self::ttlSeconds($now));
        }
    }

    /**
     * Forget this month's counter, so the next claim reseeds from the records. For tests and for a
     * super admin who has just changed an allowance.
     */
    public function forget(string $bucket, int $orgId, ?CarbonImmutable $now = null): void
    {
        Cache::forget(self::key($bucket, $orgId, $now));
    }

    /**
     * Month-scoped, so a new month always starts from a fresh key even if the old one outlived its
     * TTL (`Cache::increment` on an expired key recreates it without one).
     */
    public static function key(string $bucket, int $orgId, ?CarbonImmutable $now = null): string
    {
        return sprintf('ai-credits:%s:%d:%s', $bucket, $orgId, ($now ?? CarbonImmutable::now())->format('Y-m'));
    }

    /**
     * To the end of next month: comfortably past the point the key stops being read, without
     * relying on the TTL for correctness.
     */
    private static function ttlSeconds(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        return max(60, (int) $now->diffInSeconds($now->startOfMonth()->addMonths(2)));
    }
}
