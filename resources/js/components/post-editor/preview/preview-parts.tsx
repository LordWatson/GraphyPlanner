import { platformIcon } from '@/components/platform-icon';
import type { AssetData } from '@/components/post-editor/types';
import type { PreviewTarget } from '@/components/social-post-preview';
import {
    isImageAsset,
    isVideoAsset,
    publishableMedia,
} from '@/components/social-preview/media';
import { describeRatio, foldCaption, ratioQuotient } from '@/lib/media-format';
import type { PlatformCapability, PlatformFormat } from '@/lib/platforms';
import { platformLabelOf, usePlatforms } from '@/lib/platforms';
import { cn } from '@/lib/utils';

export type Target = PreviewTarget & { id: number };

export function TargetSwitcher({
    targets,
    activeId,
    onSelect,
}: {
    targets: Target[];
    activeId: number | null;
    onSelect: (id: number) => void;
}) {
    const registry = usePlatforms();

    if (targets.length < 2) {
        return null;
    }

    return (
        <div
            role="tablist"
            aria-label="Channel to preview"
            className="bg-muted flex max-w-full gap-1 overflow-x-auto rounded-full p-1"
        >
            {targets.map((target) => {
                const Icon = platformIcon(target.platform);
                const active = target.id === activeId;

                return (
                    <button
                        key={target.id}
                        type="button"
                        role="tab"
                        onClick={() => onSelect(target.id)}
                        aria-selected={active}
                        title={`${platformLabelOf(registry, target.platform)} · ${target.handle}`}
                        className={cn(
                            'inline-flex min-w-0 shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs whitespace-nowrap transition-colors',
                            active
                                ? 'bg-card text-foreground shadow-xs'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <Icon className="size-3.5 shrink-0" />
                        <span className="max-w-24 truncate">
                            {target.handle}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

/** Plain-language heads-ups about how this channel will treat the media and caption. */
export function previewNotes(
    capability: PlatformCapability,
    spec: PlatformFormat | undefined,
    assets: AssetData[],
    format: string,
): string[] {
    const name = capability.label;
    const notes: string[] = [];
    const media = publishableMedia(assets);
    const video = media.find(isVideoAsset);
    const images = media.filter(isImageAsset);
    const fixed = ratioQuotient(format);

    if (media.length === 0 && !capability.text_only) {
        notes.push(`${name} needs an image or a video.`);
    }

    if (assets.length > media.length && !video) {
        notes.push(
            'Documents and other files aren’t published — only images and video.',
        );
    } else if (video && assets.length > 1) {
        notes.push(
            'Only the video publishes — a post sends one video or a set of images.',
        );
    }

    if (video) {
        notes.push('Video publishes as uploaded — formats apply to images.');

        return notes;
    }

    const first = images[0];

    if (spec?.crop_to) {
        notes.push(
            `A ${spec.label.toLowerCase()} is always ${spec.crop_to} — images are cut to fill the screen around their focus point.`,
        );
    } else if (spec?.aspect && fixed === null && first) {
        const [min, max] = spec.aspect;
        const aspect =
            first.width && first.height ? first.width / first.height : null;

        if (aspect && (aspect < min || aspect > max)) {
            const clamped = aspect < min ? min : max;
            notes.push(
                `${name} only takes ${describeRatio(min)} to ${describeRatio(max)}, so this is cropped to ${describeRatio(clamped)} around its focus point.`,
            );
        }

        if (images.length > 1) {
            notes.push(`${name} shows every slide at the first image’s shape.`);
        }
    }

    if (
        capability.keep_ai_originals &&
        fixed !== null &&
        images.some((image) => image.is_ai_generated)
    ) {
        notes.push(
            `AI images go to ${name} uncropped, so their content credentials stay intact.`,
        );
    }

    return notes;
}

export function CaptionFoldMeter({
    capability,
    caption,
}: {
    capability: PlatformCapability;
    caption: string;
}) {
    if (!caption.trim()) {
        return null;
    }

    const { visible, truncated } = foldCaption(
        caption,
        capability.caption_fold,
    );
    const over = caption.length > capability.caption_limit;

    return (
        <p className="text-muted-foreground flex items-baseline justify-between gap-2 text-xs">
            <span>
                {truncated ? 'Shown before “more”' : 'Whole caption shows'}
            </span>
            <span
                className={cn(
                    'font-mono text-[11px] tabular-nums',
                    over && 'text-destructive',
                )}
            >
                {over
                    ? `${caption.length.toLocaleString()} / ${capability.caption_limit.toLocaleString()} — too long`
                    : truncated
                      ? `${visible.length} / ${caption.length} chars`
                      : `${caption.length} chars`}
            </span>
        </p>
    );
}
