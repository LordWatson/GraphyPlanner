<?php

namespace App\Enums;

/**
 * How a variant came to exist (Steps 16.7/16.8): part of the original set, a plain-words
 * refinement or a fresh seed of an earlier tile, or the final-tier re-render of a chosen draft.
 */
enum ImageVariantKind: string
{
    case Generate = 'generate';
    case Refine = 'refine';
    case Regenerate = 'regenerate';
    case Final = 'final';

    public function label(): string
    {
        return match ($this) {
            self::Generate => 'Draft',
            self::Refine => 'Refined',
            self::Regenerate => 'Regenerated',
            self::Final => 'Final',
        };
    }

    /**
     * The `ai_usage_logs.method` a vendor call of this kind is recorded under (Step 16.11).
     */
    public function usageMethod(): string
    {
        return $this === self::Refine ? 'image.edit' : 'image.generate';
    }
}
