<?php

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\GenerateOrganizationReportPdfAction;
use App\Actions\Analytics\GetAnalyticsFilterOptionsAction;
use App\Actions\Analytics\GetOrganizationAnalyticsSummaryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\OrganizationAnalyticsFilterRequest;
use App\Http\Responses\CsvDownload;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationReport;
use App\Models\User;
use App\Support\Analytics\AnalyticsAccess;
use App\Support\Analytics\OrganizationAnalyticsCsv;
use App\Support\Analytics\OrganizationAnalyticsFilters;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 17 — chapter 06 Analytics: the organization's own analytics page (17.4), the client
 * drill-down (17.5) and exports of the current view (17.7). Every summary is built for the
 * requesting user, so sections their role can't see are absent from the page and every export.
 */
class AnalyticsController extends Controller
{
    public function index(OrganizationAnalyticsFilterRequest $request, GetOrganizationAnalyticsSummaryAction $summary, GetAnalyticsFilterOptionsAction $options): Response
    {
        $user = $request->user();
        $organization = $this->organization($request);
        $filters = $request->filters();

        return Inertia::render('analytics/index', [
            'summary' => $summary($organization, $filters, $user),
            'filters' => $filters->toArray(),
            'filterOptions' => $options($organization),
            'can' => $this->abilities($user, $organization),
        ]);
    }

    /**
     * Step 17.5 — one client's analytics: the same summary scoped to that client.
     */
    public function client(OrganizationAnalyticsFilterRequest $request, Client $client, GetOrganizationAnalyticsSummaryAction $summary, GetAnalyticsFilterOptionsAction $options): Response
    {
        $this->authorize('view', $client);

        $user = $request->user();
        $organization = $this->organization($request);
        $filters = $request->filters()->forClient($client->id);
        $filterOptions = $options($organization);

        return Inertia::render('analytics/client', [
            'client' => ['id' => $client->id, 'name' => $client->name, 'status' => $client->status->value],
            'summary' => $summary($organization, $filters, $user),
            'filters' => $filters->toArray(),
            'filterOptions' => [
                ...$filterOptions,
                'campaigns' => array_values(array_filter($filterOptions['campaigns'], fn (array $campaign) => $campaign['client_id'] === $client->id)),
            ],
            'can' => $this->abilities($user, $organization),
        ]);
    }

    /**
     * Step 17.7 — the current view as CSV.
     */
    public function export(OrganizationAnalyticsFilterRequest $request, GetOrganizationAnalyticsSummaryAction $summary): StreamedResponse
    {
        $organization = $this->organization($request);
        $filters = $request->filters();
        $result = $summary($organization, $filters, $request->user());

        return CsvDownload::make(OrganizationAnalyticsCsv::rows($result), $this->filename($organization, $filters, $result, 'csv'));
    }

    /**
     * Step 17.7 — the current view as the branded PDF.
     */
    public function pdf(OrganizationAnalyticsFilterRequest $request, GetOrganizationAnalyticsSummaryAction $summary, GenerateOrganizationReportPdfAction $pdf): HttpResponse
    {
        $organization = $this->organization($request);
        $filters = $request->filters();
        $result = $summary($organization, $filters, $request->user());
        $title = isset($result['client']['name']) ? "{$result['client']['name']} analytics" : 'Studio analytics';

        return $this->pdfResponse($pdf($title, $result), $this->filename($organization, $filters, $result, 'pdf'));
    }

    private function organization(OrganizationAnalyticsFilterRequest $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->organization();

        return $organization;
    }

    /**
     * @return array{viewRevenue: bool, viewAiCost: bool, saveReport: bool, export: bool}
     */
    private function abilities(User $user, Organization $organization): array
    {
        return [
            'viewRevenue' => AnalyticsAccess::canViewRevenue($user, $organization),
            'viewAiCost' => AnalyticsAccess::canViewAiCost($user, $organization),
            'saveReport' => $user->can('create', OrganizationReport::class),
            'export' => true,
        ];
    }

    /**
     * `Str::slug(name)-Y-m-d` in the org's clock — the client's name when drilled down.
     *
     * @param  array<string, mixed>  $summary
     */
    private function filename(Organization $organization, OrganizationAnalyticsFilters $filters, array $summary, string $extension): string
    {
        $name = Str::slug(($summary['client']['name'] ?? $organization->name).' analytics') ?: 'analytics';

        return $name.'-'.now()->setTimezone($filters->range->timezone)->format('Y-m-d').'.'.$extension;
    }

    private function pdfResponse(string $bytes, string $filename): HttpResponse
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
