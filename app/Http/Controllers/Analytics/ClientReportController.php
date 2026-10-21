<?php

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\GenerateClientReportPdfAction;
use App\Actions\Analytics\PrepareClientReportAction;
use App\Actions\Analytics\PublishClientReportAction;
use App\Actions\Analytics\UpdateClientReportAction;
use App\Actions\Analytics\UpdateClientReportScheduleAction;
use App\Enums\ClientReportSchedule;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientReports\PrepareClientReportRequest;
use App\Http\Requests\ClientReports\UpdateClientReportRequest;
use App\Http\Requests\ClientReports\UpdateClientReportScheduleRequest;
use App\Models\Client;
use App\Models\ClientReport;
use App\Support\ClientReports\ClientReportPresenter;
use App\Support\ClientReports\ClientReportRecipients;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step M-3.3 / I-10.3 — the studio side of monthly client reports: the client's report list and
 * schedule, one report's page (the document as the client will see it, plus the commentary
 * editor), prepare / refresh / publish, and the PDF. Business logic lives in the Actions.
 */
class ClientReportController extends Controller
{
    public function index(Request $request, Client $client): Response
    {
        $this->authorize('viewAny', [ClientReport::class, $client]);

        $user = $request->user();
        $reports = $client->reports()->get();
        $timezone = $client->organization->default_timezone;
        $taken = $reports->map(fn (ClientReport $report) => $report->period_start->format('Y-m'))->all();
        $thisMonth = CarbonImmutable::now(in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC')->startOfMonth();

        return Inertia::render('client-reports/index', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'report_schedule' => $client->report_schedule->value,
            ],
            'reports' => $reports->map(fn (ClientReport $report) => ClientReportPresenter::row($report))->values()->all(),
            // The last twelve months without a report yet, newest first.
            'months' => collect(range(0, 11))
                ->map(fn (int $back) => $thisMonth->subMonthsNoOverflow($back))
                ->reject(fn (CarbonImmutable $month) => in_array($month->format('Y-m'), $taken, true))
                ->map(fn (CarbonImmutable $month) => ['value' => $month->format('Y-m'), 'label' => $month->format('F Y')])
                ->values()
                ->all(),
            // The month just ended is the usual one to prepare by hand.
            'suggestedMonth' => $thisMonth->subMonthNoOverflow()->format('Y-m'),
            'schedules' => array_map(fn (ClientReportSchedule $schedule) => ['value' => $schedule->value, 'label' => $schedule->label()], ClientReportSchedule::cases()),
            'recipients' => ClientReportRecipients::for($client),
            'can' => [
                'prepare' => $user->can('create', [ClientReport::class, $client]),
                'manageSchedule' => $user->can('manageSchedule', [ClientReport::class, $client]),
            ],
        ]);
    }

    public function store(PrepareClientReportRequest $request, Client $client, PrepareClientReportAction $prepare): RedirectResponse
    {
        $report = $prepare($client, $request->month());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':month report ready.', ['month' => $report->periodLabel()])]);

        return to_route('client-reports.show', $report);
    }

    public function show(Request $request, ClientReport $report): Response
    {
        // Staff only: client contacts read published reports in the portal.
        $this->authorize('viewAny', [ClientReport::class, $report->client]);

        $user = $request->user();
        $client = $report->client;

        return Inertia::render('client-reports/show', [
            'client' => ['id' => $client->id, 'name' => $client->name],
            'report' => ClientReportPresenter::document($report),
            'recipients' => ClientReportRecipients::for($client),
            'can' => [
                'update' => $user->can('update', $report),
                'publish' => $user->can('publish', $report),
            ],
        ]);
    }

    public function update(UpdateClientReportRequest $request, ClientReport $report, UpdateClientReportAction $update): RedirectResponse
    {
        $update($report, $request->validated('commentary'), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Commentary saved.')]);

        return back();
    }

    public function refresh(ClientReport $report, PrepareClientReportAction $prepare): RedirectResponse
    {
        $this->authorize('update', $report);

        $prepare($report->client, CarbonImmutable::parse($report->period_start->toDateString()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Figures refreshed.')]);

        return back();
    }

    public function publish(Request $request, ClientReport $report, PublishClientReportAction $publish): RedirectResponse
    {
        $this->authorize('publish', $report);

        $publish($report, $request->user());
        $count = count(ClientReportRecipients::for($report->client));

        Inertia::flash('toast', ['type' => 'success', 'message' => $count > 0
            ? trans_choice('Published and emailed to :count contact.|Published and emailed to :count contacts.', $count, ['count' => $count])
            : __('Published to the portal. Nobody was emailed: this client has no contacts yet.')]);

        return back();
    }

    public function pdf(ClientReport $report, GenerateClientReportPdfAction $pdf): HttpResponse
    {
        $this->authorize('viewAny', [ClientReport::class, $report->client]);

        return response($pdf($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.GenerateClientReportPdfAction::filename($report).'"',
        ]);
    }

    public function updateSchedule(UpdateClientReportScheduleRequest $request, Client $client, UpdateClientReportScheduleAction $update): RedirectResponse
    {
        $update($client, ClientReportSchedule::from((string) $request->validated('report_schedule')), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report schedule saved.')]);

        return back();
    }
}
