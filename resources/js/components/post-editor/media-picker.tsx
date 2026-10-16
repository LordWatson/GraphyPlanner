import { Check, FileText, Film, ImagePlus, Loader2 } from 'lucide-react';
import { useRef, useState } from 'react';
import type { AssetData } from '@/components/post-editor/types';
import { cn } from '@/lib/utils';

function isVideo(asset: AssetData): boolean {
    if (asset.type) {
        return asset.type.startsWith('video');
    }

    return /\.(mp4|mov|webm|m4v)$/i.test(asset.url ?? '');
}

function isImage(asset: AssetData): boolean {
    if (asset.type) {
        return asset.type.startsWith('image');
    }

    return /\.(png|jpe?g|gif|webp|avif|svg)$/i.test(asset.url ?? '');
}

function Thumbnail({ asset }: { asset: AssetData }) {
    if (asset.url && isImage(asset)) {
        return (
            <img
                src={asset.url}
                alt={asset.original_filename ?? ''}
                loading="lazy"
                className="size-full object-cover"
            />
        );
    }

    if (asset.url && isVideo(asset)) {
        return (
            <>
                <video
                    src={asset.url}
                    muted
                    preload="metadata"
                    className="size-full object-cover"
                />
                <Film className="text-ink-foreground absolute bottom-2 left-2 size-4 drop-shadow" />
            </>
        );
    }

    return (
        <span className="text-muted-foreground flex size-full flex-col items-center justify-center gap-1.5 p-2">
            <FileText className="size-6" />
            <span className="line-clamp-2 text-center text-[11px] break-all">
                {asset.original_filename ?? `Asset #${asset.id}`}
            </span>
        </span>
    );
}

/**
 * The client's asset library as a thumbnail grid. Click to attach/detach; the number badge is the
 * order the media will appear in the post. The first tile uploads (click or drop a file) straight
 * into the client's library without leaving the editor.
 */
export function MediaPicker({
    assets,
    selectedIds,
    onToggle,
    canUpload,
    uploading,
    onUpload,
}: {
    assets: AssetData[];
    selectedIds: number[];
    onToggle: (assetId: number) => void;
    canUpload: boolean;
    uploading: boolean;
    onUpload: (file: File) => void;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);

    return (
        <div className="grid gap-3">
            <div className="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-5">
                {canUpload && (
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
                            'border-border text-muted-foreground hover:border-foreground/30 hover:text-foreground focus-visible:ring-ring/40 flex aspect-square flex-col items-center justify-center gap-1.5 rounded-xl border border-dashed text-xs transition-colors outline-none focus-visible:ring-[3px]',
                            dragging && 'border-primary text-primary bg-accent',
                        )}
                    >
                        {uploading ? (
                            <Loader2 className="size-5 animate-spin" />
                        ) : (
                            <ImagePlus className="size-5" />
                        )}
                        {uploading ? 'Uploading…' : 'Upload or drop'}
                    </button>
                )}
                {assets.map((asset) => {
                    const order = selectedIds.indexOf(asset.id);
                    const selected = order !== -1;

                    return (
                        <button
                            key={asset.id}
                            type="button"
                            onClick={() => onToggle(asset.id)}
                            aria-pressed={selected}
                            title={asset.original_filename ?? undefined}
                            className={cn(
                                'bg-muted focus-visible:ring-ring/40 relative aspect-square overflow-hidden rounded-xl border transition outline-none focus-visible:ring-[3px]',
                                selected
                                    ? 'border-primary ring-primary ring-2'
                                    : 'border-border hover:border-foreground/30',
                            )}
                        >
                            <Thumbnail asset={asset} />
                            <span
                                className={cn(
                                    'absolute top-1.5 right-1.5 flex size-5 items-center justify-center rounded-full border font-mono text-[11px] transition',
                                    selected
                                        ? 'bg-primary text-primary-foreground border-transparent'
                                        : 'bg-card/80 border-border text-transparent',
                                )}
                            >
                                {selected ? (
                                    order + 1
                                ) : (
                                    <Check className="size-3" />
                                )}
                            </span>
                        </button>
                    );
                })}
            </div>

            {assets.length === 0 && !canUpload && (
                <p className="text-muted-foreground text-sm">
                    No assets on this client yet.
                </p>
            )}

            <input
                ref={inputRef}
                type="file"
                className="hidden"
                accept="image/*,video/*,.pdf,.doc,.docx,.ppt,.pptx,.zip"
                onChange={(e) => {
                    const file = e.target.files?.[0];

                    if (file) {
                        onUpload(file);
                    }

                    e.target.value = '';
                }}
            />

            {selectedIds.map((id, i) => (
                <input
                    key={id}
                    type="hidden"
                    name={`asset_ids[${i}]`}
                    value={id}
                />
            ))}
        </div>
    );
}
