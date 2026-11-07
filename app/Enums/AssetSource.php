<?php

namespace App\Enums;

use App\Rules\SafeUpload;

enum AssetSource: string
{
    case Upload = 'upload';
    case Figma = 'figma';
    case Url = 'url';
    case Ai = 'ai';
    // Step M-5.9: design/cloud imports (each behind a `DesignImportSource`).
    case Canva = 'canva';
    case GoogleDrive = 'google_drive';
    case Dropbox = 'dropbox';

    /**
     * Step GTM-1.1 — did these bytes go through the upload safety pipeline ({@see SafeUpload}:
     * content sniffing and the `VirusScanner`)? Only a scanned file may be sold as a paid download;
     * a design/cloud import is third-party bytes we stored without ever looking inside them. A new
     * case has to answer this deliberately rather than inherit a default.
     */
    public function isVirusScanned(): bool
    {
        return match ($this) {
            // Web/MCP upload: SafeUpload runs on the way in.
            self::Upload => true,
            // Rendered by our own image provider from a prompt, never a user's file.
            self::Ai => true,
            self::Figma, self::Url, self::Canva, self::GoogleDrive, self::Dropbox => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Upload => 'Upload',
            self::Figma => 'Figma link',
            self::Url => 'External URL',
            self::Ai => 'AI generated',
            self::Canva => 'Canva',
            self::GoogleDrive => 'Google Drive',
            self::Dropbox => 'Dropbox',
        };
    }
}
