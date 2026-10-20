import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import {
    Check,
    ImagePlus,
    LibraryBig,
    Loader2,
    Pin,
    Scissors,
    Sparkles,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { getXsrfTokenFromCookie } from '@/components/clients/client-layout';
import { placeholderClasses } from '@/components/grids/grid-tile-image';
import type { StudioImage } from '@/components/grids/types';
import { EmptyState } from '@/components/page';
import { AssetLibraryDialog } from '@/components/post-editor/asset-library-dialog';
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
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import { store as storeAsset } from '@/routes/clients/assets';
import { split as splitImage } from '@/routes/grids';
import gridTiles from '@/routes/grids/tiles';

type Source = 'upload' | 'library' | 'studio' | 'split' | 'placeholder';

type TilePayload = Record<string, FormDataConvertible>;

const SPLITS = [
    { value: '1x3', label: '1 × 3', hint: 'A banner across one row' },
    { value: '2x3', label: '2 × 3', hint: 'Two rows' },
    { value: '3x3', label: '3 × 3', hint: 'A full 3 × 3 block' },
];

/**
 * Step 18.5 — "Add tiles": upload, browse the library, pick finished Image Studio images, split
 * one image across rows, or reserve a slot with a placeholder. New tiles go in at `atPosition`
 * (1 = top-left) and push the rest along.
 */
export function AddTilesDialog({
    open,
    onOpenChange,
    gridId,
    clientId,
    campaignId,
    atPosition,
    studioImages,
    canUpload,
    placeholderColours,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    gridId: number;
    clientId: number;
    campaignId: number | null;
    atPosition: number;
    studioImages: StudioImage[] | undefined;
    canUpload: boolean;
    placeholderColours: { value: string; label: string }[];
}) {
    const [source, setSource] = useState<Source>(
        canUpload ? 'upload' : 'library',
    );
    const [library, setLibrary] = useState<'tiles' | 'split' | null>(null);
    const [asCarousel, setAsCarousel] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [picked, setPicked] = useState<number[]>([]);
    const [splitSource, setSplitSource] = useState<AssetData | null>(null);
    const [layout, setLayout] = useState('1x3');
    const [colour, setColour] = useState(
        placeholderColours[0]?.value ?? 'teal',
    );
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const fileRef = useRef<HTMLInputElement>(null);

    const where =
        atPosition === 1 ? 'at the top-left' : `before tile ${atPosition}`;
    const done = () => {
        setPicked([]);
        setSplitSource(null);
        setNote('');
        onOpenChange(false);
    };

    const addTiles = (tiles: TilePayload[]) => {
        setBusy(true);
        router.post(
            gridTiles.store.url(gridId),
            { at_position: atPosition, tiles },
            {
                preserveScroll: true,
                onSuccess: done,
                onFinish: () => setBusy(false),
            },
        );
    };

    const addAssets = (assetIds: number[]) =>
        addTiles(
            asCarousel && assetIds.length > 1
                ? [{ asset_ids: assetIds }]
                : assetIds.map((id) => ({ asset_ids: [id] })),
        );

    const upload = async (files: FileList) => {
        setUploading(true);
        const ids: number[] = [];

        try {
            for (const file of Array.from(files)) {
                const body = new FormData();
                body.append('source', 'upload');
                body.append('file', file);

                if (campaignId) {
                    body.append('campaign_id', String(campaignId));
                }

                const response = await fetch(storeAsset.url(clientId), {
                    method: 'POST',
                    body,
                    headers: {
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': getXsrfTokenFromCookie(),
                    },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    const json = (await response.json().catch(() => null)) as {
                        message?: string;
                    } | null;
                    toast.error(
                        json?.message ?? `We couldn’t upload ${file.name}.`,
                    );
                    continue;
                }

                const json = (await response.json()) as {
                    asset: { id: number };
                };
                ids.push(json.asset.id);
            }
        } finally {
            setUploading(false);
        }

        if (ids.length > 0) {
            addAssets(ids);
        }
    };

    return (
        <>
            <Dialog open={open && library === null} onOpenChange={onOpenChange}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Add tiles</DialogTitle>
                        <DialogDescription>
                            They go in {where} and push the rest along.
                        </DialogDescription>
                    </DialogHeader>
                    <Tabs
                        value={source}
                        onValueChange={(value) => setSource(value as Source)}
                        className="gap-4"
                    >
                        <div className="-mx-1 overflow-x-auto px-1">
                            <TabsList className="whitespace-nowrap">
                                {canUpload && (
                                    <TabsTrigger value="upload">
                                        <ImagePlus />
                                        Upload
                                    </TabsTrigger>
                                )}
                                <TabsTrigger value="library">
                                    <LibraryBig />
                                    Library
                                </TabsTrigger>
                                <TabsTrigger value="studio">
                                    <Sparkles />
                                    Image Studio
                                </TabsTrigger>
                                <TabsTrigger value="split">
                                    <Scissors />
                                    Split an image
                                </TabsTrigger>
                                <TabsTrigger value="placeholder">
                                    <Pin />
                                    Placeholder
                                </TabsTrigger>
                            </TabsList>
                        </div>

                        {(source === 'upload' || source === 'library') && (
                            <label className="flex cursor-pointer items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    className="accent-primary size-4"
                                    checked={asCarousel}
                                    onChange={(event) =>
                                        setAsCarousel(event.target.checked)
                                    }
                                />
                                Put several images in one carousel tile
                            </label>
                        )}

                        <TabsContent value="upload" className="grid gap-3">
                            <button
                                type="button"
                                disabled={uploading || busy}
                                onClick={() => fileRef.current?.click()}
                                onDragOver={(event) => event.preventDefault()}
                                onDrop={(event) => {
                                    event.preventDefault();

                                    if (event.dataTransfer.files.length > 0) {
                                        void upload(event.dataTransfer.files);
                                    }
                                }}
                                className="border-border text-muted-foreground hover:border-foreground/30 hover:text-foreground focus-visible:ring-ring/40 flex min-h-40 flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed p-6 text-center text-sm outline-none focus-visible:ring-[3px]"
                            >
                                {uploading ? (
                                    <Loader2 className="size-6 animate-spin" />
                                ) : (
                                    <ImagePlus className="size-6" />
                                )}
                                <span className="text-foreground font-medium">
                                    {uploading
                                        ? 'Uploading…'
                                        : 'Choose images or videos'}
                                </span>
                                <span>
                                    or drop them here. Each becomes a tile, in
                                    order.
                                </span>
                            </button>
                            <input
                                ref={fileRef}
                                type="file"
                                multiple
                                accept="image/*,video/*"
                                className="hidden"
                                onChange={(event) => {
                                    if (event.target.files?.length) {
                                        void upload(event.target.files);
                                    }

                                    event.target.value = '';
                                }}
                            />
                        </TabsContent>

                        <TabsContent value="library" className="grid gap-3">
                            <p className="text-muted-foreground text-sm">
                                Pick from everything already in this client’s
                                library.
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setLibrary('tiles')}
                                disabled={busy}
                            >
                                <LibraryBig />
                                Browse the library
                            </Button>
                        </TabsContent>

                        <TabsContent value="studio" className="grid gap-3">
                            {studioImages === undefined ? (
                                <div className="grid grid-cols-4 gap-2">
                                    {Array.from({ length: 8 }, (_, index) => (
                                        <Skeleton
                                            key={index}
                                            className="aspect-[3/4] rounded-md"
                                        />
                                    ))}
                                </div>
                            ) : studioImages.length === 0 ? (
                                <EmptyState
                                    compact
                                    icon={Sparkles}
                                    title="No studio images yet."
                                    description="Pick a favourite in the Image Studio and it shows up here."
                                />
                            ) : (
                                <>
                                    <ul className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                                        {studioImages.map((image) => {
                                            const on = picked.includes(
                                                image.variant_id,
                                            );

                                            return (
                                                <li key={image.variant_id}>
                                                    <button
                                                        type="button"
                                                        aria-pressed={on}
                                                        onClick={() =>
                                                            setPicked(
                                                                (current) =>
                                                                    on
                                                                        ? current.filter(
                                                                              (
                                                                                  id,
                                                                              ) =>
                                                                                  id !==
                                                                                  image.variant_id,
                                                                          )
                                                                        : [
                                                                              ...current,
                                                                              image.variant_id,
                                                                          ],
                                                            )
                                                        }
                                                        className={cn(
                                                            'focus-visible:ring-ring relative block aspect-[3/4] w-full overflow-hidden rounded-md outline-none focus-visible:ring-[3px]',
                                                            on &&
                                                                'ring-primary ring-[3px]',
                                                        )}
                                                    >
                                                        {image.thumbnail_url && (
                                                            <img
                                                                src={
                                                                    image.thumbnail_url
                                                                }
                                                                alt=""
                                                                loading="lazy"
                                                                className="size-full object-cover"
                                                            />
                                                        )}
                                                        {on && (
                                                            <span className="bg-primary text-primary-foreground absolute top-1 right-1 flex size-5 items-center justify-center rounded-full font-mono text-[10px]">
                                                                {picked.indexOf(
                                                                    image.variant_id,
                                                                ) + 1}
                                                            </span>
                                                        )}
                                                    </button>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            disabled={
                                                picked.length === 0 || busy
                                            }
                                            onClick={() =>
                                                addTiles(
                                                    picked.map((id) => ({
                                                        variant_ids: [id],
                                                    })),
                                                )
                                            }
                                        >
                                            <Check />
                                            Add {picked.length || ''}{' '}
                                            {picked.length === 1
                                                ? 'tile'
                                                : 'tiles'}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </TabsContent>

                        <TabsContent value="split" className="grid gap-4">
                            <p className="text-muted-foreground text-sm text-pretty">
                                Slice one wide image into 3:4 tiles that run
                                across whole rows. The original stays as it is;
                                the slices move together.
                            </p>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={layout}
                                onValueChange={(value) =>
                                    value && setLayout(value)
                                }
                                className="flex-wrap justify-start"
                            >
                                {SPLITS.map((option) => (
                                    <ToggleGroupItem
                                        key={option.value}
                                        value={option.value}
                                        aria-label={option.hint}
                                    >
                                        {option.label}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setLibrary('split')}
                                >
                                    <LibraryBig />
                                    {splitSource
                                        ? 'Pick another image'
                                        : 'Pick the image'}
                                </Button>
                                {splitSource && (
                                    <span className="text-muted-foreground truncate text-sm">
                                        {splitSource.original_filename ??
                                            'Image'}
                                    </span>
                                )}
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    disabled={!splitSource || busy}
                                    onClick={() => {
                                        if (!splitSource) {
                                            return;
                                        }

                                        setBusy(true);
                                        router.post(
                                            splitImage.url(gridId),
                                            {
                                                asset_id: splitSource.id,
                                                layout,
                                                at_position: atPosition,
                                            },
                                            {
                                                preserveScroll: true,
                                                onSuccess: done,
                                                onError: (errors) =>
                                                    toast.error(
                                                        errors.asset_id ??
                                                            errors.layout ??
                                                            'That image couldn’t be split.',
                                                    ),
                                                onFinish: () => setBusy(false),
                                            },
                                        );
                                    }}
                                >
                                    <Scissors />
                                    {busy ? 'Splitting…' : 'Split it'}
                                </Button>
                            </DialogFooter>
                        </TabsContent>

                        <TabsContent value="placeholder" className="grid gap-4">
                            <p className="text-muted-foreground text-sm">
                                Hold a slot for something that isn’t shot yet.
                            </p>
                            <div
                                className="flex flex-wrap gap-2"
                                role="radiogroup"
                                aria-label="Placeholder colour"
                            >
                                {placeholderColours.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        role="radio"
                                        aria-checked={colour === option.value}
                                        onClick={() => setColour(option.value)}
                                        className={cn(
                                            'focus-visible:ring-ring flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs outline-none focus-visible:ring-[3px]',
                                            colour === option.value
                                                ? 'border-primary bg-primary/5'
                                                : 'border-border',
                                        )}
                                    >
                                        <span
                                            aria-hidden
                                            className={cn(
                                                'size-3 rounded-full',
                                                placeholderClasses[
                                                    option.value
                                                ],
                                            )}
                                        />
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="placeholder-note">
                                    What goes here? (only your team sees this)
                                </Label>
                                <Textarea
                                    id="placeholder-note"
                                    rows={2}
                                    value={note}
                                    onChange={(event) =>
                                        setNote(event.target.value)
                                    }
                                />
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    disabled={busy}
                                    onClick={() =>
                                        addTiles([
                                            {
                                                kind: 'placeholder',
                                                placeholder_colour: colour,
                                                team_note: note,
                                            },
                                        ])
                                    }
                                >
                                    <Pin />
                                    Add placeholder
                                </Button>
                            </DialogFooter>
                        </TabsContent>
                    </Tabs>
                </DialogContent>
            </Dialog>

            <AssetLibraryDialog
                open={library !== null}
                onOpenChange={(next) => !next && setLibrary(null)}
                clientId={clientId}
                campaignId={campaignId ?? undefined}
                imagesOnly={library === 'split'}
                title={
                    library === 'split'
                        ? 'Pick the image to split'
                        : 'Add from the library'
                }
                description={
                    library === 'split'
                        ? 'Pick one wide image. Each slice is a sharp 1080 × 1440 tile.'
                        : 'Each pick becomes a tile, in the order you pick them.'
                }
                confirmLabel={
                    library === 'split' ? 'Use this image' : 'Add tiles'
                }
                onConfirm={(assets) => {
                    if (library === 'split') {
                        setSplitSource(assets[assets.length - 1] ?? null);
                        setLibrary(null);

                        return;
                    }

                    setLibrary(null);

                    if (assets.length > 0) {
                        addAssets(assets.map((asset) => asset.id));
                    }
                }}
            />
        </>
    );
}
