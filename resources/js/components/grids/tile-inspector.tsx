import type { FormDataConvertible } from '@inertiajs/core';
import { Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ExternalLink,
    ImagePlus,
    Lock,
    Plus,
    RotateCcw,
    Trash2,
    TriangleAlert,
    Undo2,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { confirmDialog } from '@/components/confirm-dialog';
import { GridFramingEditor } from '@/components/grids/grid-framing-editor';
import {
    GridTileImage,
    placeholderClasses,
} from '@/components/grids/grid-tile-image';
import type {
    GridAsset,
    GridTileData,
    GridTileKind,
} from '@/components/grids/types';
import InputError from '@/components/input-error';
import { AssetLibraryDialog } from '@/components/post-editor/asset-library-dialog';
import type { AssetData } from '@/components/post-editor/types';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatGridMoment, ordinal } from '@/lib/grid-layout';
import type { Crop } from '@/lib/media-format';
import { DEFAULT_CROP, ratioLabels } from '@/lib/media-format';
import { cn } from '@/lib/utils';
import { edit as editPost } from '@/routes/posts';
import gridTiles from '@/routes/grids/tiles';

export type TileDraft = {
    kind: GridTileKind;
    format: string;
    assets: GridAsset[];
    cover: GridAsset | null;
    placeholder_colour: string | null;
    team_note: string;
    caption_idea: string;
    planned_local_date: string;
    planned_local_time: string;
};

export function draftOf(tile: GridTileData): TileDraft {
    return {
        kind: tile.kind,
        format: tile.format,
        assets: tile.assets,
        cover: tile.cover,
        placeholder_colour: tile.placeholder_colour,
        team_note: tile.team_note ?? '',
        caption_idea: tile.caption_idea ?? '',
        planned_local_date: tile.planned_local_date ?? '',
        planned_local_time: (tile.planned_local_time ?? '').slice(0, 5),
    };
}

/** The save payload for `grids.tiles.update`. */
export function payloadOf(
    draft: TileDraft,
): Record<string, FormDataConvertible> {
    return {
        kind: draft.kind,
        format: draft.format,
        asset_ids: draft.assets.map((asset) => asset.id),
        asset_crops: Object.fromEntries(
            draft.assets.map((asset) => [asset.id, asset.crop]),
        ),
        asset_alt_texts: Object.fromEntries(
            draft.assets.map((asset) => [asset.id, asset.alt_text ?? '']),
        ),
        cover_asset_id:
            draft.kind === 'reel' ? (draft.cover?.id ?? null) : null,
        placeholder_colour:
            draft.kind === 'placeholder' ? draft.placeholder_colour : null,
        team_note: draft.team_note,
        caption_idea: draft.caption_idea,
        planned_local_date: draft.planned_local_date || null,
        planned_local_time: draft.planned_local_time || null,
    };
}

const FEED_FORMATS = ['3:4', '4:5', '1:1', '1.91:1', 'original'];

const kinds: { value: GridTileKind; label: string }[] = [
    { value: 'image', label: 'Image' },
    { value: 'carousel', label: 'Carousel' },
    { value: 'reel', label: 'Reel' },
    { value: 'placeholder', label: 'Placeholder' },
];

function asGridAsset(asset: AssetData): GridAsset {
    return {
        id: asset.id,
        url: asset.url,
        thumbnail_url: asset.thumbnail_url ?? null,
        type: asset.type,
        width: asset.width ?? null,
        height: asset.height ?? null,
        original_filename: asset.original_filename,
        alt_text: asset.alt_text ?? null,
        crop: DEFAULT_CROP,
    };
}

/**
 * Step 18.5 — the selected tile: what it is, its media in carousel order (reorder, remove, alt
 * text), a Reel's cover, its format ("3:4 fills the grid"), focus and zoom with the live grid-crop
 * overlay, the team note (never shown to the client), the caption idea and the planned date. It
 * edits a draft; the page's save bar (or ⌘/Ctrl+S) saves it.
 */
