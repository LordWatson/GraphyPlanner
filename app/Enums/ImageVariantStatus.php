<?php

namespace App\Enums;

enum ImageVariantStatus: string
{
    case Queued = 'queued';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Generating => 'Drafting',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
        };
    }

    public function isPending(): bool
    {
        return $this === self::Queued || $this === self::Generating;
    }
}
