<?php

namespace App\Actions\Campaigns;

use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\CampaignReport;
use App\Support\Campaigns\CampaignClock;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Step C-7.3 — the branded wrap report PDF (`PdfBrand`, the Phase 17 pipeline's look). Built
 * from the report's frozen KPI snapshot when shared — never live numbers, never fees or invoices.
 */
class GenerateCampaignReportPdfAction
{
    public function __invoke(Campaign $campaign, CampaignReport $report): string
    {
        File::ensureDirectoryExists(storage_path('fonts'));
        $started = microtime(true);
        $timezone = CampaignClock::timezone($campaign->organization);

        $pdf = Pdf::loadView('pdf.campaign-report', [
            'campaign' => $campaign,
            'client' => $campaign->client,
            'organization' => $campaign->organization,
            'report' => $report,
            'kpis' => $report->kpi_snapshot ?? [],
            'engagement' => $report->engagement_snapshot,
            'published' => $campaign->posts()->whereIn('status', [PostStatus::Published, PostStatus::PartiallyPublished])->count(),
            'generatedAt' => now()->setTimezone($timezone),
        ])->setPaper('a4')->output();

        Log::info('Campaign wrap report PDF generated', [
            'campaign_id' => $campaign->id,
            'report_id' => $report->id,
            'version' => $report->version,
            'bytes' => strlen($pdf),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $pdf;
    }
}
