<?php

namespace App\Services\DesignImports;

use App\Contracts\DesignImportSource;
use App\Enums\DesignImportProvider;
use App\Exceptions\DesignImportReconnectRequired;
use App\Models\DesignImportConnection;
use App\Support\DesignImports\DesignFile;
use App\Support\DesignImports\DesignImportedFile;
use App\Support\DesignImports\DesignImportListing;

/**
 * Step M-5.9 — an in-memory `DesignImportSource` for tests and local development: canned files and
 * bytes, and an optional "dead connection" switch so the reconnect path can be exercised without a
 * real provider. Bound in tests via the concrete source class the manager resolves.
 */
class FakeDesignImportSource implements DesignImportSource
{
    /**
     * @param  list<DesignFile>  $files
     */
    public function __construct(
        private DesignImportProvider $provider = DesignImportProvider::Canva,
        private array $files = [],
        private string $contents = 'fake-bytes',
        private string $mimeType = 'image/png',
        private bool $dead = false,
    ) {}

    public function provider(): DesignImportProvider
    {
        return $this->provider;
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return 'https://example.test/oauth?state='.$state.'&redirect_uri='.urlencode($redirectUri);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        return [
            'access_token' => 'fake-access-'.$code,
            'refresh_token' => 'fake-refresh',
            'expires_at' => now()->addHour(),
            'account_label' => 'Fake account',
        ];
    }

    public function listFiles(DesignImportConnection $connection, ?string $cursor = null): DesignImportListing
    {
        $this->guard($connection);

        return new DesignImportListing($this->files !== []
            ? $this->files
            : [new DesignFile('file-1', 'Example design', $this->mimeType)]);
    }

    public function download(DesignImportConnection $connection, string $fileId): DesignImportedFile
    {
        $this->guard($connection);

        return new DesignImportedFile(
            contents: $this->contents,
            mimeType: $this->mimeType,
            filename: $fileId.'.png',
            extension: 'png',
        );
    }

    private function guard(DesignImportConnection $connection): void
    {
        if ($this->dead || $connection->isRevoked()) {
            throw new DesignImportReconnectRequired($connection);
        }
    }
}
