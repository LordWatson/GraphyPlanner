<?php

namespace App\Enums;

/**
 * Phase 18 — what a grid tile will become: one image, a carousel (2–10 items, from the Instagram
 * carousel `FormatSpec`), a Reel (a video with an optional cover), or a placeholder (a reserved
 * slot with a colour and a note, no media yet).
 */
enum GridTileKind: string
{
    case Image = 'image';
    case Carousel = 'carousel';
    case Reel = 'reel';
    case Placeholder = 'placeholder';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Image',
            self::Carousel => 'Carousel',
            self::Reel => 'Reel',
            self::Placeholder => 'Placeholder',
        };
    }

    /**
     * The format a new tile of this kind starts with: Reels are 9:16, everything else fills the
     * 3:4 grid tile.
     */
    public function defaultFormat(): MediaRatio
    {
        return $this === self::Reel ? MediaRatio::Vertical : MediaRatio::Classic;
    }

    /**
     * The Instagram `PostTarget::format` key the tile's post publishes as (Step 18.9).
     */
    public function targetFormat(): ?string
    {
        return match ($this) {
            self::Image => 'feed',
            self::Carousel => 'carousel',
            self::Reel => 'reel',
            self::Placeholder => null,
        };
    }
}
