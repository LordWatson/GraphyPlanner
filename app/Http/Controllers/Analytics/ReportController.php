<?php

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\CreateOrganizationReportAction;
use App\Actions\Analytics\DeleteOrganizationReportAction;
use App\Actions\Analytics\GenerateOrganizationReportPdfAction;
use App\Actions\Analytics\GetAnalyticsFilterOptionsAction;
use App\Actions\Analytics\RunOrganizationReportAction;
use App\Actions\Analytics\UpdateOrganizationReportAction;
use App\Enums\ReportSchedule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\SaveOrganizationReportRequest;
use App\Http\Responses\CsvDownload;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationReport;
use App\Models\User;
use App\Support\Analytics\AnalyticsAccess;
use App\Support\Analytics\OrganizationAnalyticsCsv;
use App\Support\Analytics\OrganizationAnalyticsFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Step 17.6 — saved organization reports: CRUD, a "run" view (`show`) that re-runs the saved
 * filters for the current viewer, and (Step 17.7) CSV and PDF exports of a run. Every report is
 * visible to the whole organization; permissions are applied when it runs.
 */
class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', OrganizationReport::class);

        $user = $request->user();
        $clientNames = Client::query()->where('org_id', $user->org_id)->pluck('name', 'id');

        return Inertia::render('analytics/reports/index', [
            'reports' => OrganizationReport::query()
                ->where('org_id', $user->org_id)
                ->with(['creator:id,name', 'organization'])
                ->latest('updated_at')
                ->get()
                ->map(fn (OrganizationReport $report) => [
                    ...$this->transform($report, $user),
                    'client_names' => array_values(array_filter(array_map(
                        fn ($id) => $clientNames[(int) $id] ?? null,
                        (array) ($report->filters['client_ids'] ?? []),
                    ))),
                ])
                ->all(),
            'can' => ['create' => $user->can('create', OrganizationReport::class)],
        ]);
    }

    public function create(Request $request, GetAnalyticsFilterOptionsAction $options): Response
    {
        $this->authorize('create', OrganizationReport::class);

        $organization = $this->organization($request);

        // "Save as report" from the analytics page pre-fills the builder with its current filters.
        $filters = OrganizationAnalyticsFilters::fromArray(
            $request->only(['range', 'from', 'to', 'client_ids', 'campaign_ids', 'platforms', 'metrics']),
            $organization,
        );

        return Inertia::render('analytics/reports/edit', [
            'report' => null,
            'defaults' => [
                'name' => '',
                'description' => '',
                'filters' => $filters->toArray(),
                'schedule' => null,
                'recipient_ids' => [],
            ],
            ...$this->builderOptions($organization, $options),
        ]);
    }

    public function store(SaveOrganizationReportRequest $request, CreateOrganizationReportAction $create): RedirectResponse
    {
        $report = $create($this->organization($request), $request->report(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report saved.')]);

        return to_route('analytics.reports.show', $report);
    }

    /**
     * The "run" view: re-runs the saved filters against today's data, for the current viewer.
     */
    public function show(Request $request, OrganizationReport $report, RunOrganizationReportAction $run): Response
    {
        $this->authorize('view', $report);

        $user = $request->user();
        $summary = $run($report, $user);

        return Inertia::render('analytics/reports/show', [
            'report' => $this->transform($report->load('creator:id,name'), $user),
            'summary' => $summary,
            'can' => [
                'viewRevenue' => AnalyticsAccess::canViewRevenue($user, $report->organization),
                'viewAiCost' => AnalyticsAccess::canViewAiCost($user, $report->organization),
                'saveReport' => $user->can('create', OrganizationReport::class),
                'export' => true,
            ],
        ]);
    }

    public function edit(Request $request, OrganizationReport $report, RunOrganizationReportAction $run, GetAnalyticsFilterOptionsAction $options): Response
    {
        $this->authorize('update', $report);

        return Inertia::render('analytics/reports/edit', [
            'report' => $this->transform($report->load('creator:id,name'), $request->user()),
            'defaults' => [
                'name' => $report->name,
                'description' => $report->description ?? '',
                'filters' => $run->filters($report)->toArray(),
                'schedule' => $report->schedule?->value,
                'recipient_ids' => $report->recipientIds(),
            ],
            ...$this->builderOptions($report->organization, $options),
        ]);
    }

    public function update(SaveOrganizationReportRequest $request, OrganizationReport $report, UpdateOrganizationReportAction $update): RedirectResponse
    {
        $update($report, $request->report(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report updated.')]);

        return to_route('analytics.reports.show', $report);
    }

    public function destroy(Request $request, OrganizationReport $report, DeleteOrganizationReportAction $delete): RedirectResponse
    {
        $this->authorize('delete', $report);

        $delete($report, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report deleted.')]);

        return to_route('analytics.reports.index');
    }

    /**
     * Step 17.7 — a run of the report as CSV (only the sections the requester can see).
     */
    public function export(Request $request, OrganizationReport $report, RunOrganizationReportAction $run): StreamedResponse
    {
        $this->authorize('view', $report);

        return CsvDownload::make(OrganizationAnalyticsCsv::rows($run($report, $request->user())), $this->filename($report, 'csv'));
    }

    /**
     * Step 17.7 — a run of the report as the branded PDF.
     */
    public function pdf(Request $request, OrganizationReport $report, RunOrganizationReportAction $run, GenerateOrganizationReportPdfAction $pdf): HttpResponse
    {
        $this->authorize('view', $report);

        $bytes = $pdf($report->name, $run($report, $request->user()), $report->description);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($report, 'pdf').'"',
        ]);
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->user()->organization;

        return $organization;
    }

    /**
     * @return array{filterOptions: array<string, mixed>, recipientOptions: list<array{value: int, label: string, role: string}>, scheduleOptions: list<array{value: string, label: string}>}
     */
    private function builderOptions(Organization $organization, GetAnalyticsFilterOptionsAction $options): array
    {
        return [
            'filterOptions' => $options($organization),
            'recipientOptions' => $options->recipients($organization),
            'scheduleOptions' => array_map(
                fn (ReportSchedule $schedule) => ['value' => $schedule->value, 'label' => $schedule->label()],
                ReportSchedule::cases(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(OrganizationReport $report, User $user): array
    {
        return [
            'id' => $report->id,
            'name' => $report->name,
            'description' => $report->description,
            'filters' => OrganizationAnalyticsFilters::fromArray($report->filters, $report->organization)->toArray(),
            'creator' => $report->creator?->name,
            'schedule' => $report->schedule?->value,
            'schedule_label' => $report->schedule?->label(),
            'recipient_count' => count($report->recipientIds()),
            'last_run_at' => $report->last_run_at?->toIso8601String(),
            'last_sent_at' => $report->last_sent_at?->toIso8601String(),
            'updated_at' => $report->updated_at?->toIso8601String(),
            'can' => [
                'update' => $user->can('update', $report),
                'delete' => $user->can('delete', $report),
            ],
        ];
    }

    private function filename(OrganizationReport $report, string $extension): string
    {
        $timezone = $report->organization->default_timezone ?: 'UTC';

        return (Str::slug($report->name) ?: 'report').'-'.now()->setTimezone($timezone)->format('Y-m-d').'.'.$extension;
    }
}
