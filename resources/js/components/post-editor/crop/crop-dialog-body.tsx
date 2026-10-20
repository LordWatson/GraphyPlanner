import {
    ImageStrip,
    ZoomSlider,
} from '@/components/post-editor/crop/crop-controls';
import {
    FormatPicker,
    usePlatformNames,
} from '@/components/post-editor/crop/format-picker';
import { FramingStage } from '@/components/post-editor/crop/framing-stage';
import { RotateCcw } from 'lucide-react';
import { useState } from 'react';
import type { PreviewAsset } from '@/components/asset-preview-carousel';
import { platformIcon } from '@/components/platform-icon';
import {
    cropFor,
    FramedMedia,
    useMediaAspects,
} from '@/components/social-preview/media';
import { Button } from '@/components/ui/button';
import {
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Crop, MediaFormat, MediaFormatOptions } from '@/lib/media-format';
import {
    DEFAULT_CROP,
    describeRatio,
    frameFor,
    normalizeCrop,
} from '@/lib/media-format';
import { cn } from '@/lib/utils';

export function DialogBody({
    images,
    initialCrops,
    platforms,
    initialPlatform,
    initialFormat,
    formatOptions,
    onApply,
    onCancel,
}: {
    images: PreviewAsset[];
    initialCrops: Record<number, Crop>;
    platforms: string[];
    initialPlatform: string;
    initialFormat: MediaFormat;
    formatOptions: MediaFormatOptions;
    onApply: (crops: Record<number, Crop>, format: MediaFormat) => void;
    onCancel: () => void;
}) {
    const platformNames = usePlatformNames();
    const [platform, setPlatform] = useState(initialPlatform);
    const [format, setFormat] = useState<MediaFormat>(initialFormat);
    const [crops, setCrops] = useState<Record<number, Crop>>(() =>
        Object.fromEntries(
            images.map((image) => [image.id, cropFor(image, initialCrops)]),
        ),
    );
    const [activeId, setActiveId] = useState(images[0]?.id);
    const aspects = useMediaAspects();

    const active = images.find((image) => image.id === activeId) ?? images[0];
    const crop = crops[active.id] ?? DEFAULT_CROP;
    // A carousel's frame follows its first image, exactly as it publishes.
    const firstAspect = aspects.aspectOf(images[0]);
    const frameOn = (channel: string) =>
        frameFor(channel, format[channel], firstAspect, false);
    const frame = frameOn(platform);
    const stageRatio = frame.focus && frame.fit === 'crop' ? frame.ratio : null;
    const name = platformNames[platform] ?? platform;

    return (
        <>
            <DialogHeader>
                <DialogTitle>Frame your media.</DialogTitle>
                <DialogDescription>
                    Drag the box to choose what stays in shot. One focus point
                    per image carries across every channel.
                </DialogDescription>
            </DialogHeader>

            <div className="flex flex-wrap items-center justify-between gap-3">
                {platforms.length > 1 ? (
                    <div
                        role="tablist"
                        aria-label="Channel"
                        className="bg-muted flex max-w-full gap-1 overflow-x-auto rounded-full p-1"
                    >
                        {platforms.map((channel) => {
                            const Icon = platformIcon(channel);
                            const selected = channel === platform;

                            return (
                                <button
                                    key={channel}
                                    type="button"
                                    role="tab"
                                    aria-selected={selected}
                                    onClick={() => setPlatform(channel)}
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs whitespace-nowrap transition-colors',
                                        selected
                                            ? 'bg-card text-foreground shadow-xs'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    <Icon className="size-3.5" />
                                    {platformNames[channel] ?? channel}
                                </button>
                            );
                        })}
                    </div>
                ) : (
                    <span className="eyebrow text-muted-foreground">
                        {name}
                    </span>
                )}
                <FormatPicker
                    platform={platform}
                    value={format[platform] ?? 'original'}
                    options={formatOptions}
                    onChange={(value) =>
                        setFormat((current) => ({
                            ...current,
                            [platform]: value,
                        }))
                    }
                />
            </div>

            <div className="grid min-w-0 gap-5 lg:grid-cols-[minmax(0,1fr)_200px]">
                <div className="grid min-w-0 content-start gap-3">
                    <FramingStage
                        asset={active}
                        ratio={stageRatio}
                        crop={crop}
                        aspect={aspects.aspectOf(active)}
                        onMeasure={aspects.measure}
                        onChange={(next) =>
                            setCrops((current) => ({
                                ...current,
                                [active.id]: next,
                            }))
                        }
                    />

                    {stageRatio === null && (
                        <p className="text-muted-foreground text-xs">
                            {platform === 'tiktok'
                                ? 'Original plays on black bars in TikTok photo mode — choose 9:16 to fill the screen.'
                                : `${name} shows the original and crops the feed thumbnail itself. Pick a format to frame it yourself.`}{' '}
                            The focus point still applies to channels that crop.
                        </p>
                    )}

                    <ZoomSlider
                        zoom={crop.zoom}
                        onChange={(zoom) =>
                            setCrops((current) => ({
                                ...current,
                                [active.id]: normalizeCrop({ ...crop, zoom }),
                            }))
                        }
                    />

                    <ImageStrip
                        images={images}
                        activeId={active.id}
                        onSelect={setActiveId}
                    />
                </div>

                <div className="grid content-start gap-3">
                    <span className="eyebrow text-muted-foreground">
                        On every channel
                    </span>
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-1">
                        {platforms.map((channel) => {
                            const Icon = platformIcon(channel);
                            const channelFrame = frameOn(channel);

                            return (
                                <button
                                    key={channel}
                                    type="button"
                                    onClick={() => setPlatform(channel)}
                                    className={cn(
                                        'grid gap-1.5 rounded-xl border p-2 text-left transition-colors',
                                        channel === platform
                                            ? 'border-primary'
                                            : 'border-border hover:border-foreground/25',
                                    )}
                                >
                                    <span className="flex items-center justify-between gap-2 text-xs">
                                        <span className="flex items-center gap-1.5 font-medium">
                                            <Icon className="size-3.5" />
                                            {platformNames[channel] ?? channel}
                                        </span>
                                        <span className="text-muted-foreground font-mono text-[11px]">
                                            {channelFrame.fit === 'contain'
                                                ? 'Fit'
                                                : describeRatio(
                                                      channelFrame.ratio,
                                                  )}
                                        </span>
                                    </span>
                                    <div
                                        className={cn(
                                            'mx-auto w-full overflow-hidden rounded-md',
                                            channelFrame.ratio < 1 &&
                                                'max-w-[120px]',
                                        )}
                                    >
                                        <FramedMedia
                                            asset={active}
                                            frame={channelFrame}
                                            crop={crop}
                                            aspect={aspects.aspectOf(active)}
                                            onMeasure={aspects.measure}
                                        />
                                    </div>
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>

            <DialogFooter className="items-center gap-2 sm:justify-between">
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() =>
                        setCrops((current) => ({
                            ...current,
                            [active.id]: DEFAULT_CROP,
                        }))
                    }
                >
                    <RotateCcw />
                    Reset this image
                </Button>
                <span className="flex gap-2">
                    <Button type="button" variant="outline" onClick={onCancel}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={() => onApply(crops, format)}
                    >
                        Apply framing
                    </Button>
                </span>
            </DialogFooter>
        </>
    );
}
