<?php

namespace App\Services\DesignImports;

use App\Enums\DesignImportProvider;
use App\Models\DesignImportConnection;
use App\Support\DesignImports\DesignFile;
use App\Support\DesignImports\DesignImportedFile;
use App\Support\DesignImports\DesignImportListing;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Step M-5.9 — import designs from Canva (Connect API). Canva renders an export asynchronously, so
 * `download()` starts an export job and polls (briefly, bounded) for the rendered PNG.
 */
class CanvaImportSource extends OAuth2ImportSource
{
    /** How many times to poll an export job, and the gap between polls (µs). */
    private const POLL_ATTEMPTS = 8;

    private const POLL_INTERVAL_US = 500_000;

    public function provider(): DesignImportProvider
    {
        return DesignImportProvider::Canva;
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://www.canva.com/api/oauth/authorize';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://api.canva.com/rest/v1/oauth/token';
    }

    protected function scopes(): array
    {
        return ['design:content:read', 'asset:read'];
    }

    public function listFiles(DesignImportConnection $connection, ?string $cursor = null): DesignImportListing
    {
        $response = $this->request($connection)->get('https://api.canva.com/rest/v1/designs', array_filter([
            'continuation' => $cursor,
        ]));

        $this->reconnectOnAuthFailure($connection, $response->status());

        if ($response->failed()) {
            throw new RuntimeException('Canva listing failed.');
        }

        $files = array_values(array_map(fn (array $design) => new DesignFile(
            id: (string) $design['id'],
            name: (string) ($design['title'] ?? 'Untitled design'),
            mimeType: 'image/png',
            thumbnailUrl: $design['thumbnail']['url'] ?? null,
        ), (array) $response->json('items', [])));

        return new DesignImportListing($files, $response->json('continuation'));
    }

    public function download(DesignImportConnection $connection, string $fileId): DesignImportedFile
    {
        $start = $this->request($connection)->post('https://api.canva.com/rest/v1/exports', [
            'design_id' => $fileId,
            'format' => ['type' => 'png'],
        ]);
        $this->reconnectOnAuthFailure($connection, $start->status());

        if ($start->failed()) {
            throw new RuntimeException('Canva export could not be started.');
        }

        $jobId = (string) $start->json('job.id');
        $url = $this->awaitExport($connection, $jobId);

        // The finished export URL is a short-lived signed URL — no auth header needed.
        $bytes = Http::get($url);

        if ($bytes->failed()) {
            throw new RuntimeException('Canva export download failed.');
        }

        return new DesignImportedFile(
            contents: $bytes->body(),
            mimeType: 'image/png',
            filename: 'canva-'.$fileId.'.png',
            extension: 'png',
        );
    }

    private function awaitExport(DesignImportConnection $connection, string $jobId): string
    {
        for ($attempt = 0; $attempt < self::POLL_ATTEMPTS; $attempt++) {
            $job = $this->request($connection)->get("https://api.canva.com/rest/v1/exports/{$jobId}");
            $this->reconnectOnAuthFailure($connection, $job->status());

            $status = (string) $job->json('job.status');

            if ($status === 'success') {
                $url = $job->json('job.urls.0');

                if (! is_string($url)) {
                    throw new RuntimeException('Canva export returned no file.');
                }

                return $url;
            }

            if ($status === 'failed') {
                throw new RuntimeException('Canva export failed.');
            }

            usleep(self::POLL_INTERVAL_US);
        }

        throw new RuntimeException('Canva export timed out.');
    }
}
