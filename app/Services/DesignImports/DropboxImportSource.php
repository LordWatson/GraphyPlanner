<?php

namespace App\Services\DesignImports;

use App\Enums\DesignImportProvider;
use App\Models\DesignImportConnection;
use App\Support\DesignImports\DesignFile;
use App\Support\DesignImports\DesignImportedFile;
use App\Support\DesignImports\DesignImportExtension;
use App\Support\DesignImports\DesignImportListing;
use RuntimeException;

/**
 * Step M-5.9 — import images and videos from the user's Dropbox (read-only).
 */
class DropboxImportSource extends OAuth2ImportSource
{
    private const MEDIA_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'mp4', 'mov'];

    public function provider(): DesignImportProvider
    {
        return DesignImportProvider::Dropbox;
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://www.dropbox.com/oauth2/authorize';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://api.dropboxapi.com/oauth2/token';
    }

    protected function scopes(): array
    {
        return ['files.content.read', 'files.metadata.read'];
    }

    protected function authorizeParams(): array
    {
        // `offline` so Dropbox returns a refresh token.
        return ['token_access_type' => 'offline'];
    }

    public function listFiles(DesignImportConnection $connection, ?string $cursor = null): DesignImportListing
    {
        $response = $cursor !== null
            ? $this->request($connection)->post('https://api.dropboxapi.com/2/files/list_folder/continue', ['cursor' => $cursor])
            : $this->request($connection)->post('https://api.dropboxapi.com/2/files/list_folder', [
                'path' => '',
                'recursive' => false,
                'limit' => 100,
            ]);

        $this->reconnectOnAuthFailure($connection, $response->status());

        if ($response->failed()) {
            throw new RuntimeException('Dropbox listing failed.');
        }

        $files = [];
        foreach ((array) $response->json('entries', []) as $entry) {
            if (($entry['.tag'] ?? '') !== 'file') {
                continue;
            }
            $name = (string) ($entry['name'] ?? '');
            if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::MEDIA_EXTENSIONS, true)) {
                continue;
            }
            $files[] = new DesignFile(
                id: (string) ($entry['path_lower'] ?? $entry['id'] ?? ''),
                name: $name,
                mimeType: null,
                thumbnailUrl: null,
                size: isset($entry['size']) ? (int) $entry['size'] : null,
            );
        }

        $nextCursor = ($response->json('has_more') === true) ? $response->json('cursor') : null;

        return new DesignImportListing($files, $nextCursor);
    }

    public function download(DesignImportConnection $connection, string $fileId): DesignImportedFile
    {
        $response = $this->request($connection)
            ->withHeaders(['Dropbox-API-Arg' => json_encode(['path' => $fileId])])
            ->post('https://content.dropboxapi.com/2/files/download');

        $this->reconnectOnAuthFailure($connection, $response->status());

        if ($response->failed()) {
            throw new RuntimeException('Dropbox download failed.');
        }

        $name = basename($fileId);
        $extension = DesignImportExtension::fromMime('', $name);

        return new DesignImportedFile(
            contents: $response->body(),
            mimeType: (string) ($response->header('Content-Type') ?: 'application/octet-stream'),
            filename: $name,
            extension: $extension,
        );
    }
}
