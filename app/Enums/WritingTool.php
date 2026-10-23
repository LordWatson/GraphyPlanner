<?php

namespace App\Enums;

/**
 * Step M-7.3 (dev plan I-9.4) — the inline editor writing tools that rewrite a caption in the brand
 * voice. A human always confirms the result; nothing is saved automatically.
 */
enum WritingTool: string
{
    case Shorten = 'shorten';
    case Expand = 'expand';
    case ChangeTone = 'change_tone';
    case Adapt = 'adapt';
    case AddCta = 'add_cta';

    public function label(): string
    {
        return match ($this) {
            self::Shorten => 'Make it shorter',
            self::Expand => 'Make it longer',
            self::ChangeTone => 'Change tone',
            self::Adapt => 'Adapt to platform',
            self::AddCta => 'Add a call to action',
        };
    }

    /**
     * Whether this tool needs a target platform (Adapt) to do its job.
     */
    public function needsPlatform(): bool
    {
        return $this === self::Adapt;
    }

    /**
     * Whether this tool takes a tone (ChangeTone).
     */
    public function needsTone(): bool
    {
        return $this === self::ChangeTone;
    }
}
