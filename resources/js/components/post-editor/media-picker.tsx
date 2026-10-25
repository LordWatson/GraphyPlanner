import { GripVertical, ImagePlus, LibraryBig, Loader2, X } from 'lucide-react';
import type { KeyboardEvent } from 'react';
import { useRef, useState } from 'react';
import { AssetLibraryDialog } from '@/components/post-editor/asset-library-dialog';
import { AssetThumbnail } from '@/components/post-editor/asset-thumbnail';
import type { AssetData } from '@/components/post-editor/types';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const tileClass =
    'border-border text-muted-foreground hover:border-foreground/30 hover:text-foreground focus-visible:ring-ring/40 flex aspect-square flex-col items-center justify-center gap-1.5 rounded-xl border border-dashed px-2 text-center text-xs transition-colors outline-none focus-visible:ring-[3px]';

const acceptedFileTypes = 'image/*,video/*,.pdf,.doc,.docx,.ppt,.pptx,.zip';

/**
 * Dashed "Upload or drop a file" tile with its own hidden file input. Shared by the post
 * editor's `MediaPicker` and the client Assets tab.
 */
export function UploadTile({
    uploading,
    onUpload,
}: {
    uploading: boolean;
    onUpload: (file: File) => void;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);

    return (
        <>
            <button
                type="button"
                disabled={uploading}
                onClick={() => inputRef.current?.click()}
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDragging(false);
                    const file = e.dataTransfer.files?.[0];

                    if (file) {
                        onUpload(file);
                    }
                }}
                className={cn(
                    tileClass,
                    'w-full',
                    dragging && 'border-primary text-primary bg-accent',
                )}
            >
                {uploading ? (
                    <Loader2 className="size-5 animate-spin" />
                ) : (
                    <ImagePlus className="size-5" />
                )}
                <span className="text-foreground font-medium">
                    {uploading ? 'Uploading…' : 'Upload'}
                </span>
                {!uploading && <span>or drop a file</span>}
            </button>
            <input
                ref={inputRef}
                type="file"
                className="hidden"
                accept={acceptedFileTypes}
                onChange={(e) => {
                    const file = e.target.files?.[0];

                    if (file) {
                        onUpload(file);
                    }

                    e.target.value = '';
                }}
            />
        </>
    );
}

/** Dashed "Browse library" tile that opens the asset library dialog. */
export function BrowseLibraryTile({ onClick }: { onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(tileClass, 'w-full')}
        >
            <LibraryBig className="size-5" />
            <span className="text-foreground font-medium">Browse library</span>
            <span>Search existing assets</span>
        </button>
    );
}

function moveItem(list: AssetData[], from: number, to: number): AssetData[] {
    const next = [...list];
    const [moved] = next.splice(from, 1);
    next.splice(Math.max(0, Math.min(next.length, to)), 0, moved);

    return next;
}

const orderOf = (assets: AssetData[]) => assets.map((a) => a.id).join(',');
const nameOf = (asset: AssetData) => asset.original_filename ?? 'this item';

/**
 * The post's attached media, in publish order, plus two tiles: upload a new file (click or drop)
 * straight into the client's library, or browse the existing library in a searchable, paged
 * dialog. Only attached assets render here, so a large library never crowds the editor.
 *
 * The attached tiles reorder like the grid board: drag one onto another (mouse), or focus its grip
 * handle and press space to lift it, the arrow keys to move it, space to drop (escape cancels) —
 * announced to screen readers. The number badge is the publish order and updates live.
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
    const [dragIndex, setDragIndex] = useState<number | null>(null);
    const [overIndex, setOverIndex] = useState<number | null>(null);
    // A keyboard drag stages its order here until dropped, so Escape can cancel it cleanly.
    const [lifted, setLifted] = useState<{
        id: number;
        order: AssetData[];
    } | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const handleRefs = useRef(new Map<number, HTMLButtonElement>());

    // While a keyboard drag is in flight the staged order is shown; otherwise the saved order.
    const order = lifted?.order ?? selected;

    const commit = (next: AssetData[]) => {
        if (orderOf(next) !== orderOf(selected)) {
            onChange(next);
        }
    };

    const onDrop = (to: number) => {
        if (dragIndex === null) {
            return;
        }

        if (dragIndex !== to) {
            commit(moveItem(selected, dragIndex, to));
            setAnnouncement(
                `Moved to position ${to + 1} of ${selected.length}.`,
            );
        }

        setDragIndex(null);
        setOverIndex(null);
    };

    const handleKeyDown = (
        event: KeyboardEvent<HTMLButtonElement>,
        asset: AssetData,
    ) => {
        if (event.key === ' ' || event.key === 'Enter') {
            event.preventDefault();

            if (!lifted) {
                setLifted({ id: asset.id, order: selected });
                setAnnouncement(
                    `${nameOf(asset)} lifted. Use the arrow keys to move it, space to drop, escape to cancel.`,
                );

                return;
            }

            const position =
                lifted.order.findIndex((a) => a.id === lifted.id) + 1;
            commit(lifted.order);
            setLifted(null);
            setAnnouncement(`Dropped at position ${position}.`);

            return;
        }

        if (lifted && event.key === 'Escape') {
            event.preventDefault();
            setLifted(null);
            setAnnouncement('Move cancelled.');

            return;
        }

        if (
            lifted &&
            ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(
                event.key,
            )
        ) {
            event.preventDefault();
            const from = lifted.order.findIndex((a) => a.id === lifted.id);
            const step =
                event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 1;
            const to = from + step;

            if (to < 0 || to >= lifted.order.length) {
                return;
            }

            const nextOrder = moveItem(lifted.order, from, to);
            setLifted({ id: lifted.id, order: nextOrder });
            setAnnouncement(
                `Moved to position ${to + 1} of ${nextOrder.length}.`,
            );
            requestAnimationFrame(() =>
                handleRefs.current.get(asset.id)?.focus(),
            );
        }
    };

    const reorderable = selected.length > 1;

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
                                ref={(element) => {
                                    if (element) {
                                        handleRefs.current.set(
                                            asset.id,
                                            element,
                                        );
                                    } else {
                                        handleRefs.current.delete(asset.id);
                                    }
                                }}
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
