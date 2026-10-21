<?php

namespace App\Actions\Analytics;

use App\Models\ClientReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step M-3.3 — the branded monthly report PDF (`PdfBrand`, the analytics report's "Studio Paper"
 * look), drawn only from the report's snapshot and commentary — never live numbers, never billing.
 * Nothing is stored.
 */
class GenerateClientReportPdfAction
{
    public function __invoke(ClientReport $report): string
    {
        File::ensureDirectoryExists(storage_path('fonts'));
        $started = microtime(true);

        $pdf = Pdf::loadView('pdf.client-report', [
            'report' => $report,
            'snapshot' => $report->snapshot ?? [],
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
