import type { PlatformCapability, PlatformFormat } from '@/lib/platforms';

/**
 * Image formats and framing for post previews. The frontend mirror of `App\Enums\MediaRatio`,
 * `App\Support\Media\CropBox` and `App\Services\Media\PublishImageCropper` — keep them in step,
 * so the preview frames media exactly the way the publisher cuts it.
 */

export type MediaRatioValue =
    | 'original'
    | '1:1'
    | '4:5'
    | '3:4'
    | '1.91:1'
    | '9:16';

/** Format per channel, e.g. `{ instagram: '4:5', tiktok: 'original' }`. */
export type MediaFormat = Partial<Record<string, string>>;

export type MediaFormatOption = { value: string; label: string };

/** Formats each channel offers, as sent by `PostMediaPresenter::formatOptions()`. */
export type MediaFormatOptions = Record<string, MediaFormatOption[]>;

/** An image's focus point (0–1 from the top-left) and zoom (1 = the largest box of the ratio). */
export type Crop = { x: number; y: number; zoom: number };

export const DEFAULT_CROP: Crop = { x: 0.5, y: 0.5, zoom: 1 };

export const MIN_ZOOM = 1;
export const MAX_ZOOM = 3;

/**
 * The feed range most platforms display an image in (width ÷ height) when the registry gives no
 * range of its own. Platform rules themselves come from `config/platforms.php` (`usePlatforms()`).
 */
const FEED_MIN = 0.8;
const FEED_MAX = 1.91;

const quotients: Record<string, number | null> = {
    original: null,
    '1:1': 1,
    '4:5': 4 / 5,
    '3:4': 3 / 4,
    '1.91:1': 1.91,
    '9:16': 9 / 16,
};

export const ratioLabels: Record<string, string> = {
    original: 'Original',
    '1:1': 'Square',
    '4:5': 'Portrait',
    '3:4': 'Classic portrait',
    '1.91:1': 'Landscape',
    '9:16': 'Full screen',
};

/** Width ÷ height of a format, or null for `original`. */
export function ratioQuotient(ratio: string | null | undefined): number | null {
    return quotients[ratio ?? 'original'] ?? null;
}

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

export function normalizeCrop(crop: Partial<Crop> | null | undefined): Crop {
    return {
        x: clamp(Number(crop?.x ?? 0.5), 0, 1),
        y: clamp(Number(crop?.y ?? 0.5), 0, 1),
        zoom: clamp(Number(crop?.zoom ?? 1), MIN_ZOOM, MAX_ZOOM),
    };
}

/** A crop as fractions of the image: left, top, width, height (all 0–1). */
export type CropRect = { x: number; y: number; w: number; h: number };

/**
 * `CropBox::compute()` on fractions: the largest box of `ratio` that fits an image of
 * `imageAspect`, shrunk by zoom, centred on the focus point and clamped inside the image.
 */
export function cropBox(
    imageAspect: number,
    ratio: number,
    crop: Crop,
): CropRect {
    let w = 1;
    let h = 1;

    if (imageAspect > ratio) {
        w = ratio / imageAspect;
    } else {
        h = imageAspect / ratio;
    }

    const zoom = clamp(crop.zoom, MIN_ZOOM, MAX_ZOOM);
    w /= zoom;
    h /= zoom;

    return {
        x: clamp(crop.x - w / 2, 0, 1 - w),
        y: clamp(crop.y - h / 2, 0, 1 - h),
        w,
        h,
    };
}

/**
 * How a channel frames a post's media slot:
 * - `ratio`: the slot's width ÷ height;
 * - `fit`: `crop` fills the slot, `contain` letterboxes the media inside it;
 * - `focus`: whether the saved focus point decides the crop (we cut it before publishing) or the
 *   platform centre-crops the original on its own.
 */
export type MediaFrame = {
    ratio: number;
    fit: 'crop' | 'contain';
    focus: boolean;
};

const VERTICAL = 9 / 16;

/**
 * The frame a channel shows media in, given the post's format there, the first media item's
 * shape (null when not known yet) and — when known — the target's registry format (Step M-2.3).
 * Mirrors `PublishImageCropper::targetRatio()` for images:
 * - a format with a forced framing (`crop_to`, Stories) always shows at it;
 * - a full-screen format (`vertical`) fills a 9:16 screen — images letterbox there unless the post
 *   crops them (TikTok photo mode);
 * - a fixed post format crops to it; otherwise images inside an accepted range (`aspect`, the
 *   Instagram feed) are cut into it, and other feeds centre-crop the original themselves.
 */
