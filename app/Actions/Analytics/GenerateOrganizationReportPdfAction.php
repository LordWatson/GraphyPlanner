<?php

namespace App\Actions\Analytics;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Step 17.7 — the branded analytics PDF: an internal studio review document (the client-facing
 * version is Phase 8). A cover with the organization, report name and range, then one page per
 * section in the summary it's given, footed "Marketing brighter together.".
 *
 * It renders whatever summary it's handed — the controller always builds that summary through
 * `GetOrganizationAnalyticsSummaryAction` with the requesting viewer, so a Designer's PDF has no
 * billing page and no AI cost. Nothing is stored: the bytes stream straight to the browser.
 */
class GenerateOrganizationReportPdfAction
{
    /**
     * @param  array<string, mixed>  $summary
     */
    public function __invoke(string $title, array $summary, ?string $description = null): string
    {
        // dompdf caches the metrics of the bundled brand fonts here on first use.
        File::ensureDirectoryExists(storage_path('fonts'));

        $started = microtime(true);

        $pdf = Pdf::loadView('pdf.analytics-report', [
            'title' => $title,
            'description' => $description,
            'summary' => $summary,
            'generatedAt' => now()->setTimezone($summary['filters']['timezone'] ?? 'UTC'),
        ])->setPaper('a4')->output();

        Log::info('Organization analytics PDF generated', [
            'metrics' => $summary['metrics'] ?? [],
            'range' => $summary['filters']['range'] ?? null,
            'bytes' => strlen($pdf),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $pdf;
    }
}
