import {
    Check,
    ChevronLeft,
    ChevronRight,
    Download,
    Sparkles,
    Wand2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { RefineForm } from '@/components/image-studio/image-tile';
import type { StudioVariant } from '@/components/image-studio/types';
import { SocialPostPreview } from '@/components/social-post-preview';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { download } from '@/routes/ai-image-variants';

const PREVIEWABLE = ['instagram', 'tiktok', 'facebook', 'linkedin'];

/**
 * Large preview of one tile beside a lightweight platform frame carrying the real caption, so the
 * team judges the image as it will appear. ←/→ move between tiles, Enter selects, R opens refine.
 */
export function ImageLightbox({
    variants,
    index,
    onIndexChange,
    onClose,
    selectedIds,
    canManage,
    busy,
    caption,
    platform,
    handle,
    onToggleSelect,
    onRefine,
    onFinalize,
}: {
    variants: StudioVariant[];
    index: number | null;
    onIndexChange: (index: number) => void;
    onClose: () => void;
    selectedIds: number[];
    canManage: boolean;
    busy: boolean;
    caption: string;
    platform: string;
    handle: string;
    onToggleSelect: (variant: StudioVariant) => void;
    onRefine: (variant: StudioVariant, instruction: string) => void;
    onFinalize: (variant: StudioVariant) => void;
}) {
    const [refining, setRefining] = useState(false);
    const variant = index !== null ? variants[index] : undefined;
    const selected = variant ? selectedIds.includes(variant.id) : false;

    useEffect(() => {
        setRefining(false);
    }, [index]);

    const step = (delta: number) => {
        if (index === null || variants.length === 0) {
            return;
        }

        onIndexChange((index + delta + variants.length) % variants.length);
    };

    const onKeyDown = (event: React.KeyboardEvent) => {
        if (refining || !variant) {
            return;
        }

        const target = event.target as HTMLElement;

        if (['INPUT', 'TEXTAREA'].includes(target.tagName)) {
            return;
        }

        if (event.key === 'ArrowRight') {
            event.preventDefault();
            step(1);
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            step(-1);
        } else if (
            event.key === 'Enter' &&
            canManage &&
            target.tagName !== 'BUTTON' &&
            target.tagName !== 'A'
        ) {
            event.preventDefault();
            onToggleSelect(variant);
        } else if (event.key.toLowerCase() === 'r' && canManage) {
            event.preventDefault();
            setRefining(true);
        }
    };

    const previewPlatform = PREVIEWABLE.includes(platform)
        ? platform
        : 'instagram';

    return (
        <Dialog
            open={variant !== undefined}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent
                className="max-h-[92vh] overflow-y-auto sm:max-w-5xl"
                onKeyDown={onKeyDown}
            >
                {variant && (
                    <div className="flex flex-col gap-5">
                        <div className="flex flex-col gap-1 pr-8">
                            <DialogTitle>
                                {variant.tier === 'final'
                                    ? 'Final image'
                                    : `Draft ${(index ?? 0) + 1} of ${variants.length}`}
                            </DialogTitle>
                            <DialogDescription>
                                ← → to browse
                                {canManage
                                    ? ' · Enter to select · R to refine'
                                    : ''}
                            </DialogDescription>
                        </div>

                        <div className="grid gap-6 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                            <div className="bg-muted relative flex items-center justify-center overflow-hidden rounded-xl">
                                {variant.url ? (
                                    <img
                                        src={variant.url}
                                        alt={variant.alt}
                                        className="max-h-[64vh] w-full object-contain"
                                    />
                                ) : null}
                                {variants.length > 1 && (
                                    <>
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            className="bg-card/90 absolute top-1/2 left-3 -translate-y-1/2"
                                            onClick={() => step(-1)}
                                            aria-label="Previous image"
                                        >
                                            <ChevronLeft />
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            className="bg-card/90 absolute top-1/2 right-3 -translate-y-1/2"
                                            onClick={() => step(1)}
                                            aria-label="Next image"
                                        >
                                            <ChevronRight />
                                        </Button>
                                    </>
                                )}
                            </div>

                            <div className="flex flex-col gap-4">
                                <span className="eyebrow text-muted-foreground">
                                    How it will look
                                </span>
                                <SocialPostPreview
                                    target={{
                                        id: 'studio',
                                        platform: previewPlatform,
                                        handle,
                                    }}
                                    caption={caption}
                                    hashtags={[]}
                                    assets={[
                                        {
                                            id: variant.assetId ?? variant.id,
                                            url: variant.url,
                                            original_filename: null,
                                            type: 'image',
                                        },
                                    ]}
                                />
                            </div>
                        </div>

                        {refining ? (
                            <RefineForm
                                busy={busy}
                                onCancel={() => setRefining(false)}
                                onSubmit={(instruction) => {
                                    onRefine(variant, instruction);
                                    setRefining(false);
                                }}
                            />
                        ) : (
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                <Button variant="ghost" asChild>
                                    <a href={download.url(variant.id)}>
                                        <Download />
                                        Download
                                    </a>
                                </Button>
                                {canManage && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setRefining(true)}
                                    >
                                        <Wand2 />
                                        Refine
                                    </Button>
                                )}
                                {canManage && variant.tier === 'draft' && (
                                    <Button
                                        variant="outline"
                                        onClick={() => onFinalize(variant)}
                                        disabled={busy}
                                    >
                                        <Sparkles />
                                        Make final
                                    </Button>
                                )}
                                {canManage && (
                                    <Button
                                        variant="secondary"
                                        onClick={() => onToggleSelect(variant)}
                                        aria-pressed={selected}
                                    >
                                        <Check />
                                        {selected ? 'Selected' : 'Select'}
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
