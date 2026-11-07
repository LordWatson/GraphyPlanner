<?php

namespace App\Services\Analytics\Benchmarks;

use App\Contracts\CompetitorProfileSource;
use App\Enums\Platform;
use App\Models\TrackedProfile;
use App\Support\Analytics\Benchmarks\CompetitorProfileReading;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step GTM-4.1 — the bound `CompetitorProfileSource`: Meta's Business Discovery for Instagram,
 * nothing for every other platform (there is no legitimate public read, and scraping is not on the
 * table — see `benchmarks.md`).
 *
 * This is also the last line of the never-throw rule: an implementation that throws anyway is
 * logged and turned into a gap, so a vendor outage can never take out the nightly sweep or the
 * analytics page.
 */
class CompositeCompetitorProfileSource implements CompetitorProfileSource
{
    public function __construct(private readonly MetaBusinessDiscoveryCompetitorSource $meta) {}

    public function key(): string
    {
        return 'composite';
    }

    public function profile(TrackedProfile $profile): ?CompetitorProfileReading
    {
        if ($profile->platform !== Platform::Instagram) {
            return null;
        }

        try {
            return $this->meta->profile($profile);
        } catch (Throwable $e) {
            Log::warning('Competitor profile read failed', [
                'tracked_profile_id' => $profile->id,
                'org_id' => $profile->org_id,
                'platform' => $profile->platform->value,
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * Whether a platform can be benchmarked at all, so the UI can say so before somebody adds a
     * handle we will never have a figure for.
     */
    public static function supports(Platform $platform): bool
    {
        return $platform === Platform::Instagram;
    }
}
