import { ImagePlus, LibraryBig, Loader2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { cn } from '@/lib/utils';

const tileClass =
    'border-border text-muted-foreground hover:border-foreground/30 hover:text-foreground focus-visible:ring-ring/40 flex aspect-square flex-col items-center justify-center gap-1.5 rounded-xl border border-dashed px-2 text-center text-xs transition-colors outline-none focus-visible:ring-[3px]';

const acceptedFileTypes = 'image/*,video/*,.pdf,.doc,.docx,.ppt,.pptx,.zip';

/**
 * The two dashed "add something" tiles. They live here rather than in `media-picker` because the
 * post editor is not their only caller: the client Assets tab and the campaign kit page use them
 * on their own, with no `MediaPicker` in sight.
 */

/** Dashed "Upload or drop a file" tile with its own hidden file input. */
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
