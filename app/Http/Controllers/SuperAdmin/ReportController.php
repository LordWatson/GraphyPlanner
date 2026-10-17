<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Actions\SuperAdmin\CreateReportAction;
use App\Actions\SuperAdmin\DeleteReportAction;
use App\Actions\SuperAdmin\RunReportAction;
use App\Actions\SuperAdmin\UpdateReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\SaveReportRequest;
use App\Models\Organization;
use App\Models\SuperAdminReport;
use App\Support\SuperAdmin\AnalyticsFilters;
use App\Support\SuperAdmin\PlatformAnalyticsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Step 15.5 — saved super admin reports: full CRUD, a "run" view (`show`) that re-runs the saved
 * filters through `GetPlatformAnalyticsSummaryAction`, and a CSV export of a run.
 */
class ReportController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', SuperAdminReport::class);

        $organizationNames = Organization::query()->pluck('name', 'id');

        return Inertia::render('super-admin/reports/index', [
            'reports' => SuperAdminReport::query()
                ->with('creator:id,name')
                ->latest('updated_at')
                ->get()
                ->map(fn (SuperAdminReport $report) => [
                    ...$this->transform($report),
                    'organization_names' => collect($report->filters['org_ids'] ?? [])
                        ->map(fn (int $id) => $organizationNames[$id] ?? null)
                        ->filter()
                        ->values()
                        ->all(),
                ])
                ->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', SuperAdminReport::class);

        // "Save as report" from the analytics page pre-fills the builder with its current filters.
        $filters = AnalyticsFilters::fromArray($request->only(['range', 'from', 'to', 'org_ids', 'metrics']));

        return Inertia::render('super-admin/reports/edit', [
            'report' => null,
            'defaults' => ['name' => '', 'description' => '', 'filters' => $filters->toArray()],
            'organizationOptions' => $this->organizationOptions(),
        ]);
    }

    public function store(SaveReportRequest $request, CreateReportAction $create): RedirectResponse
    {
        $report = $create($request->report(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report saved.')]);

        return to_route('super-admin.reports.show', $report);
    }

    /**
     * The "run" view: re-runs the saved filters against today's data.
     */
    public function show(SuperAdminReport $report, RunReportAction $run): Response
    {
        $this->authorize('view', $report);

        return Inertia::render('super-admin/reports/show', [
            'report' => $this->transform($report->load('creator:id,name')),
            'summary' => $run($report),
        ]);
    }

    public function edit(SuperAdminReport $report): Response
    {
        $this->authorize('update', $report);

        return Inertia::render('super-admin/reports/edit', [
            'report' => $this->transform($report->load('creator:id,name')),
            'defaults' => [
                'name' => $report->name,
                'description' => $report->description ?? '',
                'filters' => AnalyticsFilters::fromArray($report->filters)->toArray(),
            ],
            'organizationOptions' => $this->organizationOptions(),
        ]);
    }

    public function update(SaveReportRequest $request, SuperAdminReport $report, UpdateReportAction $update): RedirectResponse
    {
        $update($report, $request->report(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report updated.')]);

        return to_route('super-admin.reports.show', $report);
    }

    public function destroy(Request $request, SuperAdminReport $report, DeleteReportAction $delete): RedirectResponse
    {
        $this->authorize('delete', $report);

        $delete($report, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report deleted.')]);

        return to_route('super-admin.reports.index');
    }

    public function export(SuperAdminReport $report, RunReportAction $run): StreamedResponse
    {
        $this->authorize('view', $report);

        $rows = PlatformAnalyticsCsv::rows($run($report));
        $filename = Str::slug($report->name ?: 'report').'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(SuperAdminReport $report): array
    {
        $filters = AnalyticsFilters::fromArray($report->filters);

        return [
            'id' => $report->id,
            'name' => $report->name,
            'description' => $report->description,
            'filters' => $filters->toArray(),
            'creator' => $report->creator?->name,
            'last_run_at' => $report->last_run_at?->toIso8601String(),
            'updated_at' => $report->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function organizationOptions(): array
    {
        return array_values(Organization::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Organization $organization) => ['value' => $organization->id, 'label' => $organization->name])
            ->all());
    }
}