export function TileInspector({
    gridId,
    clientId,
    campaignId,
    tile,
    draft,
    errors,
    timezone,
    readOnly,
    placeholderColours,
    onChange,
    onAddBefore,
    onClose,
}: {
    gridId: number;
    clientId: number;
    campaignId: number | null;
    tile: GridTileData;
    draft: TileDraft;
    errors: Record<string, string>;
    timezone: string;
    readOnly: boolean;
    placeholderColours: { value: string; label: string }[];
    onChange: (draft: TileDraft) => void;
    onAddBefore: (position: number) => void;
    onClose: () => void;
}) {
    const [library, setLibrary] = useState<'media' | 'cover' | null>(null);
    const [framed, setFramed] = useState(0);
    const set = (patch: Partial<TileDraft>) => onChange({ ...draft, ...patch });
    const framingAsset =
        draft.assets.filter((asset) => asset.type !== 'video')[framed] ?? null;
    const images = draft.assets.filter((asset) => asset.type !== 'video');
    const formats = draft.kind === 'reel' ? ['9:16'] : FEED_FORMATS;

    const setKind = (kind: GridTileKind) => {
        const format =
            kind === 'reel'
                ? '9:16'
                : draft.format === '9:16'
                  ? '3:4'
                  : draft.format;
        set({ kind, format });
    };

    const moveAsset = (index: number, step: number) => {
        const next = [...draft.assets];
        const [moved] = next.splice(index, 1);
        next.splice(index + step, 0, moved);
        set({ assets: next });
    };

    const updateAsset = (id: number, patch: Partial<GridAsset>) =>
        set({
            assets: draft.assets.map((asset) =>
                asset.id === id ? { ...asset, ...patch } : asset,
            ),
        });

    const removeTile = async () => {
        const confirmed = await confirmDialog({
            title: `Remove tile ${tile.position}?`,
            description: tile.post
                ? 'Its post stays — it just won’t be part of this grid any more.'
                : 'The tiles after it move up one place. Its media stays in the library.',
            confirmLabel: 'Remove tile',
            tone: 'destructive',
        });

        if (confirmed) {
            router.delete(gridTiles.destroy.url([gridId, tile.id]), {
                preserveScroll: true,
                onSuccess: onClose,
            });
        }
    };

    return (
        <section
            className="border-border bg-card flex flex-col gap-5 rounded-2xl border p-5 shadow-sm"
            aria-label={`Tile ${tile.position}`}
        >
            <header className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <div className="w-14 shrink-0 overflow-hidden rounded-md">
                        <GridTileImage
                            tile={{ ...tile, ...draft }}
                            override={{
                                format: draft.format,
                                crop: draft.assets[0]?.crop,
                            }}
                        />
                    </div>
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <span className="eyebrow text-muted-foreground">
                            Tile {String(tile.position).padStart(2, '0')}
                        </span>
                        <h2 className="text-[17px] leading-snug font-semibold">
                            {tile.sequence !== null
                                ? `Goes out ${ordinal(tile.sequence)}`
                                : 'Not dated yet'}
                        </h2>
                        <span className="text-muted-foreground text-xs">
                            {formatGridMoment(tile.publish_at, timezone)}
                        </span>
                    </div>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label="Close tile"
                    onClick={onClose}
                >
                    <X />
                </Button>
            </header>

            {tile.drift.length > 0 && (
                <div className="border-warning/30 bg-warning/10 grid gap-2 rounded-xl border p-3 text-sm">
                    <p className="flex items-center gap-2 font-medium">
                        <TriangleAlert className="text-warning size-4" />
                        Changed since the client approved the grid
                    </p>
                    <ul className="text-muted-foreground list-disc pl-5 text-xs">
                        {tile.drift.map((change) => (
                            <li key={change}>{change}</li>
                        ))}
                    </ul>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    gridTiles.restorePost.url([
                                        gridId,
                                        tile.id,
                                    ]),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Undo2 />
                            Put the post back
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={async () => {
                                if (
                                    await confirmDialog({
                                        title: 'Update the tile from the post?',
                                        description:
                                            'This changes the approved look, so the grid goes back to draft for a fresh review.',
                                        confirmLabel: 'Update tile',
                                    })
                                ) {
                                    router.post(
                                        gridTiles.syncFromPost.url([
                                            gridId,
                                            tile.id,
                                        ]),
                                        {},
                                        { preserveScroll: true },
                                    );
                                }
                            }}
                        >
                            <RotateCcw />
                            Update the tile from the post
                        </Button>
                    </div>
                </div>
            )}

            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                {tile.post ? (
                    <>
                        <StatusPill
                            kind="post"
                            value={tile.post.status}
                            label={tile.post.status_label}
                        />
                        <Button
                            asChild
                            variant="link"
                            size="sm"
                            className="h-auto px-0"
                        >
                            <Link href={editPost(tile.post.id)}>
                                Open post
                                <ExternalLink className="size-3.5" />
                            </Link>
                        </Button>
                    </>
                ) : (
                    <span className="text-muted-foreground text-xs">
                        No post yet. Posts are made once the grid is approved.
                    </span>
                )}
            </div>

            <fieldset
                disabled={readOnly}
                className="grid gap-5 disabled:opacity-80"
            >
                <div className="grid gap-1.5">
                    <Label htmlFor="tile-kind">Kind</Label>
                    <Select
                        value={draft.kind}
                        onValueChange={(value) =>
                            setKind(value as GridTileKind)
                        }
                        disabled={readOnly}
                    >
                        <SelectTrigger id="tile-kind" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {kinds.map((kind) => (
                                <SelectItem key={kind.value} value={kind.value}>
                                    {kind.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.kind} />
                </div>

                {draft.kind === 'placeholder' ? (
                    <div className="grid gap-1.5">
                        <Label>Colour</Label>
                        <div
                            className="flex flex-wrap gap-2"
                            role="radiogroup"
                            aria-label="Placeholder colour"
                        >
                            {placeholderColours.map((colour) => (
                                <button
                                    key={colour.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={
                                        draft.placeholder_colour ===
                                        colour.value
                                    }
                                    onClick={() =>
                                        set({
                                            placeholder_colour: colour.value,
                                        })
                                    }
                                    className={cn(
                                        'focus-visible:ring-ring flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs outline-none focus-visible:ring-[3px]',
                                        draft.placeholder_colour ===
                                            colour.value
                                            ? 'border-primary bg-primary/5'
                                            : 'border-border',
                                    )}
                                >
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'size-3 rounded-full',
                                            placeholderClasses[colour.value],
                                        )}
                                    />
                                    {colour.label}
                                </button>
                            ))}
                        </div>
                    </div>
                ) : (
                    <>
                        <div className="grid gap-2">
                            <div className="flex items-center justify-between">
                                <Label>
                                    {draft.kind === 'carousel'
                                        ? 'Slides, in order'
                                        : draft.kind === 'reel'
                                          ? 'Video'
                                          : 'Image'}
                                </Label>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setLibrary('media')}
                                >
                                    <ImagePlus />
                                    {draft.assets.length === 0
                                        ? 'Add media'
                                        : 'Change'}
                                </Button>
                            </div>
                            {draft.assets.length > 0 && (
                                <ol className="grid gap-2">
                                    {draft.assets.map((asset, index) => (
                                        <li
                                            key={asset.id}
                                            className="border-border flex items-start gap-2 rounded-lg border p-2"
                                        >
                                            <span className="text-muted-foreground w-5 pt-2 text-center font-mono text-[11px]">
                                                {index + 1}
                                            </span>
                                            <div className="bg-muted size-12 shrink-0 overflow-hidden rounded-md">
                                                {asset.thumbnail_url ||
                                                asset.url ? (
                                                    <img
                                                        src={
                                                            asset.thumbnail_url ??
                                                            asset.url ??
                                                            ''
                                                        }
                                                        alt=""
                                                        className="size-full object-cover"
                                                    />
                                                ) : null}
                                            </div>
                                            <div className="grid min-w-0 flex-1 gap-1">
                                                <span className="truncate text-xs">
                                                    {asset.original_filename ??
                                                        `Item ${index + 1}`}
                                                </span>
                                                <Input
                                                    aria-label={`Alt text for item ${index + 1}`}
                                                    placeholder="Alt text — describe it for screen readers"
                                                    value={asset.alt_text ?? ''}
                                                    onChange={(event) =>
                                                        updateAsset(asset.id, {
                                                            alt_text:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                    className="h-8 text-xs"
                                                />
                                            </div>
                                            <div className="flex flex-col">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-7"
                                                    aria-label="Move up"
                                                    disabled={index === 0}
                                                    onClick={() =>
                                                        moveAsset(index, -1)
                                                    }
                                                >
                                                    <ArrowUp />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-7"
                                                    aria-label="Move down"
                                                    disabled={
                                                        index ===
                                                        draft.assets.length - 1
                                                    }
                                                    onClick={() =>
                                                        moveAsset(index, 1)
                                                    }
                                                >
                                                    <ArrowDown />
                                                </Button>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-7"
                                                aria-label="Remove item"
                                                onClick={() =>
                                                    set({
                                                        assets: draft.assets.filter(
                                                            (item) =>
                                                                item.id !==
                                                                asset.id,
                                                        ),
                                                    })
                                                }
                                            >
                                                <X />
                                            </Button>
                                        </li>
                                    ))}
                                </ol>
                            )}
                            <InputError message={errors.asset_ids} />
                        </div>

                        {draft.kind === 'reel' && (
                            <div className="grid gap-2">
                                <div className="flex items-center justify-between">
                                    <Label>Cover</Label>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setLibrary('cover')}
                                    >
                                        <ImagePlus />
                                        {draft.cover
                                            ? 'Change cover'
                                            : 'Pick a cover'}
                                    </Button>
                                </div>
                                {draft.cover && (
                                    <div className="flex items-center gap-2 text-xs">
                                        <div className="w-12 overflow-hidden rounded-md">
                                            <GridTileImage
                                                tile={{
                                                    kind: 'image',
                                                    format: '9:16',
                                                    assets: [draft.cover],
                                                }}
                                            />
                                        </div>
                                        <span className="text-muted-foreground">
                                            The grid shows the middle 3:4 of the
                                            cover.
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => set({ cover: null })}
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                )}
                            </div>
                        )}

                        <div className="grid gap-1.5">
                            <Label>Format</Label>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                size="sm"
                                value={draft.format}
                                onValueChange={(value) =>
                                    value && set({ format: value })
                                }
                                className="flex-wrap justify-start"
                                aria-label="Format"
                            >
                                {formats.map((format) => (
                                    <ToggleGroupItem
                                        key={format}
                                        value={format}
                                        className="whitespace-nowrap"
                                    >
                                        {format === 'original'
                                            ? 'Original'
                                            : format}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                            <p className="text-muted-foreground text-xs">
                                {draft.format === '3:4'
                                    ? 'Classic portrait — fills the grid tile exactly.'
                                    : draft.format === '9:16'
                                      ? 'Full screen. The grid shows the middle 3:4 of the cover.'
                                      : `${ratioLabels[draft.format] ?? draft.format}. The grid trims it to 3:4.`}
                            </p>
                            <InputError message={errors.format} />
                        </div>

                        {framingAsset && (
                            <div className="grid gap-2">
                                <div className="flex items-center justify-between gap-2">
                                    <Label>Focus and zoom</Label>
                                    {images.length > 1 && (
                                        <Select
                                            value={String(framed)}
                                            onValueChange={(value) =>
                                                setFramed(Number(value))
                                            }
                                        >
                                            <SelectTrigger className="h-8 w-auto text-xs">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {images.map((asset, index) => (
                                                    <SelectItem
                                                        key={asset.id}
                                                        value={String(index)}
                                                    >
                                                        Slide {index + 1}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    )}
                                </div>
                                <GridFramingEditor
                                    asset={framingAsset}
                                    format={
                                        draft.kind === 'reel'
                                            ? '9:16'
                                            : draft.format
                                    }
                                    crop={framingAsset.crop}
                                    disabled={readOnly}
                                    onChange={(crop: Crop) =>
                                        updateAsset(framingAsset.id, { crop })
                                    }
                                />
                            </div>
                        )}
                    </>
                )}

                <div className="grid gap-1.5">
                    <Label
                        htmlFor="tile-team-note"
                        className="flex items-center gap-1.5"
                    >
                        Team note
                        <span className="text-muted-foreground inline-flex items-center gap-1 text-xs font-normal">
                            <Lock className="size-3" />
                            Only your team sees this
                        </span>
                    </Label>
                    <Textarea
                        id="tile-team-note"
                        rows={2}
                        value={draft.team_note}
                        onChange={(event) =>
                            set({ team_note: event.target.value })
                        }
                    />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="tile-caption-idea">Caption idea</Label>
                    <Textarea
                        id="tile-caption-idea"
                        rows={3}
                        value={draft.caption_idea}
                        placeholder="Becomes the post’s caption draft. The words still get approved on the post."
                        onChange={(event) =>
                            set({ caption_idea: event.target.value })
                        }
                    />
                    <InputError message={errors.caption_idea} />
                </div>

                <div className="grid gap-1.5">
                    <Label>Planned for</Label>
                    <div className="flex gap-2">
                        <Input
                            type="date"
                            aria-label="Planned date"
                            value={draft.planned_local_date}
                            onChange={(event) =>
                                set({ planned_local_date: event.target.value })
                            }
                        />
                        <Input
                            type="time"
                            aria-label="Planned time"
                            value={draft.planned_local_time}
                            onChange={(event) =>
                                set({ planned_local_time: event.target.value })
                            }
                            className="w-32"
                        />
                    </div>
                    <p className="text-muted-foreground text-xs">
                        In the account’s time zone ({timezone}).
                    </p>
                    <InputError
                        message={
                            errors.planned_local_date ??
                            errors.planned_local_time
                        }
                    />
                </div>
            </fieldset>

            {!readOnly && (
                <div className="border-border/70 flex flex-wrap justify-between gap-2 border-t pt-4">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => onAddBefore(tile.position)}
                    >
                        <Plus />
                        Add tiles before this one
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="text-destructive"
                        onClick={() => void removeTile()}
                    >
                        <Trash2 />
                        Remove tile
                    </Button>
                </div>
            )}

            <AssetLibraryDialog
                open={library !== null}
                onOpenChange={(open) => !open && setLibrary(null)}
                clientId={clientId}
                campaignId={campaignId ?? undefined}
                imagesOnly={library === 'cover'}
                selected={library === 'cover' ? [] : draft.assets}
                title={library === 'cover' ? 'Pick a Reel cover' : 'Tile media'}
                description={
                    library === 'cover'
                        ? 'The cover shows in the Reels tab and, trimmed to 3:4, on the grid.'
                        : 'Pick the media for this tile. The number is the order it appears in.'
                }
                confirmLabel={library === 'cover' ? 'Use as cover' : 'Use'}
                onConfirm={(assets) => {
                    if (library === 'cover') {
                        set({
                            cover: assets[0] ? asGridAsset(assets[0]) : null,
                        });
                    } else {
                        const existing = new Map(
                            draft.assets.map((asset) => [asset.id, asset]),
                        );
                        set({
                            assets: assets.map(
                                (asset) =>
                                    existing.get(asset.id) ??
                                    asGridAsset(asset),
                            ),
                        });
                    }

                    setLibrary(null);
                }}
            />
        </section>
    );
}
