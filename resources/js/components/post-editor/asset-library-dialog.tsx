import { ChevronLeft, ChevronRight, ImageOff, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/page';
import { AssetThumbnail } from '@/components/post-editor/asset-thumbnail';
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
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import { index as assetIndex } from '@/routes/clients/assets';

type LibraryAsset = AssetData & {
    campaign_name: string | null;
    created_at: string | null;
};

type LibraryPage = {
    data: LibraryAsset[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

const typeFilters = [
    { value: 'all', label: 'All' },
    { value: 'image', label: 'Images' },
    { value: 'video', label: 'Videos' },
    { value: 'document', label: 'Documents' },
];

/**
 * Browse a client's whole asset library — searched, filtered and paged server-side
 * (`clients.assets.index`) so it stays fast however large the library grows. Selection is a
 * draft until "Attach"; already-attached assets start ticked and keep their order, new picks are
 * appended in the order they were clicked.
 */
export function AssetLibraryDialog({
    open,
    onOpenChange,
    clientId,
    selected,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    clientId: number;
    selected: AssetData[];
    onConfirm: (assets: AssetData[]) => void;
}) {
    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [type, setType] = useState('all');
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<LibraryPage | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [draft, setDraft] = useState<AssetData[]>(selected);
    const [reloadKey, setReloadKey] = useState(0);

    // Fresh draft each time the dialog opens.
    useEffect(() => {
        if (open) {
            setDraft(selected);
        }
    }, [open]);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            setDebouncedSearch(search.trim());
            setPage(1);
        }, 300);

        return () => window.clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();
        setLoading(true);
        setError(null);

        fetch(
            assetIndex.url(clientId, {
                query: {
                    search: debouncedSearch || undefined,
                    type: type === 'all' ? undefined : type,
                    page,
                },
            }),
            {
                signal: controller.signal,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        )
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error('Unable to load assets.');
                }

                setResult((await response.json()) as LibraryPage);
            })
            .catch((e: unknown) => {
                if (e instanceof DOMException && e.name === 'AbortError') {
                    return;
                }

                setError('Unable to load assets right now.');
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [open, clientId, debouncedSearch, type, page, reloadKey]);

    const toggle = (asset: AssetData) => {
        setDraft((current) =>
            current.some((a) => a.id === asset.id)
                ? current.filter((a) => a.id !== asset.id)
                : [...current, asset],
        );
    };

    const meta = result?.meta;
    const assets = result?.data ?? [];
    const filtered = debouncedSearch !== '' || type !== 'all';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col gap-4 sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>Asset library</DialogTitle>
                    <DialogDescription>
                        Pick the media for this post. The number shows the order
                        it will appear in.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <div className="relative flex-1">
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                        <Input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search by file name…"
                            className="pl-9"
                            aria-label="Search assets"
                            autoFocus
                        />
                    </div>
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        size="sm"
                        value={type}
                        onValueChange={(value) => {
                            if (value) {
                                setType(value);
                                setPage(1);
                            }
                        }}
                        aria-label="Filter by type"
                    >
                        {typeFilters.map((filter) => (
                            <ToggleGroupItem
                                key={filter.value}
                                value={filter.value}
                                className="px-3"
                            >
                                {filter.label}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                </div>

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
                                    onClick={() =>
                                        setReloadKey((key) => key + 1)
                                    }
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
                            {assets.map((asset) => {
                                const order = draft.findIndex(
                                    (a) => a.id === asset.id,
                                );
                                const isSelected = order !== -1;

                                return (
                                    <li key={asset.id} className="min-w-0">
                                        <button
                                            type="button"
                                            onClick={() => toggle(asset)}
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
                                                    {isSelected
                                                        ? order + 1
                                                        : ''}
                                                </span>
                                            </span>
                                            <span className="min-w-0 px-0.5">
                                                <span className="block truncate text-xs">
                                                    {asset.original_filename ??
                                                        `Asset #${asset.id}`}
                                                </span>
                                                {asset.campaign_name && (
                                                    <span className="text-muted-foreground block truncate text-[11px]">
                                                        {asset.campaign_name}
                                                    </span>
                                                )}
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                {meta && meta.total > 0 && (
                    <div className="border-border/70 flex items-center justify-between gap-3 border-t pt-3">
                        <span className="text-muted-foreground font-mono text-xs tabular-nums">
                            {meta.total.toLocaleString()}{' '}
                            {meta.total === 1 ? 'asset' : 'assets'}
                        </span>
                        {meta.last_page > 1 && (
                            <div className="flex items-center gap-1">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-8"
                                    disabled={meta.current_page <= 1 || loading}
                                    onClick={() =>
                                        setPage(meta.current_page - 1)
                                    }
                                    aria-label="Previous page"
                                >
                                    <ChevronLeft />
                                </Button>
                                <span className="font-mono text-xs tabular-nums">
                                    {meta.current_page} / {meta.last_page}
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-8"
                                    disabled={
                                        meta.current_page >= meta.last_page ||
                                        loading
                                    }
                                    onClick={() =>
                                        setPage(meta.current_page + 1)
                                    }
                                    aria-label="Next page"
                                >
                                    <ChevronRight />
                                </Button>
                            </div>
                        )}
                    </div>
                )}

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
                                onConfirm(draft);
                                onOpenChange(false);
                            }}
                        >
                            {draft.length === 0
                                ? 'Attach none'
                                : `Attach ${draft.length}`}
                        </Button>
                    </span>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
