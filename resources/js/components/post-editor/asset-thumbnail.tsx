import { FileText, Film } from 'lucide-react';
import type { AssetData } from '@/components/post-editor/types';

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

/** Square-fill preview of an asset: image, first video frame, or a file glyph + name. */
export function AssetThumbnail({ asset }: { asset: AssetData }) {
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
