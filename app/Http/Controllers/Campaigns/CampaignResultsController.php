<?php

namespace App\Http\Controllers\Campaigns;

use App\Actions\Campaigns\GenerateCampaignReportPdfAction;
use App\Actions\Campaigns\ShareCampaignReportAction;
use App\Actions\Campaigns\UpdateCampaignKpisAction;
use App\Actions\Campaigns\UpdateCampaignReportAction;
use App\Enums\CampaignReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\UpdateCampaignKpisRequest;
use App\Models\Campaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Step C-7.1 — KPI targets; Step C-7.3 — the wrap report (edit, share, PDF).
 */
class CampaignResultsController extends Controller
{
    public function updateKpis(UpdateCampaignKpisRequest $request, Campaign $campaign, UpdateCampaignKpisAction $action): RedirectResponse
    {
        $action($campaign, $request->targets(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Targets saved. We’ll track them for you.')]);

        return back();
    }

    public function updateReport(Request $request, Campaign $campaign, UpdateCampaignReportAction $action): RedirectResponse
    {
        $this->authorize('update', $campaign);

        /** @var array{summary?: string|null, highlights?: list<string>, learnings?: string|null, next_steps?: string|null} $data */
        $data = $request->validate([
            'summary' => ['nullable', 'string', 'max:3000'],
            'highlights' => ['nullable', 'array', 'max:10'],
            'highlights.*' => ['string', 'max:300'],
            'learnings' => ['nullable', 'string', 'max:3000'],
            'next_steps' => ['nullable', 'string', 'max:3000'],
        ]);

        $action($campaign, $data, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report saved.')]);

        return back();
    }

    public function shareReport(Request $request, Campaign $campaign, ShareCampaignReportAction $action): RedirectResponse
    {
        $this->authorize('update', $campaign);
        $report = $action($campaign, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shared. The client has it in their portal and inbox (version :version).', ['version' => $report->version])]);

        return back();
    }

    public function reportPdf(Request $request, Campaign $campaign, GenerateCampaignReportPdfAction $action): Response
    {
        $this->authorize('view', $campaign);
        $report = $campaign->report;
        abort_if($report === null, 404);

        return $this->pdf($campaign, $action($campaign, $report));
    }

    /**
     * The portal's copy: only once shared, only the reviewer's own client (portal middleware).
     */
    public function portalReportPdf(Campaign $campaign, GenerateCampaignReportPdfAction $action): Response
    {
        $report = $campaign->report;
        abort_unless($report !== null && $report->status === CampaignReportStatus::Shared, 404);

        return $this->pdf($campaign, $action($campaign, $report));
    }

    private function pdf(Campaign $campaign, string $bytes): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.Str::slug($campaign->name).'-wrap-report.pdf"',
        ]);
    }
}
