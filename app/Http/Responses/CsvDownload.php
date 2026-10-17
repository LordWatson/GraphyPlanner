<?php

namespace App\Http\Responses;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams CSV rows as a file download (analytics exports, Step 17.7) — rows are written as they
 * stream, so nothing is buffered in a temp file.
 */
final class CsvDownload
{
    /**
     * @param  iterable<list<string|int|float|null>>  $rows
     */
    public static function make(iterable $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            foreach ($rows as $row) {
                fputcsv($handle, $row, escape: '\\');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
