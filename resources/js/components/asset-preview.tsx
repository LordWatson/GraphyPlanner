import { ExternalLink, File, Figma, Image as ImageIcon } from 'lucide-react';

export type AssetPreviewData = {
    id: number;
    url: string | null;
    original_filename: string | null;
    type: string | null;
    source: string | null;
    mime_type: string | null;
};

const IMAGE_EXTENSIONS = ['.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg', '.avif'];

function hasImageExtension(url: string | null): boolean {
    if (!url) {
        return false;
    }

    const path = url.split(/[?#]/)[0].toLowerCase();

    return IMAGE_EXTENSIONS.some((extension) => path.endsWith(extension));
}

/**
 * Renders a best-effort preview for a post's attachment, regardless of how it was sourced:
 * a directly uploaded image/video file, an arbitrary external URL, or a Figma design link.
 */
export function AssetPreview({ asset }: { asset: AssetPreviewData }) {
    const label = asset.original_filename ?? 'Attachment';
    const containerClass =
        'flex aspect-square w-full flex-col items-center justify-center gap-1.5 rounded-md border border-border bg-muted/30 p-2 text-center text-xs text-muted-foreground';

    if (asset.source === 'upload' && asset.url) {
        if (asset.mime_type?.startsWith('video/') || asset.type === 'video') {
            return (
                <video
                    src={asset.url}
                    controls
                    className="aspect-square w-full rounded-md border border-border bg-black object-contain"
                >
                    <track kind="captions" />
                </video>
            );
        }

        if (asset.mime_type?.startsWith('image/') || asset.type === 'image' || hasImageExtension(asset.url)) {
            return (
                <img
                    src={asset.url}
                    alt={label}
                    className="aspect-square w-full rounded-md border border-border object-cover"
                />
            );
        }

        return (
            <a
                href={asset.url}
                target="_blank"
                rel="noreferrer"
                className={`${containerClass} hover:border-primary/40 hover:text-foreground`}
            >
                <File className="size-5" />
                <span className="line-clamp-2 break-all">{label}</span>
            </a>
        );
    }

    if (asset.source === 'figma') {
        return (
            <a
                href={asset.url ?? undefined}
                target="_blank"
                rel="noreferrer"
                className={`${containerClass} hover:border-primary/40 hover:text-foreground`}
            >
                <Figma className="size-5" />
                <span className="line-clamp-2 break-all">{label}</span>
                <ExternalLink className="size-3" />
            </a>
        );
    }

    if (asset.source === 'url' && asset.url) {
        if (hasImageExtension(asset.url)) {
            return (
                <img
                    src={asset.url}
                    alt={label}
                    className="aspect-square w-full rounded-md border border-border object-cover"
                />
            );
        }

        return (
            <a
                href={asset.url}
                target="_blank"
                rel="noreferrer"
                className={`${containerClass} hover:border-primary/40 hover:text-foreground`}
            >
                <ImageIcon className="size-5" />
                <span className="line-clamp-2 break-all">{label}</span>
                <ExternalLink className="size-3" />
            </a>
        );
    }

    return <div className={containerClass}>{label}</div>;
}
