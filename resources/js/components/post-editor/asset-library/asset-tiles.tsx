import type { LibraryAsset } from '@/components/post-editor/asset-library/types';
import { Loader2, Trash2 } from 'lucide-react';
import { AssetThumbnail } from '@/components/post-editor/asset-thumbnail';
import { cn } from '@/lib/utils';

export function AssetCaption({ asset }: { asset: LibraryAsset }) {
    return (
        <span className="min-w-0 px-0.5">
            <span className="block truncate text-xs">
                {asset.original_filename ?? `Asset #${asset.id}`}
            </span>
            {asset.campaign_name && (
                <span className="text-muted-foreground block truncate text-[11px]">
                    {asset.campaign_name}
                </span>
            )}
        </span>
    );
}

/** `select` mode tile: toggles the asset in the draft; the badge shows its attach order. */
export function SelectableAsset({
    asset,
    order,
    onToggle,
}: {
    asset: LibraryAsset;
    order: number;
    onToggle: () => void;
}) {
    const isSelected = order !== -1;

    return (
        <li className="min-w-0">
            <button
                type="button"
                onClick={onToggle}
                aria-pressed={isSelected}
                className="group focus-visible:ring-ring/40 flex w-full flex-col gap-1.5 rounded-xl text-left outline-none focus-visible:ring-[3px]"
            >
                <span
                    className={cn(
                        'bg-muted relative block aspect-square w-full overflow-hidden rounded-xl border transition',
                        isSelected
                            ? 'border-primary ring-primary ring-2'
                            : 'border-border group-hover:border-foreground/30',
                    )}
                >
                    <AssetThumbnail asset={asset} />
                    <span
                        className={cn(
                            'absolute top-1.5 right-1.5 flex size-5 items-center justify-center rounded-full border font-mono text-[11px] transition',
                            isSelected
                                ? 'bg-primary text-primary-foreground border-transparent'
                                : 'bg-card/80 border-border text-transparent',
                        )}
                    >
                        {isSelected ? order + 1 : ''}
                    </span>
                </span>
                <AssetCaption asset={asset} />
            </button>
        </li>
    );
}

/** `manage` mode tile: opens the asset in a new tab, with a remove button when allowed. */
export function ManagedAsset({
    asset,
    deleting,
    onDelete,
}: {
    asset: LibraryAsset;
    deleting: boolean;
    onDelete?: () => void;
}) {
    return (
        <li className="group relative min-w-0">
            <a
                href={asset.url ?? undefined}
                target="_blank"
                rel="noreferrer"
                className="focus-visible:ring-ring/40 flex w-full flex-col gap-1.5 rounded-xl outline-none focus-visible:ring-[3px]"
            >
                <span className="bg-muted border-border group-hover:border-foreground/30 relative block aspect-square w-full overflow-hidden rounded-xl border transition">
                    <AssetThumbnail asset={asset} />
                </span>
                <AssetCaption asset={asset} />
            </a>
            {onDelete && (
                <button
                    type="button"
                    onClick={onDelete}
                    disabled={deleting}
                    aria-label={`Remove ${asset.original_filename ?? 'asset'}`}
                    className="bg-card/90 border-border text-foreground hover:bg-card hover:text-destructive focus-visible:ring-ring/40 absolute top-1.5 right-1.5 flex size-7 items-center justify-center rounded-full border opacity-0 transition outline-none group-hover:opacity-100 focus-visible:opacity-100 focus-visible:ring-[3px] disabled:opacity-100 [@media(hover:none)]:opacity-100"
                >
                    {deleting ? (
                        <Loader2 className="size-3.5 animate-spin" />
                    ) : (
                        <Trash2 className="size-3.5" />
                    )}
                </button>
            )}
        </li>
    );
}
