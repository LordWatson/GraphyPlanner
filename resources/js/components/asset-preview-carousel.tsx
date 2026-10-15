import { ChevronLeft, ChevronRight, FileQuestion } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export type PreviewAsset = {
    id: number;
    url: string | null;
    original_filename: string | null;
    type: string | null;
};

function isVideoAsset(asset: PreviewAsset): boolean {
    if (asset.type) {
        return asset.type.startsWith('video');
    }

    return /\.(mp4|mov|webm|m4v)$/i.test(asset.url ?? '');
}

/**
 * Shows a stacked preview of the assets currently selected for a post. A single asset renders as
 * one large preview; multiple assets render as a small carousel (one at a time, prev/next
 * controls) plus a thumbnail strip so the user can jump directly to an asset.
 */
export function AssetPreviewCarousel({
    assets,
    className,
}: {
    assets: PreviewAsset[];
    className?: string;
}) {
    const [index, setIndex] = useState(0);

    if (assets.length === 0) {
        return null;
    }

    const activeIndex = Math.min(index, assets.length - 1);
    const active = assets[activeIndex];

    const goTo = (next: number) => {
        setIndex((next + assets.length) % assets.length);
    };

    return (
        <div className={cn('grid gap-2', className)}>
            <div className="border-border bg-muted/30 relative flex items-center justify-center overflow-hidden rounded-lg border">
                <AssetPreviewMedia
                    asset={active}
                    className="max-h-72 w-full object-contain"
                />

                {assets.length > 1 && (
                    <>
                        <Button
                            type="button"
                            variant="secondary"
                            size="icon"
                            className="absolute top-1/2 left-2 -translate-y-1/2 opacity-90"
                            onClick={() => goTo(activeIndex - 1)}
                        >
                            <ChevronLeft />
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            size="icon"
                            className="absolute top-1/2 right-2 -translate-y-1/2 opacity-90"
                            onClick={() => goTo(activeIndex + 1)}
                        >
                            <ChevronRight />
                        </Button>
                        <span className="bg-background/80 text-muted-foreground absolute right-2 bottom-2 rounded-full px-2 py-0.5 text-xs">
                            {activeIndex + 1} / {assets.length}
                        </span>
                    </>
                )}
            </div>

            {active.original_filename && (
                <p className="text-muted-foreground truncate text-xs">
                    {active.original_filename}
                </p>
            )}

            {assets.length > 1 && (
                <div className="flex flex-wrap gap-2">
                    {assets.map((asset, i) => (
                        <button
                            key={asset.id}
                            type="button"
                            onClick={() => setIndex(i)}
                            className={cn(
                                'size-14 shrink-0 overflow-hidden rounded-md border transition-colors',
                                i === activeIndex
                                    ? 'border-primary ring-primary/30 ring-2'
                                    : 'border-border hover:border-primary/40',
                            )}
                        >
                            <AssetPreviewMedia
                                asset={asset}
                                className="size-full object-cover"
                                muted
                            />
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function AssetPreviewMedia({
    asset,
    className,
    muted,
}: {
    asset: PreviewAsset;
    className?: string;
    muted?: boolean;
}) {
    if (!asset.url) {
        return (
            <div
                className={cn(
                    'bg-muted/50 text-muted-foreground flex h-24 items-center justify-center',
                    className,
                )}
            >
                <FileQuestion className="size-6" />
            </div>
        );
    }

    if (isVideoAsset(asset)) {
        return (
            <video
                src={asset.url}
                className={className}
                controls={!muted}
                muted={muted}
                playsInline
            />
        );
    }

    return (
        <img
            src={asset.url}
            alt={asset.original_filename ?? 'Asset preview'}
            className={className}
        />
    );
}
