<?php

namespace App\Contracts;

use App\Enums\DesignImportProvider;
use App\Exceptions\DesignImportReconnectRequired;
use App\Models\DesignImportConnection;
use App\Support\DesignImports\DesignImportedFile;
use App\Support\DesignImports\DesignImportListing;
use Carbon\CarbonInterface;

/**
 * Step M-5.9 (per Step I-15.2) — one design/cloud import source (Canva, Google Drive, Dropbox),
 * behind a contract so each sits behind its own swappable implementation and is faked in tests.
 * Figma stays link-only and is not a source.
 *
 * Implementations never log tokens. When a connection can no longer be used they throw
 * `App\Exceptions\DesignImportReconnectRequired` so the UI can prompt a reconnect.
 */
interface DesignImportSource
{
    public function provider(): DesignImportProvider;

    /**
     * The provider's OAuth2 authorization URL to begin connecting, with the CSRF `state`.
     */
    public function authorizationUrl(string $redirectUri, string $state): string;

    /**
     * Exchange an OAuth2 callback code for tokens. Returns the attributes to persist on the
     * connection: `access_token`, and optionally `refresh_token`, `expires_at`, `account_label`.
     *
     * @return array{access_token: string, refresh_token?: string|null, expires_at?: CarbonInterface|null, account_label?: string|null}
     */
    public function exchangeCode(string $code, string $redirectUri): array;

    /**
     * List importable files, paginated by an opaque cursor.
     *
     * @throws DesignImportReconnectRequired
     */
    public function listFiles(DesignImportConnection $connection, ?string $cursor = null): DesignImportListing;

    /**
     * Download one file's bytes.
     *
     * @throws DesignImportReconnectRequired
     */
    public function download(DesignImportConnection $connection, string $fileId): DesignImportedFile;
}
