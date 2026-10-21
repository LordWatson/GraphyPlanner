<?php

namespace App\Contracts;

use App\Exceptions\Analytics\EngagementSourceException;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Analytics\Engagement\AccountMetricsReading;
use App\Support\Analytics\Engagement\PostMetricsReading;

/**
 * Step M-3.1 / I-10.1 (Phase 12) — where engagement numbers come from. Bound to
 * `UploadPostEngagementSource` (M-0.2: Upload-Post reports analytics for all nine networks);
 * `NullEngagementSource` is the safe no-op. Kept separate from `PublishAdapter` so a metrics-only
 * source (e.g. the Meta Graph fallback recorded in `publishing.md`) can be swapped in without
 * touching publishing.
 *
 * Both methods return null when there is nothing to report — the platform has no source for it
 * (`config/platforms.php` `account_metrics` / `post_metrics`), or the vendor has no reading yet —
 * and throw `EngagementSourceException` when the vendor call itself failed.
 */
interface EngagementSource
{
    /**
     * Stored as `post_target_metrics.source`, so a reading can be traced to where it came from.
     */
    public function key(): string;

    /**
     * @throws EngagementSourceException
     */
    public function postMetrics(PostTarget $target): ?PostMetricsReading;

    /**
     * @throws EngagementSourceException
     */
    public function accountMetrics(SocialAccount $account): ?AccountMetricsReading;
}
