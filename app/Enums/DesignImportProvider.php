<?php

namespace App\Enums;

/**
 * Step M-5.9 — a third-party design/cloud source posts can import media from. Each maps to a
 * `DesignImportSource` implementation and an `AssetSource` tag on the imported asset. Figma stays
 * link-only (it isn't here).
 */
enum DesignImportProvider: string
{
    case Canva = 'canva';
    case GoogleDrive = 'google_drive';
    case Dropbox = 'dropbox';

    public function label(): string
    {
        return match ($this) {
            self::Canva => 'Canva',
            self::GoogleDrive => 'Google Drive',
            self::Dropbox => 'Dropbox',
        };
    }

    /** The `AssetSource` an asset imported from this provider is tagged with. */
    public function assetSource(): AssetSource
    {
        return match ($this) {
            self::Canva => AssetSource::Canva,
            self::GoogleDrive => AssetSource::GoogleDrive,
            self::Dropbox => AssetSource::Dropbox,
        };
    }
}
