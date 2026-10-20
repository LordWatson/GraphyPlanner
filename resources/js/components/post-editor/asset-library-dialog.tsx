import { ImageOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/page';
import { Pagination } from '@/components/pagination';
import {
    ManagedAsset,
    SelectableAsset,
} from '@/components/post-editor/asset-library/asset-tiles';
import { LibraryFilters } from '@/components/post-editor/asset-library/library-filters';
import type { LibraryAsset } from '@/components/post-editor/asset-library/types';
import { useAssetLibrary } from '@/components/post-editor/asset-library/use-asset-library';
import type { AssetData } from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

export type { LibraryAsset } from '@/components/post-editor/asset-library/types';

/**
 * Browse a client's whole asset library — searched, filtered and paged server-side
 * (`clients.assets.index`, see `useAssetLibrary`) so it stays fast however large it grows.
 *
 * `select` mode (default): selection is a draft until "Attach"; already-attached assets start
 * ticked and keep their order, new picks are appended in the order they were clicked.
 * `manage` mode (client Assets tab): each asset opens in a new tab, and assets the viewer may
 * delete get a remove button wired to `onDelete`; the page reloads after a successful delete.
 */
export function AssetLibraryDialog({
    open,
    onOpenChange,
    clientId,
    mode = 'select',
    selected = [],
    onConfirm,
    onDelete,
    imagesOnly = false,
    title = 'Asset library',
    description = 'Pick the media for this post. The number shows the order it will appear in.',
    confirmLabel = 'Attach',
    campaignId,
    defaultScope = 'campaign',
}: {
    /** Step C-4.1: offer "This campaign / Whole library", narrowing to the campaign's kit. */
    campaignId?: number;
    defaultScope?: 'campaign' | 'all';
    open: boolean;
    onOpenChange: (open: boolean) => void;
    clientId: number;
    mode?: 'select' | 'manage';
    selected?: AssetData[];
    onConfirm?: (assets: AssetData[]) => void;
    /** `manage` mode: remove an asset; resolve `true` on success so the page reloads. */
    onDelete?: (asset: LibraryAsset) => Promise<boolean>;
    /** Lock the library to images (e.g. Brand Brain visual references, Step 16.4). */
    imagesOnly?: boolean;
    title?: string;
    description?: string;
    confirmLabel?: string;
}) {
    const library = useAssetLibrary({
        open,
        clientId,
        campaignId,
        imagesOnly,
        defaultScope,
    });
    const [draft, setDraft] = useState<AssetData[]>(selected);
    const [deletingId, setDeletingId] = useState<number | null>(null);

    // Fresh draft each time the dialog opens.
    useEffect(() => {
        if (open) {
            setDraft(selected);
        }
    }, [open]);

    const toggle = (asset: AssetData) => {
        setDraft((current) =>
            current.some((a) => a.id === asset.id)
                ? current.filter((a) => a.id !== asset.id)
                : [...current, asset],
        );
    };

    const remove = async (asset: LibraryAsset) => {
        if (!onDelete) {
            return;
        }

        setDeletingId(asset.id);

        try {
            if (await onDelete(asset)) {
                library.reload();
            }
        } finally {
            setDeletingId(null);
        }
    };

    const managing = mode === 'manage';
    const { result, loading, error, filtered } = library;
    const meta = result?.meta;
    const assets = result?.data ?? [];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col gap-4 sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <LibraryFilters
                    search={library.search}
                    onSearchChange={library.setSearch}
                    scope={library.scope}
                    onScopeChange={library.setScope}
                    showScope={campaignId !== undefined}
                    type={library.type}
                    onTypeChange={library.setType}
                    showType={!imagesOnly}
                />

                <div className="-mx-1 min-h-[18rem] flex-1 overflow-y-auto px-1 py-1">
                    {error ? (
                        <EmptyState
                            compact
                            icon={ImageOff}
                            title="Couldn't load assets."
                            description={error}
                            action={
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={library.reload}
                                >
                                    Try again
                                </Button>
                            }
                        />
                    ) : loading && !result ? (
                        <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                            {Array.from({ length: 12 }).map((_, i) => (
                                <Skeleton
                                    key={i}
                                    className="aspect-square rounded-xl"
                                />
                            ))}
                        </div>
                    ) : assets.length === 0 ? (
                        <EmptyState
                            compact
                            icon={ImageOff}
                            title={
                                filtered ? 'Nothing matches.' : 'No assets yet.'
                            }
                            description={
                                filtered
                                    ? 'Try a different search or type.'
                                    : 'Upload something from the Media section to start the library.'
                            }
                        />
                    ) : (
                        <ul
                            className={cn(
                                'grid grid-cols-3 gap-3 transition-opacity sm:grid-cols-4 lg:grid-cols-6',
                                loading && 'opacity-60',
                            )}
                        >
                            {assets.map((asset) =>
                                managing ? (
                                    <ManagedAsset
                                        key={asset.id}
                                        asset={asset}
                                        deleting={deletingId === asset.id}
                                        onDelete={
                                            onDelete && asset.can?.delete
                                                ? () => void remove(asset)
                                                : undefined
                                        }
                                    />
                                ) : (
                                    <SelectableAsset
                                        key={asset.id}
                                        asset={asset}
                                        order={draft.findIndex(
                                            (a) => a.id === asset.id,
                                        )}
                                        onToggle={() => toggle(asset)}
                                    />
                                ),
                            )}
                        </ul>
                    )}
                </div>

                {meta && meta.total > 0 && (
                    <Pagination
                        className="border-border/70 border-t pt-3"
                        label={meta.total === 1 ? 'asset' : 'assets'}
                        page={{
                            current_page: meta.current_page,
                            last_page: meta.last_page,
                            total: meta.total,
                            from: (meta.current_page - 1) * meta.per_page + 1,
                            to: Math.min(
                                meta.current_page * meta.per_page,
                                meta.total,
                            ),
                        }}
                        onPageChange={library.setPage}
                    />
                )}

                {managing ? (
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            Close
                        </Button>
                    </DialogFooter>
                ) : (
                    <DialogFooter className="items-center sm:justify-between">
                        <span className="text-muted-foreground text-sm">
                            {draft.length === 0
                                ? 'Nothing selected'
                                : `${draft.length} selected`}
                            {draft.length > 0 && (
                                <Button
                                    type="button"
                                    variant="link"
                                    className="ml-2 h-auto text-sm"
                                    onClick={() => setDraft([])}
                                >
                                    Clear
                                </Button>
                            )}
                        </span>
                        <span className="flex gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => onOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                onClick={() => {
                                    onConfirm?.(draft);
                                    onOpenChange(false);
                                }}
                            >
                                {draft.length === 0
                                    ? `${confirmLabel} none`
                                    : `${confirmLabel} ${draft.length}`}
                            </Button>
                        </span>
                    </DialogFooter>
                )}
            </DialogContent>
        </Dialog>
    );
}
