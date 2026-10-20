import { Crop as CropIcon, Eye, Info, Maximize2, ScanLine } from 'lucide-react';
import { useState } from 'react';
import {
    CropEditorDialog,
    FormatPicker,
} from '@/components/post-editor/crop-editor-dialog';
import type { AssetData } from '@/components/post-editor/types';
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
import { publishedCaption, ratioLabels } from '@/lib/media-format';
import { capabilityOf, resolveFormat, usePlatforms } from '@/lib/platforms';
import { cn } from '@/lib/utils';
import type { Target } from '@/components/post-editor/preview/preview-parts';
import {
    TargetSwitcher,
    previewNotes,
    CaptionFoldMeter,
} from '@/components/post-editor/preview/preview-parts';

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
