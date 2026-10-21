<?php

namespace App\Actions\Analytics;

use App\Enums\ClientReportStatus;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step M-3.3 — get one client's report for a month ready: creates the draft if there isn't one,
 * and (re)builds its figures while it's a draft. A published report is returned untouched — its
 * figures are frozen. Used by the team ("Prepare report", "Refresh figures") and the monthly
 * scheduler. The month is the org-timezone calendar month containing `$month`.
 */
class PrepareClientReportAction
{
    public function __construct(private readonly BuildClientReportSnapshotAction $build) {}

    public function __invoke(Client $client, CarbonImmutable $month): ClientReport
    {
        $start = $month->startOfMonth();

        return DB::transaction(function () use ($client, $start) {
            /** @var ClientReport|null $report */
            $report = ClientReport::query()
                ->withoutGlobalScope(OrganizationScope::class)
                ->where('client_id', $client->id)
                ->whereDate('period_start', $start->toDateString())
                ->lockForUpdate()
                ->first();

            if ($report?->isPublished()) {
                return $report;
            }

            $snapshot = ($this->build)($client, $start);
            $created = $report === null;

            $report ??= new ClientReport([
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'period_start' => $start->toDateString(),
                'period_end' => $start->endOfMonth()->toDateString(),
                'status' => ClientReportStatus::Draft,
            ]);

            $report->fill(['snapshot' => $snapshot, 'snapshot_at' => now()])->save();

            Log::info($created ? 'Client report prepared' : 'Client report figures refreshed', [
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'client_report_id' => $report->id,
                'period' => $start->toDateString(),
            ]);

            return $report;
        });
    }
}
