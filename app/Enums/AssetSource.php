<?php

namespace App\Enums;

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
