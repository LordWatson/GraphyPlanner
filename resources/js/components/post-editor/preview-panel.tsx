import { Crop as CropIcon, Eye, Info, Maximize2, ScanLine } from 'lucide-react';
import { useState } from 'react';
import { platformIcon } from '@/components/platform-icon';
import {
    CropEditorDialog,
    FormatPicker,
} from '@/components/post-editor/crop-editor-dialog';
import type { AssetData } from '@/components/post-editor/types';
import type { PreviewTarget } from '@/components/social-post-preview';
import { SocialPostPreview } from '@/components/social-post-preview';
import {
    isImageAsset,
    isVideoAsset,
    publishableMedia,
} from '@/components/social-preview/media';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Toggle } from '@/components/ui/toggle';
import type { Crop, MediaFormat, MediaFormatOptions } from '@/lib/media-format';
import {
    describeRatio,
    foldCaption,
    publishedCaption,
    ratioLabels,
    ratioQuotient,
} from '@/lib/media-format';
import type { PlatformCapability, PlatformFormat } from '@/lib/platforms';
import {
    capabilityOf,
    platformLabelOf,
    resolveFormat,
    usePlatforms,
} from '@/lib/platforms';
import { cn } from '@/lib/utils';

type Target = PreviewTarget & { id: number };

