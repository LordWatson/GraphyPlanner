<?php

namespace App\Enums;

enum AssetType: string
{
    case Image = 'image';
    case Video = 'video';
    case Document = 'document';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Image',
            self::Video => 'Video',
            self::Document => 'Document',
            self::Other => 'Other',
        };
    }
}
