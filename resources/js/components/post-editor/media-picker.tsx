import { ImagePlus, LibraryBig, Loader2, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { AssetLibraryDialog } from '@/components/post-editor/asset-library-dialog';
import { AssetThumbnail } from '@/components/post-editor/asset-thumbnail';
import type { AssetData } from '@/components/post-editor/types';
import { cn } from '@/lib/utils';

const tileClass =
    'border-border text-muted-foreground hover:border-foreground/30 hover:text-foreground focus-visible:ring-ring/40 flex aspect-square flex-col items-center justify-center gap-1.5 rounded-xl border border-dashed px-2 text-center text-xs transition-colors outline-none focus-visible:ring-[3px]';

/**
 * The post's attached media, in publish order, plus two tiles: upload a new file (click or drop)
 * straight into the client's library, or browse the existing library in a searchable, paged
 * dialog. Only attached assets render here, so a large library never crowds the editor.
 */
export function MediaPicker({
    clientId,
    selected,
    onChange,
    canUpload,
    uploading,
    onUpload,
}: {
    clientId: number;
    selected: AssetData[];
    onChange: (assets: AssetData[]) => void;
    canUpload: boolean;
    uploading: boolean;
    onUpload: (file: File) => void;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const [browsing, setBrowsing] = useState(false);

    return (
        <div className="grid gap-3">
            <ul className="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-5">
                {selected.map((asset, index) => (
                    <li
                        key={asset.id}
                        className="group bg-muted border-border relative aspect-square overflow-hidden rounded-xl border"
                        title={asset.original_filename ?? undefined}
                    >
                        <AssetThumbnail asset={asset} />
                        <span className="bg-primary text-primary-foreground absolute top-1.5 left-1.5 flex size-5 items-center justify-center rounded-full font-mono text-[11px]">
                            {index + 1}
                        </span>
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
                    </li>
                ))}

                {canUpload && (
                    <li>
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
                                dragging &&
                                    'border-primary text-primary bg-accent',
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
                    </li>
                )}

                <li>
                    <button
                        type="button"
                        onClick={() => setBrowsing(true)}
                        className={cn(tileClass, 'w-full')}
                    >
                        <LibraryBig className="size-5" />
                        <span className="text-foreground font-medium">
                            Browse library
                        </span>
                        <span>Search existing assets</span>
                    </button>
                </li>
            </ul>

            {selected.length === 0 && (
                <p className="text-muted-foreground text-xs">
                    No media attached yet.
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

            {selected.map((asset, i) => (
                <input
                    key={asset.id}
                    type="hidden"
                    name={`asset_ids[${i}]`}
                    value={asset.id}
                />
            ))}

            <AssetLibraryDialog
                open={browsing}
                onOpenChange={setBrowsing}
                clientId={clientId}
                selected={selected}
                onConfirm={onChange}
            />
        </div>
    );
}