function TargetSwitcher({
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
function previewNotes(
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

function CaptionFoldMeter({
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

type PanelProps = {
    targets: Target[];
    caption: string;
    hashtags: string[];
    assets: AssetData[];
    musicName: string;
    locationName: string;
    mediaFormat: MediaFormat;
    crops: Record<number, Crop>;
    formatOptions?: MediaFormatOptions;
    /** Lets the user change formats and framing (they still save with the post). */
    editable: boolean;
    onMediaFormatChange?: (format: MediaFormat) => void;
    onCropsChange?: (crops: Record<number, Crop>) => void;
    /** Controls which channel shows (the editor's composer scope); omit to let the panel own it. */
    activeId?: number | null;
    onActiveChange?: (id: number) => void;
};

/** The live platform mockup in the editor's right rail, with framing tools and a fullscreen view. */
export function PreviewPanel({
    targets,
    caption,
    hashtags,
    assets,
    musicName,
    locationName,
    mediaFormat,
    crops,
    formatOptions = {},
    editable,
    onMediaFormatChange,
    onCropsChange,
    activeId: controlledId,
    onActiveChange,
}: PanelProps) {
    const [ownId, setOwnId] = useState<number | null>(targets[0]?.id ?? null);
    const activeId = controlledId ?? ownId;
    const setActiveId = (id: number) => {
        setOwnId(id);
        onActiveChange?.(id);
    };
    const [fullscreen, setFullscreen] = useState(false);
    const [framing, setFraming] = useState(false);
    const [safeZones, setSafeZones] = useState(false);
    const active =
        targets.find((target) => target.id === activeId) ?? targets[0] ?? null;

    const registry = usePlatforms();
    const platform = active?.platform ?? '';
    const capability = capabilityOf(registry, platform);
    const format = mediaFormat[platform] ?? 'original';
    const media = publishableMedia(assets);
    const hasVideo = media.some(isVideoAsset);
    const images = media.filter((asset) => asset.url && isImageAsset(asset));
    const spec = resolveFormat(
        capability,
        active?.format,
        media.map((asset) => (isVideoAsset(asset) ? 'video' : 'image')),
    );
    const canFrame =
        editable && !hasVideo && images.length > 0 && !spec?.crop_to;
    const vertical = Boolean(spec?.vertical) || format === '9:16';
    const platforms = [...new Set(targets.map((target) => target.platform))];
    const notes = active ? previewNotes(capability, spec, assets, format) : [];
    const published =
        active?.publishedCaption ??
        publishedCaption(
            active?.caption ?? caption,
            active?.hashtags ?? hashtags,
            capability,
            active?.link,
        );

    const setFormat = (value: string) =>
        onMediaFormatChange?.({ ...mediaFormat, [platform]: value });

    const controls = active &&
        !hasVideo &&
        images.length > 0 &&
        !spec?.crop_to && (
            <div className="grid gap-2">
                <div className="flex items-center justify-between gap-2">
                    <span className="eyebrow text-muted-foreground">
                        Format
                    </span>
                    {canFrame && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setFraming(true)}
                        >
                            <CropIcon />
                            Frame
                        </Button>
                    )}
                </div>
                {editable ? (
                    <FormatPicker
                        platform={platform}
                        value={format}
                        options={formatOptions}
                        onChange={setFormat}
                    />
                ) : (
                    <p className="text-sm">
                        {ratioLabels[format] ?? format}
                        {format !== 'original' && (
                            <span className="text-muted-foreground font-mono text-xs">
                                {' '}
                                {format}
                            </span>
                        )}
                    </p>
                )}
            </div>
        );

    const body = (size: 'rail' | 'full') =>
        active ? (
            <div className="grid gap-4">
                <TargetSwitcher
                    targets={targets}
                    activeId={active.id}
                    onSelect={setActiveId}
                />
                {controls}
                {notes.length > 0 && (
                    <ul className="grid gap-1.5">
                        {notes.map((note) => (
                            <li
                                key={note}
                                className="text-muted-foreground flex items-start gap-1.5 text-xs leading-snug"
                            >
                                <Info className="text-info mt-px size-3.5 shrink-0" />
                                {note}
                            </li>
                        ))}
                    </ul>
                )}
                <div
                    className={cn(
                        'mx-auto flex w-full justify-center',
                        size === 'full' && 'max-w-[420px]',
                    )}
                >
                    <SocialPostPreview
                        key={active.id}
                        target={active}
                        caption={caption}
                        hashtags={hashtags}
                        assets={assets}
                        musicName={musicName}
                        locationName={locationName}
                        mediaFormat={mediaFormat}
                        crops={crops}
                        showSafeZones={vertical && safeZones}
                    />
                </div>
                <div className="grid gap-2">
                    {vertical && (
                        <Toggle
                            size="sm"
                            variant="outline"
                            pressed={safeZones}
                            onPressedChange={setSafeZones}
                            className="justify-self-center px-3 text-xs"
                        >
                            <ScanLine />
                            Safe zones
                        </Toggle>
                    )}
                    <CaptionFoldMeter
                        capability={capability}
                        caption={published}
                    />
                    <p className="text-muted-foreground text-center text-[11px]">
                        A close approximation — apps vary by device.
                    </p>
                </div>
            </div>
        ) : (
            <p className="text-muted-foreground py-8 text-center text-sm">
                Pick a channel to see how the post will look.
            </p>
        );

    return (
        <section className="border-border bg-card rounded-2xl border">
            <div className="border-border/70 flex items-center justify-between gap-2 border-b px-5 py-3">
                <h2 className="flex items-center gap-2 text-[15px] font-semibold">
                    <Eye className="text-muted-foreground size-4" />
                    Preview
                </h2>
                {active && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        onClick={() => setFullscreen(true)}
                        aria-label="Open preview fullscreen"
                    >
                        <Maximize2 className="size-4" />
                    </Button>
                )}
            </div>
            <div className="p-4">{body('rail')}</div>

            <Dialog open={fullscreen} onOpenChange={setFullscreen}>
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            Preview
                            {active
                                ? ` — ${capability.label} · ${active.handle}`
                                : ''}
                        </DialogTitle>
                    </DialogHeader>
                    {body('full')}
                </DialogContent>
            </Dialog>

            {canFrame && (
                <CropEditorDialog
                    open={framing}
                    onOpenChange={setFraming}
                    assets={images}
                    crops={crops}
                    platforms={platforms}
                    activePlatform={platform}
                    mediaFormat={mediaFormat}
                    formatOptions={formatOptions}
                    onApply={(nextCrops, nextFormat) => {
                        onCropsChange?.({ ...crops, ...nextCrops });
                        onMediaFormatChange?.(nextFormat);
                    }}
                />
            )}
        </section>
    );
}
