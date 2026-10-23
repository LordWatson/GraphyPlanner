<?php

namespace App\Actions\Business;

use App\Models\Client;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step B-8.4 — the branded one-page media kit PDF, drawn only from the already-public kit data
 * ({@see BuildMediaKitAction}) so it can never leak private figures. Nothing is stored.
 */
class GenerateMediaKitPdfAction
{
    /**
     * @param  array<string, mixed>  $kit  the output of {@see BuildMediaKitAction}
     */
    public function __invoke(array $kit): string
    {
        File::ensureDirectoryExists(storage_path('fonts'));
        $started = microtime(true);

        $pdf = Pdf::loadView('pdf.media-kit', ['kit' => $kit])->setPaper('a4')->output();

        Log::info('Media kit PDF generated', [
            'bytes' => strlen($pdf),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $pdf;
    }

    public static function filename(Client $brand): string
    {
        return Str::slug($brand->name.' media kit').'.pdf';
    }
}
