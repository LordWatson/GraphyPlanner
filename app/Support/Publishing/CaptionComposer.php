<?php

namespace App\Support\Publishing;

/**
 * Step I-3.1 / M-2.2 — builds the caption exactly as it publishes on one platform, from the
 * target's effective caption, hashtags and link. The adapter sends this text, the checklist counts
 * it against the platform's limit, and the frontend mirror (`publishedCaption()` in
 * `resources/js/lib/media-format.ts`) previews it — keep the two in step.
 *
 * - A link goes in the caption only where the platform has no dedicated link field and links in
 *   captions are welcome; it's skipped when the caption already contains it.
 * - Hashtags are dropped where the platform doesn't use them. Where `hashtag_spacer` is set they
 *   sit below the eight-line "dot-dash" spacer (pushed under the "more" fold); elsewhere after a
 *   blank line.
 */
final class CaptionComposer
{
    public const SPACER_LINES = 8;

    /**
     * @param  list<string>  $hashtags  bare tags (no "#")
     */
    public static function compose(PlatformSpec $spec, string $caption, array $hashtags, ?string $link = null): string
    {
        $text = trim($caption);

        if (self::linkGoesInCaption($spec, $link) && ! str_contains($text, (string) $link)) {
            $text = $text === '' ? (string) $link : $text."\n\n".$link;
        }

        $tags = $spec->hashtags ? self::tagText($hashtags) : '';

        if ($tags === '') {
            return $text;
        }

        if ($text === '') {
            return $tags;
        }

        $separator = $spec->hashtagSpacer
            ? "\n".implode("\n", array_fill(0, self::SPACER_LINES, '-'))."\n"
            : "\n\n";

        return $text.$separator.$tags;
    }

    /**
     * Whether a target's link is appended to its caption (rather than sent in a dedicated field,
     * or not sent at all because the platform buries caption links).
     */
    public static function linkGoesInCaption(PlatformSpec $spec, ?string $link): bool
    {
        return filled($link) && $spec->linkField === null && $spec->linksInCaption;
    }

    /**
     * @param  list<string>  $hashtags
     */
    private static function tagText(array $hashtags): string
    {
        return collect($hashtags)
            ->map(fn ($tag) => ltrim(trim((string) $tag), '#'))
            ->filter(fn ($tag) => $tag !== '')
            ->unique()
            ->map(fn ($tag) => '#'.$tag)
            ->implode(' ');
    }
}
