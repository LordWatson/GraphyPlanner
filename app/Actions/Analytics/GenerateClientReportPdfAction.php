<?php

namespace App\Actions\Analytics;

use App\Models\ClientReport;
use App\Services\Branding\BrandingResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step M-3.3 — the branded monthly report PDF (`PdfBrand`, the analytics report's "Studio Paper"
 * look), drawn only from the report's snapshot and commentary — never live numbers, never billing.
 * Nothing is stored.
 *
 * Step GTM-3.1: on a plan with `Feature::WhiteLabel` the band, the accent word and the footer carry
 * the agency's logo, colour and footer line, and the "Prepared with Alima" credit comes off. The
 * paper, the type and the layout stay ours either way — this is a white label, not a theme.
 */
class GenerateClientReportPdfAction
{
    public function __construct(private readonly BrandingResolver $branding) {}

    public function __invoke(ClientReport $report): string
    {
        File::ensureDirectoryExists(storage_path('fonts'));
        $started = microtime(true);

        // Step GTM-3.1: the agency's branding when its plan includes it, Alima's otherwise. The
        // logo has to be bytes — dompdf cannot fetch a URL.
        $organization = $report->organization;

        $pdf = Pdf::loadView('pdf.client-report', [
            'report' => $report,
            'snapshot' => $report->snapshot ?? [],
            'branding' => $this->branding->for($organization),
            'brandLogo' => $this->branding->logoDataUri($organization),
        ])->setPaper('a4')->output();

        Log::info('Client report PDF generated', [
            'org_id' => $report->org_id,
            'client_report_id' => $report->id,
            'bytes' => strlen($pdf),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $pdf;
    }

    public static function filename(ClientReport $report): string
    {
        return Str::slug(($report->client->name ?? 'client').' '.$report->periodLabel().' report').'.pdf';
    }
}
