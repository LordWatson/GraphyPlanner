<?php

namespace App\Services\Analytics\Engagement;

use App\Contracts\EngagementSource;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Analytics\Engagement\AccountMetricsReading;
use App\Support\Analytics\Engagement\PostMetricsReading;

/**
 * Step M-3.1 — the safe no-op `EngagementSource`: never calls anyone, never reports anything, so a
 * sync with it bound stores nothing and every screen shows "not available yet".
 */
class NullEngagementSource implements EngagementSource
{
    public function key(): string
    {
        return 'null';
    }

    public function postMetrics(PostTarget $target): ?PostMetricsReading
    {
        return null;
    }

    public function accountMetrics(SocialAccount $account): ?AccountMetricsReading
    {
        return null;
    }
}
