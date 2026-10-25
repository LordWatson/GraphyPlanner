import { ChevronDown, Loader2, Sparkles } from 'lucide-react';
import { AssetThumbnail } from '@/components/post-editor/asset-thumbnail';
import type { AssetData } from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const NO_COVER = '__none';

function isVideo(asset: AssetData): boolean {
    return (asset.type ?? '').startsWith('video');
}

function isImage(asset: AssetData): boolean {
    return (asset.type ?? '').startsWith('image');
}

/**
 * Steps M-2.2 / M-2.3 — the extras attached media can carry: alt text for each image (sent to
 * every channel that takes it), and for a video post its cover image and the frame its thumbnail
 * comes from. Saved with the post through hidden fields.
 */
export function MediaDetails({
    assets,
    altTexts,
    onAltTextChange,
    coverAssetId,
    onCoverChange,
    thumbOffsetMs,
    onThumbOffsetChange,
    altTextPlatforms,
    onSuggestAltText,
    suggestingAltTextFor,
}: {
    assets: AssetData[];
    altTexts: Record<number, string>;
    onAltTextChange: (assetId: number, text: string) => void;
    coverAssetId: number | null;
    onCoverChange: (assetId: number | null) => void;
    thumbOffsetMs: number | null;
    onThumbOffsetChange: (ms: number | null) => void;
    /** Labels of the selected channels that publish alt text. */
    altTextPlatforms: string[];
    /** Step M-7.3: when set, show an AI "suggest alt text" button per image. */
    onSuggestAltText?: (assetId: number) => void;
    /** The image id currently having alt text generated, if any. */
    suggestingAltTextFor?: number | null;
}) {
    const images = assets.filter(isImage);
    const video = assets.find(isVideo);
    // Alt text is rarely filled in, so the section starts collapsed unless there's already some.
    const describedCount = images.filter(
        (image) => (altTexts[image.id] ?? '').trim() !== '',
    ).length;

    if (images.length === 0 && !video) {
        return null;
    }

    return (
        <div className="border-border/70 grid gap-5 border-t pt-5">
            {video && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid content-start gap-2">
                        <Label htmlFor="cover_asset">Video cover</Label>
                        <Select
                            value={
                                coverAssetId ? String(coverAssetId) : NO_COVER
                            }
                            onValueChange={(value) =>
                                onCoverChange(
                                    value === NO_COVER ? null : Number(value),
                                )
                            }
                        >
                            <SelectTrigger id="cover_asset">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NO_COVER}>
                                    A frame from the video
                                </SelectItem>
                                {images.map((image) => (
                                    <SelectItem
                                        key={image.id}
                                        value={String(image.id)}
                                    >
                                        {image.original_filename ??
                                            `Image ${image.id}`}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-muted-foreground text-xs">
                            {images.length > 0
                                ? 'Only the video publishes — an attached image can be its cover.'
                                : 'Attach an image to use it as the cover.'}
                        </p>
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="thumb_offset">Thumbnail frame</Label>
                        <div className="flex items-center gap-2">
                            <Input
                                id="thumb_offset"
                                type="number"
                                min={0}
                                step={0.5}
                                inputMode="decimal"
                                value={
                                    thumbOffsetMs === null
                                        ? ''
                                        : String(thumbOffsetMs / 1000)
                                }
                                onChange={(e) =>
                                    onThumbOffsetChange(
                                        e.target.value === ''
                                            ? null
                                            : Math.max(
                                                  0,
                                                  Math.round(
                                                      Number(e.target.value) *
                                                          1000,
                                                  ),
                                              ),
                                    )
                                }
                                placeholder="0"
                                className="w-28 font-mono"
                            />
                            <span className="text-muted-foreground text-sm">
                                seconds in
                            </span>
                        </div>
                        <p className="text-muted-foreground text-xs">
                            Used where the network picks a frame (Instagram,
                            TikTok) and no cover is set.
                        </p>
                    </div>
                </div>
            )}

            {images.length > 0 && !video && (
                <Collapsible
                    defaultOpen={describedCount > 0}
                    className="grid gap-3"
                >
                    <CollapsibleTrigger className="group focus-visible:ring-ring/50 -mx-1 flex items-center justify-between gap-3 rounded-lg px-1 py-1 text-start outline-none focus-visible:ring-[3px]">
                        <span className="flex items-center gap-2">
                            <span className="text-sm font-medium">
                                Alt text
                            </span>
                            <span className="text-muted-foreground font-mono text-xs">
                                {describedCount > 0
                                    ? `${describedCount} of ${images.length} described`
                                    : 'optional'}
                            </span>
                        </span>
                        <ChevronDown className="text-muted-foreground size-4 shrink-0 transition-transform group-data-[state=open]:rotate-180" />
                    </CollapsibleTrigger>
                    <CollapsibleContent className="grid gap-3">
                        <p className="text-muted-foreground text-xs">
                            {altTextPlatforms.length > 0
                                ? `Describes each image for screen readers on ${altTextPlatforms.join(', ')}.`
                                : 'None of the selected channels publish alt text.'}
                        </p>
                        <ul className="grid gap-2.5">
                            {images.map((image, index) => (
                                <li
                                    key={image.id}
                                    className="flex items-center gap-3"
                                >
                                    <span className="border-border bg-muted relative size-12 shrink-0 overflow-hidden rounded-lg border">
                                        <AssetThumbnail asset={image} />
                                    </span>
                                    <Input
                                        aria-label={`Alt text for image ${index + 1}`}
                                        dir="auto"
                                        maxLength={1000}
                                        value={altTexts[image.id] ?? ''}
                                        onChange={(e) =>
                                            onAltTextChange(
                                                image.id,
                                                e.target.value,
                                            )
                                        }
                                        placeholder="e.g. A flat white on a marble counter, morning light"
                                    />
                                    {onSuggestAltText && (
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            className="size-9 shrink-0"
                                            aria-label={`Suggest alt text for image ${index + 1} with AI`}
                                            disabled={
                                                suggestingAltTextFor ===
                                                image.id
                                            }
                                            onClick={() =>
                                                onSuggestAltText(image.id)
                                            }
                                        >
                                            {suggestingAltTextFor ===
                                            image.id ? (
                                                <Loader2 className="animate-spin" />
                                            ) : (
                                                <Sparkles />
                                            )}
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </CollapsibleContent>
                </Collapsible>
            )}
        </div>
    );
}
