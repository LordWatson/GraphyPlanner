<?php

namespace App\Enums;

/**
 * How a post's images are framed on a channel (the editor's "Format" control). `Original` keeps
 * the upload's own shape and leaves framing to the platform; every other case is a fixed crop the
 * publisher applies to each image (around its focus point) before it is sent. The frontend mirror
 * lives in `resources/js/lib/media-format.ts` — keep the two in step.
 */
enum MediaRatio: string
{
    case Original = 'original';
    case Square = '1:1';
    case Portrait = '4:5';
    case Classic = '3:4';
    case Landscape = '1.91:1';
    case Vertical = '9:16';

    public function label(): string
    {
        return match ($this) {
            self::Original => 'Original',
            self::Square => 'Square',
            self::Portrait => 'Portrait',
            self::Classic => 'Classic portrait',
            self::Landscape => 'Landscape',
            self::Vertical => 'Full screen',
        };
    }

    /**
     * Width ÷ height, or null for `Original` (the image's own shape).
     */
    public function quotient(): ?float
    {
        return match ($this) {
            self::Original => null,
            self::Square => 1.0,
            self::Portrait => 4 / 5,
            self::Classic => 3 / 4,
            self::Landscape => 1.91,
            self::Vertical => 9 / 16,
        };
    }

    /**
     * The formats each channel offers in the editor, in display order (the registry's
     * `media_ratios`, `config/platforms.php`). The first is the default.
     *
     * @return list<self>
     */
    public static function optionsFor(Platform $platform): array
    {
        $options = array_values(array_filter(array_map(
            fn (string $value) => self::tryFrom($value),
            $platform->spec()->mediaRatios,
        )));

        return $options === [] ? [self::Original] : $options;
    }

    /**
     * `Original` is the default everywhere, so posts saved before formats existed publish exactly
     * as they did then (Instagram's out-of-range clamp aside, which only rescues a rejected post).
     */
    public static function defaultFor(Platform $platform): self
    {
        return self::optionsFor($platform)[0];
    }

    /**
     * The saved format for a platform, falling back to its default when the post has none or the
     * stored value is no longer offered for that channel.
     *
     * @param  array<string, mixed>|null  $mediaFormat
     */
    public static function resolve(?array $mediaFormat, Platform $platform): self
    {
        $ratio = self::tryFrom((string) ($mediaFormat[$platform->value] ?? ''));

        return $ratio !== null && in_array($ratio, self::optionsFor($platform), true)
            ? $ratio
            : self::defaultFor($platform);
    }
}
