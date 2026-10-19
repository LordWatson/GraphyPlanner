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

/** Instagram's accepted feed range (width ÷ height) — `MediaRatio::INSTAGRAM_MIN/MAX`. */
export const INSTAGRAM_MIN = 0.8;
export const INSTAGRAM_MAX = 1.91;

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
 * The frame a channel shows media in, given the post's format there and the first media item's
 * shape (null when not known yet). Mirrors `PublishImageCropper::targetRatio()` for images.
 */
export function frameFor(
    platform: string,
    format: string | null | undefined,
    firstAspect: number | null,
    isVideo: boolean,
): MediaFrame {
    const aspect = firstAspect ?? 1;

    if (isVideo) {
        // Videos publish as uploaded: Reels and TikTok fill a vertical screen, the feeds keep
        // the clip's own shape within their display range.
        if (platform === 'instagram' || platform === 'tiktok') {
            return { ratio: VERTICAL, fit: 'crop', focus: false };
        }

        return {
            ratio: clamp(aspect, INSTAGRAM_MIN, INSTAGRAM_MAX),
            fit: 'crop',
            focus: false,
        };
    }

    const fixed = ratioQuotient(format);

    if (platform === 'tiktok') {
        // Photo mode always plays full screen; anything that isn't 9:16 sits on black.
        return fixed !== null
            ? { ratio: VERTICAL, fit: 'crop', focus: true }
            : { ratio: VERTICAL, fit: 'contain', focus: false };
    }

    if (fixed !== null) {
        return { ratio: fixed, fit: 'crop', focus: true };
    }

    if (platform === 'instagram') {
        // We cut Instagram originals into its accepted range (and a carousel to slide one).
        return {
            ratio: clamp(aspect, INSTAGRAM_MIN, INSTAGRAM_MAX),
            fit: 'crop',
            focus: true,
        };
    }

    // Facebook/LinkedIn get the original and centre-crop it into their feed range themselves.
    return {
        ratio: clamp(aspect, INSTAGRAM_MIN, INSTAGRAM_MAX),
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
 * The caption exactly as it publishes. `UploadPostAdapter::applyHashtagCaptionOverride()`
 * appends hashtags below an eight-line "dot-dash" spacer, so they sit under the "more" fold.
 */
export function publishedCaption(caption: string, hashtags: string[]): string {
    const tags = hashtags
        .map((tag) => tag.replace(/^#+/, '').trim())
        .filter(Boolean);

    if (tags.length === 0) {
        return caption;
    }

    const spacer = Array.from({ length: 8 }, () => '-').join('\n');

    return `${caption.trim()}\n${spacer}\n${tags.map((tag) => `#${tag}`).join(' ')}`;
}

/**
 * Roughly where each channel folds a caption behind "more" in the feed: whichever comes first of
 * a character count or a number of lines. Approximations of the apps' mobile layouts.
 */
export const captionFolds: Record<string, { chars: number; lines: number }> = {
    instagram: { chars: 125, lines: 2 },
    tiktok: { chars: 80, lines: 2 },
    facebook: { chars: 240, lines: 5 },
    linkedin: { chars: 210, lines: 3 },
};

/** The visible part of a caption before the fold, and whether anything is hidden. */
export function foldCaption(
    text: string,
    platform: string,
): { visible: string; truncated: boolean } {
    const fold = captionFolds[platform] ?? { chars: 240, lines: 5 };
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
