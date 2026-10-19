import { usePage } from '@inertiajs/react';

/**
 * The platform capability registry on the frontend (Step I-3.3 / M-2.1). The server shares
 * `config/platforms.php` once per page load as the `platforms` prop
 * (`PlatformCapabilities::toFrontend()`); read it with `usePlatforms()` — never hard-code a
 * platform's label, limit, fold or format here or in a component.
 */

export type PlatformFormat = {
    key: string;
    label: string;
    media: ('image' | 'video')[];
    min_items: number;
    max_items: number;
    /** Accepted width ÷ height range, or null for anything. */
    aspect: [number, number] | null;
    /** The framing images are cut to for this format (Stories: 9:16). */
    crop_to: string | null;
    /** Plays full screen at this ratio, so other shapes get black bars (TikTok photo mode). */
    fill_screen: string | null;
    /** A full-screen vertical placement (Reels, Stories, TikTok, Shorts). */
    vertical: boolean;
    min_video_seconds: number | null;
    max_video_seconds: number | null;
    max_image_mb: number | null;
    max_video_mb: number | null;
};

export type PlatformCapability = {
    value: string;
    label: string;
    enabled: boolean;
    caption_limit: number;
    title_limit: number | null;
    hashtags: boolean;
    max_hashtags: number;
    hashtag_spacer: boolean;
    links_in_caption: boolean;
    /** The post's link goes in a dedicated field (Pinterest destination, Google button). */
    link_field: boolean;
    first_comment: boolean;
    alt_text: boolean;
    text_only: boolean;
    /** AI-generated images go out uncropped (LinkedIn reads their C2PA credentials). */
    keep_ai_originals: boolean;
    required_settings: string[];
    /** Per-account settings, keyed by the vendor field (e.g. `pinterest_board_id`). */
    settings: Record<
        string,
        { label: string; required: boolean; help: string | null }
    >;
    media_ratios: string[];
    caption_fold: { chars: number; lines: number };
    default_format: string;
    formats: PlatformFormat[];
};

export type PlatformRegistry = Record<string, PlatformCapability>;

const FALLBACK: PlatformCapability = {
    value: '',
    label: '',
    enabled: false,
    caption_limit: 2200,
    title_limit: null,
    hashtags: true,
    max_hashtags: 30,
    hashtag_spacer: false,
    links_in_caption: true,
    link_field: false,
    first_comment: false,
    alt_text: false,
    text_only: false,
    keep_ai_originals: false,
    required_settings: [],
    settings: {},
    media_ratios: ['original'],
    caption_fold: { chars: 240, lines: 5 },
    default_format: 'post',
    formats: [],
};

/** The shared registry. Empty only on pages rendered outside Inertia (tests). */
export function usePlatforms(): PlatformRegistry {
    const { platforms } = usePage<{ platforms?: PlatformRegistry }>().props;

    return platforms ?? {};
}

/** One platform's capabilities, with safe defaults for an unknown value. */
export function capabilityOf(
    registry: PlatformRegistry,
    platform: string | null | undefined,
): PlatformCapability {
    const found = platform ? registry[platform] : undefined;

    return (
        found ?? { ...FALLBACK, value: platform ?? '', label: platform ?? '' }
    );
}

/** "Instagram", "Google Business"… falling back to the raw value. */
export function platformLabelOf(
    registry: PlatformRegistry,
    platform: string | null | undefined,
): string {
    return capabilityOf(registry, platform).label || (platform ?? '');
}

export function formatOf(
    capability: PlatformCapability,
    key: string | null | undefined,
): PlatformFormat | undefined {
    return (
        capability.formats.find((format) => format.key === key) ??
        capability.formats.find(
            (format) => format.key === capability.default_format,
        )
    );
}

/**
 * Mirror of `PlatformCapabilities::resolveFormat()`: the explicit format when the platform offers
 * it, else the first (non-forced-crop) format whose media kinds and item bounds fit, else a
 * single-video format for a video post, else the default.
 */
export function resolveFormat(
    capability: PlatformCapability,
    explicit: string | null | undefined,
    mediaKinds: string[],
): PlatformFormat | undefined {
    const chosen = capability.formats.find((format) => format.key === explicit);

    if (chosen) {
        return chosen;
    }

    const auto = capability.formats.filter((format) => format.crop_to === null);
    const fits = auto.find(
        (format) =>
            mediaKinds.length >= format.min_items &&
            mediaKinds.length <= format.max_items &&
            mediaKinds.every((kind) =>
                format.media.includes(kind as 'image' | 'video'),
            ),
    );

    if (fits) {
        return fits;
    }

    if (mediaKinds.includes('video')) {
        const video = auto.find(
            (format) =>
                format.media.includes('video') && format.max_items === 1,
        );

        if (video) {
            return video;
        }
    }

    return formatOf(capability, null);
}
