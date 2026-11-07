import { GripVertical, X } from 'lucide-react';
import { useState } from 'react';
import { AssetLibraryDialog } from '@/components/post-editor/asset-library-dialog';
import { AssetThumbnail } from '@/components/post-editor/asset-thumbnail';
import {
    BrowseLibraryTile,
    UploadTile,
} from '@/components/post-editor/media-tiles';
import type { AssetData } from '@/components/post-editor/types';
import {
    nameOf,
    useMediaReorder,
} from '@/components/post-editor/use-media-reorder';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/**
 * The post's attached media, in publish order, plus two tiles: upload a new file (click or drop)
 * straight into the client's library, or browse the existing library in a searchable, paged
 * dialog. Only attached assets render here, so a large library never crowds the editor.
 *
 * The attached tiles reorder like the grid board, by mouse or by keyboard — see
 * {@link useMediaReorder} for that interaction. The number badge is the publish order and
 * updates live.
 */
export function MediaPicker({
    clientId,
    selected,
    onChange,
    canUpload,
    uploading,
    onUpload,
    campaignId,
}: {
    clientId: number;
    /** Step C-4.1: the library opens on this campaign's kit first. */
    campaignId?: number;
    selected: AssetData[];
    onChange: (assets: AssetData[]) => void;
    canUpload: boolean;
    uploading: boolean;
    onUpload: (file: File) => void;
}) {
    const [browsing, setBrowsing] = useState(false);
    const {
        order,
        announcement,
        reorderable,
        dragIndex,
        overIndex,
        lifted,
        setDragIndex,
        setOverIndex,
        onDrop,
        handleKeyDown,
        setHandleRef,
    } = useMediaReorder(selected, onChange);

    return (
        <div className="grid gap-3">
            <div aria-live="assertive" className="sr-only">
                {announcement}
            </div>
            <ul className="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-5">
                {order.map((asset, index) => (
                    <li
                        key={asset.id}
                        // Only a mouse drag sets `draggable`; during a keyboard lift it's off so the
                        // two interactions can't fight over the index.
                        draggable={reorderable && lifted === null}
                        onDragStart={(event) => {
                            event.dataTransfer.effectAllowed = 'move';
                            setDragIndex(index);
                        }}
                        onDragEnd={() => {
                            setDragIndex(null);
                            setOverIndex(null);
                        }}
                        onDragOver={(event) => {
                            if (dragIndex !== null) {
                                event.preventDefault();
                                setOverIndex(index);
                            }
                        }}
                        onDragLeave={() =>
                            setOverIndex((current) =>
                                current === index ? null : current,
                            )
                        }
                        onDrop={(event) => {
                            event.preventDefault();
                            onDrop(index);
                        }}
                        className={cn(
                            'group bg-muted border-border relative aspect-square overflow-hidden rounded-xl border',
                            reorderable && 'cursor-grab active:cursor-grabbing',
                            overIndex === index &&
                                dragIndex !== index &&
                                'ring-primary z-10 ring-2',
                            dragIndex === index && 'opacity-50',
                            lifted?.id === asset.id &&
                                'ring-electric z-20 ring-2',
                        )}
                        title={asset.original_filename ?? undefined}
                    >
                        <AssetThumbnail asset={asset} />
                        <span className="bg-primary text-primary-foreground absolute top-1.5 left-1.5 flex size-5 items-center justify-center rounded-full font-mono text-[11px]">
                            {index + 1}
                        </span>
                        {asset.is_ai_generated && (
                            <Badge
                                variant="lilac"
                                className="absolute bottom-1.5 left-1.5"
                                title="Made in the AI Image Studio"
                            >
                                AI
                            </Badge>
                        )}
                        <button
                            type="button"
                            onClick={() =>
                                onChange(
                                    selected.filter((a) => a.id !== asset.id),
                                )
                            }
                            aria-label={`Remove ${asset.original_filename ?? 'asset'}`}
                            className="bg-card/90 border-border text-foreground hover:bg-card focus-visible:ring-ring/40 absolute top-1.5 right-1.5 flex size-6 items-center justify-center rounded-full border opacity-0 transition outline-none group-hover:opacity-100 focus-visible:opacity-100 focus-visible:ring-[3px] [@media(hover:none)]:opacity-100"
                        >
                            <X className="size-3.5" />
                        </button>
                        {reorderable && (
                            <button
                                type="button"
                                ref={(element) =>
                                    setHandleRef(asset.id, element)
                                }
                                onKeyDown={(event) =>
                                    handleKeyDown(event, asset)
                                }
                                aria-label={
                                    lifted?.id === asset.id
                                        ? `Moving ${nameOf(asset)}, position ${index + 1} of ${order.length}. Arrow keys to move, space to drop, escape to cancel.`
                                        : `Reorder ${nameOf(asset)}, position ${index + 1} of ${order.length}. Press space to lift, then the arrow keys.`
                                }
                                className="bg-card/90 border-border text-muted-foreground hover:bg-card hover:text-foreground focus-visible:ring-ring/40 absolute right-1.5 bottom-1.5 flex size-6 cursor-grab items-center justify-center rounded-full border opacity-0 transition outline-none group-hover:opacity-100 focus-visible:opacity-100 focus-visible:ring-[3px] active:cursor-grabbing [@media(hover:none)]:opacity-100"
                            >
                                <GripVertical className="size-3.5" />
                            </button>
                        )}
                    </li>
                ))}

                {canUpload && (
                    <li>
                        <UploadTile uploading={uploading} onUpload={onUpload} />
                    </li>
                )}

                <li>
                    <BrowseLibraryTile onClick={() => setBrowsing(true)} />
                </li>
            </ul>

            {selected.length === 0 ? (
                <p className="text-muted-foreground text-xs">
                    No media attached yet.
                </p>
            ) : (
                reorderable && (
                    <p className="text-muted-foreground text-xs">
                        Drag a tile to reorder, or focus its grip handle and
                        press space, then the arrow keys. The number is the
                        order it publishes in.
                    </p>
                )
            )}

            {selected.map((asset, i) => (
                <input
                    key={asset.id}
                    type="hidden"
                    name={`asset_ids[${i}]`}
                    value={asset.id}
                />
            ))}
            {/* With nothing attached, still send the field so saving detaches the last item. */}
            {selected.length === 0 && (
                <input type="hidden" name="asset_ids" value="" />
            )}

            <AssetLibraryDialog
                open={browsing}
                onOpenChange={setBrowsing}
                clientId={clientId}
                campaignId={campaignId}
                selected={selected}
                onConfirm={onChange}
            />
        </div>
    );
}
