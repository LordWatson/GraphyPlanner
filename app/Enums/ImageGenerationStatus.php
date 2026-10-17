<?php

namespace App\Enums;

/**
 * Lifecycle of one "Generate" click (an AiImageGeneration set), derived from its variants.
 */
enum ImageGenerationStatus: string
{
    case Queued = 'queued';
    case Generating = 'generating';
    case Ready = 'ready';
    case PartiallyFailed = 'partially_failed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Generating => 'Generating',
            self::Ready => 'Ready',
            self::PartiallyFailed => 'Partly ready',
            self::Failed => 'Failed',
        };
    }

    public function isSettled(): bool
    {
        return ! in_array($this, [self::Queued, self::Generating], true);
    }
}