export function frameFor(
    platform: string,
    format: string | null | undefined,
    firstAspect: number | null,
    isVideo: boolean,
    spec?: PlatformFormat | null,
): MediaFrame {
    const aspect = firstAspect ?? 1;
    const forced = ratioQuotient(spec?.crop_to);
    // Before the registry reaches the page, keep the old per-platform defaults.
    const vertical = spec
        ? spec.vertical
        : platform === 'tiktok' || (platform === 'instagram' && isVideo);
    const range: [number, number] = spec?.aspect ?? [FEED_MIN, FEED_MAX];

    if (isVideo) {
        // Videos publish as uploaded: full-screen formats fill a vertical screen, feeds keep the
        // clip's own shape within their display range.
        return vertical || forced !== null
            ? { ratio: VERTICAL, fit: 'crop', focus: false }
            : {
                  ratio: clamp(aspect, range[0], range[1]),
                  fit: 'crop',
                  focus: false,
              };
    }

    if (forced !== null) {
        return { ratio: forced, fit: 'crop', focus: true };
    }

    const fixed = ratioQuotient(format);

    if (vertical) {
        // A full-screen photo format plays at 9:16; anything else sits on black.
        return fixed !== null
            ? { ratio: VERTICAL, fit: 'crop', focus: true }
            : { ratio: VERTICAL, fit: 'contain', focus: false };
    }

    if (fixed !== null) {
        return { ratio: fixed, fit: 'crop', focus: true };
    }

    if (spec ? spec.aspect !== null : platform === 'instagram') {
        // We cut originals into the accepted range (and a carousel to slide one).
        return {
            ratio: clamp(aspect, range[0], range[1]),
            fit: 'crop',
            focus: true,
        };
    }

    // Everyone else gets the original and centre-crops it into their feed range themselves.
    return {
        ratio: clamp(aspect, FEED_MIN, FEED_MAX),
        fit: 'crop',
        focus: false,
    };
}

/** Whether a format is a vertical, full-screen one where app chrome covers the edges. */
export function isFullScreenFrame(frame: MediaFrame): boolean {
    return Math.abs(frame.ratio - VERTICAL) < 0.01;
}

/** "4:5" style label for any width ÷ height. */
export function describeRatio(ratio: number): string {
    const known = Object.entries(quotients).find(
        ([, value]) => value !== null && Math.abs(value - ratio) < 0.01,
    );

    if (known) {
        return known[0];
    }

    return ratio >= 1 ? `${ratio.toFixed(2)}:1` : `1:${(1 / ratio).toFixed(2)}`;
}

/**
 * The caption exactly as it publishes — the mirror of `App\Support\Publishing\CaptionComposer`.
 * With a platform's capabilities: a link goes in the caption only where there's no dedicated link
 * field and links work in captions; hashtags are dropped where the platform doesn't use them, and
 * sit below the eight-line "dot-dash" spacer only where it folds captions (`hashtag_spacer`).
 * Without capabilities it behaves as before (spacer, no link).
 */
export function publishedCaption(
    caption: string,
    hashtags: string[],
    capability?: PlatformCapability | null,
    link?: string | null,
): string {
    let text = caption.trim();
    const cleanLink = link?.trim() ?? '';

    if (
        capability &&
        cleanLink !== '' &&
        !capability.link_field &&
        capability.links_in_caption &&
        !text.includes(cleanLink)
    ) {
        text = text === '' ? cleanLink : `${text}\n\n${cleanLink}`;
    }

    const tags =
        capability && !capability.hashtags
            ? []
            : [
                  ...new Set(
                      hashtags
                          .map((tag) => tag.replace(/^#+/, '').trim())
                          .filter(Boolean),
                  ),
              ];

    if (tags.length === 0) {
        return capability ? text : caption;
    }

    const tagText = tags.map((tag) => `#${tag}`).join(' ');

    if (text === '') {
        return tagText;
    }

    const spacer =
        !capability || capability.hashtag_spacer
            ? `\n${Array.from({ length: 8 }, () => '-').join('\n')}\n`
            : '\n\n';

    return `${text}${spacer}${tagText}`;
}

/** Fallback fold when the registry hasn't reached the page. */
const DEFAULT_FOLD = { chars: 240, lines: 5 };

/** The visible part of a caption before the fold, and whether anything is hidden. */
export function foldCaption(
    text: string,
    fold: { chars: number; lines: number } = DEFAULT_FOLD,
): { visible: string; truncated: boolean } {
    const lines = text.split('\n');
    let visible = lines.slice(0, fold.lines).join('\n');

    if (visible.length > fold.chars) {
        // Cut on a word boundary, like the apps do.
        const cut = visible.slice(0, fold.chars);
        const space = cut.lastIndexOf(' ');
        visible = (
            space > fold.chars * 0.6 ? cut.slice(0, space) : cut
        ).trimEnd();
    }

    return { visible, truncated: visible.length < text.trimEnd().length };
}
