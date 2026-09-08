<?php

namespace App\Enums;

enum AssetSource: string
{
    case Upload = 'upload';
    case Figma = 'figma';
    case Url = 'url';

    public function label(): string
    {
        return match ($this) {
            self::Upload => 'Upload',
            self::Figma => 'Figma link',
            self::Url => 'External URL',
        };
    }
}
