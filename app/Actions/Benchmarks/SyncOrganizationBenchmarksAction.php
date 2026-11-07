<?php

namespace App\Actions\Benchmarks;

use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Models\Organization;
use App\Models\TrackedProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Step GTM-4.1 — one organization's nightly benchmark sweep, run by `SyncOrganizationBenchmarksJob`.
 *
 * Three caps, all about somebody else's rate limits rather than ours:
 *
 *  - a profile already read **today** is skipped, so a re-run or a manual retry costs nothing;
 *  - a run stops after `benchmarks.max_profiles_per_org`, oldest-read first, so one large roster
 *    cannot spend the whole Graph budget and starve the rest (the remainder go next night);
 *  - a suspended org, or one whose plan has lost `Feature::CompetitorBenchmarks`, is skipped
 *    entirely — a downgrade stops the reads, it doesn't just hide the panel.
 */
class SyncOrganizationBenchmarksAction
{
    public function __construct(
        private readonly SyncTrackedProfileAction $sync,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * @return array{stored: int, gap: int, skipped: string|null}
     */
    public function __invoke(Organization $organization, bool $force = false): array
    {
        $summary = ['stored' => 0, 'gap' => 0, 'skipped' => null];

        if ($organization->isSuspended()) {
            $summary['skipped'] = 'suspended';
        } elseif (! $this->entitlements->allows($organization, Feature::CompetitorBenchmarks)) {
            $summary['skipped'] = 'not_entitled';
        }

        if ($summary['skipped'] !== null) {
            Log::info('Benchmark sync skipped', ['org_id' => $organization->id, 'reason' => $summary['skipped']]);

            return $summary;
        }

        $profiles = TrackedProfile::query()
            ->withoutOrgScope()
            ->where('org_id', $organization->id)
            ->whereHas('client', fn (Builder $query) => $query->whereNull('deleted_at'))
            ->when(! $force, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<', now()->startOfDay())))
            ->orderByRaw('last_synced_at is null desc')
            ->orderBy('last_synced_at')
            ->limit(max(1, (int) config('benchmarks.max_profiles_per_org', 50)))
            ->get();

        foreach ($profiles as $profile) {
            $summary[($this->sync)($profile)]++;
        }

        Log::info('Benchmark sync finished', ['org_id' => $organization->id, ...$summary]);

        return $summary;
    }
}
