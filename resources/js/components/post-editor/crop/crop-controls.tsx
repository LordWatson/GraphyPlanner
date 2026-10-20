import type { PreviewAsset } from '@/components/asset-preview-carousel';
import { MAX_ZOOM, MIN_ZOOM } from '@/lib/media-format';
import { cn } from '@/lib/utils';

/** The framing dialog's zoom control for the image being framed. */
export function ZoomSlider({
    zoom,
    onChange,
}: {
    zoom: number;
    onChange: (zoom: number) => void;
}) {
    return (
        <div className="flex items-center gap-3">
            <label htmlFor="crop-zoom" className="text-sm font-medium">
                Zoom
            </label>
            <input
                id="crop-zoom"
                type="range"
                min={MIN_ZOOM}
                max={MAX_ZOOM}
                step={0.05}
                value={zoom}
                onChange={(event) => onChange(Number(event.target.value))}
                className="accent-primary h-2 flex-1 cursor-pointer"
            />
            <span className="text-muted-foreground w-12 text-right font-mono text-xs tabular-nums">
                {zoom.toFixed(2)}×
            </span>
        </div>
    );
}

/** Numbered thumbnails to switch which image is being framed (shown for 2+ images). */
export function ImageStrip({
    images,
    activeId,
    onSelect,
}: {
    images: PreviewAsset[];
    activeId: number;
    onSelect: (id: number) => void;
}) {
    if (images.length < 2) {
        return null;
    }

    return (
        <div className="flex gap-2 overflow-x-auto pb-1">
            {images.map((image, index) => (
                <button
                    key={image.id}
                    type="button"
                    onClick={() => onSelect(image.id)}
                    aria-label={`Frame image ${index + 1}`}
                    aria-pressed={image.id === activeId}
                    className={cn(
                        'bg-muted relative size-14 shrink-0 overflow-hidden rounded-lg border-2 transition-colors',
                        image.id === activeId
                            ? 'border-primary'
                            : 'hover:border-foreground/25 border-transparent',
                    )}
                >
                    <img
                        src={image.url ?? undefined}
                        alt=""
                        className="size-full object-cover"
                    />
                    <span className="bg-primary text-primary-foreground absolute top-0.5 left-0.5 flex size-4 items-center justify-center rounded-full font-mono text-[9px]">
                        {index + 1}
                    </span>
                </button>
            ))}
        </div>
    );
}
