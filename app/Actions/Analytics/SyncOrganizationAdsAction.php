<?php

namespace App\Actions\Analytics;

use App\Contracts\Entitlements;
use App\Enums\ConnectionStatus;
use App\Enums\Feature;
use App\Models\AdAccount;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Step GTM-5.1 — one organization's nightly ad-spend sweep. A suspended org, or one whose plan has
 * lost `Feature::AdsReporting`, is skipped entirely: a downgrade stops the reads, not just the
 * panel.
 */
class SyncOrganizationAdsAction
{
    public function __construct(
        private readonly SyncAdAccountMetricsAction $sync,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * @return array{stored: int, empty: int, failed: int, skipped: string|null}
     */
    public function __invoke(Organization $organization): array
    {
        $summary = ['stored' => 0, 'empty' => 0, 'failed' => 0, 'skipped' => null];

        if ($organization->isSuspended()) {
            $summary['skipped'] = 'suspended';
        } elseif (! $this->entitlements->allows($organization, Feature::AdsReporting)) {
            $summary['skipped'] = 'not_entitled';
        }

        if ($summary['skipped'] !== null) {
            Log::info('Ads sync skipped', ['org_id' => $organization->id, 'reason' => $summary['skipped']]);

            return $summary;
        }

        AdAccount::query()
            ->withoutOrgScope()
            ->where('org_id', $organization->id)
            ->where('connection_status', ConnectionStatus::Connected)
            ->whereHas('client', fn (Builder $query) => $query->whereNull('deleted_at'))
            ->orderBy('id')
            ->each(function (AdAccount $account) use (&$summary) {
                $summary[($this->sync)($account)]++;
            });

        Log::info('Ads sync finished', ['org_id' => $organization->id, ...$summary]);

        return $summary;
    }
}
