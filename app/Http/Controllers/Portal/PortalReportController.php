<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Analytics\GenerateClientReportPdfAction;
use App\Enums\ClientReportStatus;
use App\Http\Controllers\Controller;
use App\Models\ClientReport;
use App\Support\ClientReports\ClientReportPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step M-3.3 — the client's monthly reports in the portal: published reports of the contact's own
 * client only (the `portal` middleware 404s another client's or an unpublished one), each with
 * its PDF. No billing data — the snapshot never carries any.
 */
class PortalReportController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('portal/reports/index', [
            'reports' => ClientReport::query()
                ->where('client_id', $request->user()->client_id)
                ->where('status', ClientReportStatus::Published)
                ->latest('period_start')
                ->get()
                ->map(fn (ClientReport $report) => ClientReportPresenter::row($report))
                ->values()
                ->all(),
        ]);
    }

    public function show(ClientReport $report): Response
    {
        $this->authorize('view', $report);

        return Inertia::render('portal/reports/show', [
            'report' => ClientReportPresenter::document($report),
        ]);
    }

    public function pdf(ClientReport $report, GenerateClientReportPdfAction $pdf): HttpResponse
    {
        $this->authorize('view', $report);

        return response($pdf($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.GenerateClientReportPdfAction::filename($report).'"',
        ]);
    }
}
