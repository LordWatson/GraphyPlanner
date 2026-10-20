<?php

namespace App\Enums;

/**
 * Output formats the AI Image Studio (Phase 16) can render. The per-platform default lives here
 * (not in the UI) so every caller — the studio's preselected format, a bulk job, an API client —
 * agrees on which ratio a platform's feed wants.
 */
enum ImageAspectRatio: string
{
    case Square_1_1 = '1:1';
    case Portrait_4_5 = '4:5';
    // Step 18.12: the Instagram profile grid's tile shape ("Fill this slot" in the grid planner).
    case Classic_3_4 = '3:4';
    case Story_9_16 = '9:16';
    case Landscape_16_9 = '16:9';
    case Link_1_91_1 = '1.91:1';

    public function label(): string
    {
        return match ($this) {
            self::Square_1_1 => 'Square',
            self::Portrait_4_5 => 'Portrait',
            self::Classic_3_4 => 'Grid tile',
            self::Story_9_16 => 'Story',
            self::Landscape_16_9 => 'Landscape',
            self::Link_1_91_1 => 'Link',
        };
    }

    /**
     * The ratio actually sent to image vendors. 1.91:1 is not a native vendor ratio, so it renders
     * at the closest supported one (16:9); the difference is a light crop in the feed.
     */
    public function providerRatio(): string
    {
        return $this === self::Link_1_91_1 ? self::Landscape_16_9->value : $this->value;
    }

    /**
     * Width ÷ height, used to size placeholders and the studio's tiles.
     */
    public function quotient(): float
    {
        return match ($this) {
            self::Square_1_1 => 1.0,
            self::Portrait_4_5 => 4 / 5,
            self::Classic_3_4 => 3 / 4,
            self::Story_9_16 => 9 / 16,
            self::Landscape_16_9 => 16 / 9,
            self::Link_1_91_1 => 1.91,
        };
    }

    /**
     * Pixel dimensions with the given long edge, e.g. 4:5 at 1024 → 819×1024.
     *
     * @return array{0: int, 1: int}
     */
    public function dimensions(int $longEdge = 1024): array
    {
        $quotient = $this->quotient();

        return $quotient >= 1
            ? [$longEdge, (int) round($longEdge / $quotient)]
            : [(int) round($longEdge * $quotient), $longEdge];
    }

    /**
     * Default format for a platform's feed — the registry's `ai_image_ratio`
     * (`config/platforms.php`), square when unset.
     */
    public static function forPlatform(Platform $platform): self
    {
        return self::tryFrom((string) config("platforms.{$platform->value}.ai_image_ratio")) ?? self::Square_1_1;
    }
}
