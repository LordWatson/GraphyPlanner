<?php

namespace App\Services\DesignImports;

use App\Contracts\DesignImportSource;
use App\Enums\DesignImportProvider;
use InvalidArgumentException;

/**
 * Step M-5.9 — resolves the `DesignImportSource` for a provider and reports which providers are
 * switched on (configured credentials, `config/design-imports.php`). Each source is resolved from
 * the container, so a test can bind one to a fake.
 */
class DesignImportManager
{
    public function for(DesignImportProvider $provider): DesignImportSource
    {
        $class = match ($provider) {
            DesignImportProvider::Canva => CanvaImportSource::class,
            DesignImportProvider::GoogleDrive => GoogleDriveImportSource::class,
            DesignImportProvider::Dropbox => DropboxImportSource::class,
        };

        return app($class);
    }

    public function isEnabled(DesignImportProvider $provider): bool
    {
        return (bool) config("design-imports.{$provider->value}.enabled", false);
    }

    /**
     * @return list<DesignImportProvider>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            DesignImportProvider::cases(),
            fn (DesignImportProvider $provider) => $this->isEnabled($provider),
        ));
    }

    public function enabledOrFail(DesignImportProvider $provider): DesignImportSource
    {
        if (! $this->isEnabled($provider)) {
            throw new InvalidArgumentException("The {$provider->label()} import isn’t configured.");
        }

        return $this->for($provider);
    }
}
