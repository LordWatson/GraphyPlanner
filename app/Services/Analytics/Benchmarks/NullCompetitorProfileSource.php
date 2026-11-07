<?php

namespace App\Services\Analytics\Benchmarks;

use App\Contracts\CompetitorProfileSource;
use App\Models\TrackedProfile;
use App\Support\Analytics\Benchmarks\CompetitorProfileReading;

/**
 * Step GTM-4.1 — the honest no-op: for a platform with no legitimate way to read a profile we
 * don't own, there is no reading. Used in tests, and in production for every platform except
 * Instagram.
 */
class NullCompetitorProfileSource implements CompetitorProfileSource
{
    public function key(): string
    {
        return 'null';
    }

    public function profile(TrackedProfile $profile): ?CompetitorProfileReading
    {
        return null;
    }
}
