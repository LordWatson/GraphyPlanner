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
 * Step M-5.9 — import images and videos from the user's Google Drive (read-only).
 */
class GoogleDriveImportSource extends OAuth2ImportSource
{
    public function provider(): DesignImportProvider
    {
        return DesignImportProvider::GoogleDrive;
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function scopes(): array
    {
        return ['https://www.googleapis.com/auth/drive.readonly'];
    }

    protected function authorizeParams(): array
    {
        // `offline` + `consent` so Google returns a refresh token.
        return ['access_type' => 'offline', 'prompt' => 'consent'];
    }

    public function listFiles(DesignImportConnection $connection, ?string $cursor = null): DesignImportListing
    {
        $response = $this->request($connection)->get('https://www.googleapis.com/drive/v3/files', [
            'q' => "(mimeType contains 'image/' or mimeType contains 'video/') and trashed = false",
            'fields' => 'nextPageToken, files(id, name, mimeType, thumbnailLink, size)',
            'pageSize' => 50,
            'pageToken' => $cursor,
            'orderBy' => 'modifiedTime desc',
        ]);

        $this->reconnectOnAuthFailure($connection, $response->status());

        if ($response->failed()) {
            throw new RuntimeException('Google Drive listing failed.');
        }

        $files = array_values(array_map(fn (array $file) => new DesignFile(
            id: (string) $file['id'],
            name: (string) ($file['name'] ?? 'Untitled'),
            mimeType: $file['mimeType'] ?? null,
            thumbnailUrl: $file['thumbnailLink'] ?? null,
            size: isset($file['size']) ? (int) $file['size'] : null,
        ), (array) $response->json('files', [])));

        return new DesignImportListing($files, $response->json('nextPageToken'));
    }

    public function download(DesignImportConnection $connection, string $fileId): DesignImportedFile
    {
        $meta = $this->request($connection)->get("https://www.googleapis.com/drive/v3/files/{$fileId}", [
            'fields' => 'name, mimeType',
        ]);
        $this->reconnectOnAuthFailure($connection, $meta->status());

        $bytes = $this->request($connection)->get("https://www.googleapis.com/drive/v3/files/{$fileId}", [
            'alt' => 'media',
        ]);
        $this->reconnectOnAuthFailure($connection, $bytes->status());

        if ($bytes->failed()) {
            throw new RuntimeException('Google Drive download failed.');
        }

        $mime = (string) ($meta->json('mimeType') ?? 'application/octet-stream');
        $name = (string) ($meta->json('name') ?? 'drive-file');

        return new DesignImportedFile(
            contents: $bytes->body(),
            mimeType: $mime,
            filename: $name,
            extension: DesignImportExtension::fromMime($mime, $name),
        );
    }
}
