import { Deferred, Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    LayoutGrid,
    Lock,
    MousePointerClick,
    Plus,
    RefreshCw,
    Settings2,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { CampaignChip } from '@/components/campaigns/campaign-chip';
import { confirmDialog } from '@/components/confirm-dialog';
import { AddTilesDialog } from '@/components/grids/add-tiles-dialog';
import { GridAiPanel } from '@/components/grids/grid-ai-panel';
import { GridBoard } from '@/components/grids/grid-board';
import { GridPreview } from '@/components/grids/grid-preview';
import { GridProduction } from '@/components/grids/grid-production';
import { GridWorkflow } from '@/components/grids/grid-workflow';
import {
    TileInspector,
    draftOf,
    payloadOf,
} from '@/components/grids/tile-inspector';
import type { TileDraft } from '@/components/grids/tile-inspector';
import type {
    GridCommentData,
    GridEditorProps,
    GridTileData,
} from '@/components/grids/types';
import InputError from '@/components/input-error';
import { EmptyState, Page, PageHeader, Panel } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { PostDiscussion } from '@/components/post-editor/post-discussion';
import { ReadinessChecklist } from '@/components/post-editor/readiness-checklist';
import { StatusPill } from '@/components/status-pill';
import { Badge } from '@/components/ui/badge';
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
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useUnsavedChangesGuard } from '@/hooks/use-unsaved-changes-guard';
import { gridDisplayStatus } from '@/lib/grid-workflow';
import { cn } from '@/lib/utils';
import { show as showCampaign } from '@/routes/campaigns';
import { index } from '@/routes/clients';
import { index as clientGrids } from '@/routes/clients/grids';
import { update as updateGrid } from '@/routes/grids';
import { store as storeGridComment } from '@/routes/grids/comments';
import { refresh as refreshFeed } from '@/routes/grids/feed';
import { store as addOutsidePost } from '@/routes/grids/outside-posts';
import gridTiles from '@/routes/grids/tiles';

const NONE = 'none';

function initialTileId(tiles: GridTileData[]): number | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const requested = Number(
        new URLSearchParams(window.location.search).get('tile'),
    );

    return tiles.some((tile) => tile.id === requested) ? requested : null;
}

/** The plan's own settings: name, campaign, pinned posts, the note for the client, the live feed. */
function GridSettings({
    grid,
    campaignOptions,
    readOnly,
}: Pick<GridEditorProps, 'grid' | 'campaignOptions'> & { readOnly: boolean }) {
    const [name, setName] = useState(grid.name);
    const [campaign, setCampaign] = useState(
        grid.campaign_id ? String(grid.campaign_id) : NONE,
    );
    const [pinned, setPinned] = useState(String(grid.pinned_count));
    const [message, setMessage] = useState(grid.review_message ?? '');
    const [liveFeed, setLiveFeed] = useState(grid.include_live_feed);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const dirty =
        name !== grid.name ||
        campaign !== (grid.campaign_id ? String(grid.campaign_id) : NONE) ||
        pinned !== String(grid.pinned_count) ||
        message !== (grid.review_message ?? '') ||
        liveFeed !== grid.include_live_feed;

    const save = async () => {
        const reopens =
            pinned !== String(grid.pinned_count) &&
            ['approved', 'changes_requested'].includes(grid.status);

        if (
            reopens &&
            !(await confirmDialog({
                title: 'Reopen the grid?',
                description:
                    'Changing the pinned posts moves every tile, so the grid goes back to draft for a fresh review.',
                confirmLabel: 'Save and reopen',
            }))
        ) {
            return;
        }

        setSaving(true);
        router.put(
            updateGrid.url(grid.id),
            {
                name,
                campaign_id: campaign === NONE ? null : Number(campaign),
                pinned_count: Number(pinned),
                review_message: message,
                include_live_feed: liveFeed,
            },
            {
                preserveScroll: true,
                onError: setErrors,
                onSuccess: () => setErrors({}),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Panel eyebrow="Settings" title="Grid settings" icon={Settings2}>
            <fieldset disabled={readOnly} className="grid gap-4">
                <div className="grid gap-1.5">
                    <Label htmlFor="grid-name">Name</Label>
                    <Input
                        id="grid-name"
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                    />
                    <InputError message={errors.name} />
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label>Campaign</Label>
                        <Select
                            value={campaign}
                            onValueChange={setCampaign}
                            disabled={readOnly}
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>
                                    No campaign
                                </SelectItem>
                                {campaignOptions.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Pinned posts on the profile</Label>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            value={pinned}
                            onValueChange={(value) => value && setPinned(value)}
                            className="justify-start"
                        >
                            {['0', '1', '2', '3'].map((value) => (
                                <ToggleGroupItem
                                    key={value}
                                    value={value}
                                    aria-label={`${value} pinned`}
                                >
                                    {value}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                        <InputError message={errors.pinned_count} />
                    </div>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="grid-message">A note for the client</Label>
                    <Textarea
                        id="grid-message"
                        rows={3}
                        value={message}
                        placeholder="Shown with the grid when they review it."
                        onChange={(event) => setMessage(event.target.value)}
                    />
                </div>
                <label className="flex cursor-pointer items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="accent-primary size-4"
                        checked={liveFeed}
                        onChange={(event) => setLiveFeed(event.target.checked)}
                    />
                    Show what’s already on the profile under the plan
                </label>
                {!readOnly && (
                    <div>
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={!dirty || saving}
                            onClick={() => void save()}
                        >
                            {saving ? 'Saving…' : 'Save settings'}
                        </Button>
                    </div>
                )}
            </fieldset>
        </Panel>
    );
}

/**
 * Step 18.5 — the grid planner: the post editor's sibling, but the thing being edited is the
 * grid — tiles, order, media and framing. The board on the left, the selected tile's inspector
 * beside it, the readiness checklist and the phone preview in the sticky rail, and the
 * discussion underneath. Reorders save straight away; tile edits save from the sticky bar
 * (or ⌘/Ctrl+S).
 */
export default function GridEdit(props: GridEditorProps) {
    const {
        grid,
        client,
        can,
        allowedTransitions,
        campaignOptions,
        placeholderColours,
        mentionableUsers,
        minGapMinutes,
        feed,
        comments,
        activity,
        studioImages,
    } = props;
    const readOnly = !can.update;
    const [selectedId, setSelectedId] = useState<number | null>(() =>
        initialTileId(grid.tiles),
    );
    const [localOrder, setLocalOrder] = useState<number[] | null>(null);
    const [addAt, setAddAt] = useState<number | null>(null);
    const [hoverTile, setHoverTile] = useState<number | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const tiles = useMemo(() => {
        if (!localOrder) {
            return grid.tiles;
        }

        const byId = new Map(grid.tiles.map((tile) => [tile.id, tile]));

        return localOrder
            .map((id) => byId.get(id))
            .filter((tile): tile is GridTileData => tile !== undefined)
            .map((tile, position) => ({ ...tile, position: position + 1 }));
    }, [grid.tiles, localOrder]);

    const selectedTile = tiles.find((tile) => tile.id === selectedId) ?? null;
    const serverDraft = selectedTile ? draftOf(selectedTile) : null;
    const serverKey = JSON.stringify(serverDraft);
    const [draft, setDraft] = useState<TileDraft | null>(serverDraft);
    const lastServerKey = useRef(serverKey);

    // New server state (a save, someone else's change) replaces the draft — unless there are
    // unsaved edits on top of the old state.
    useEffect(() => {
        if (draft === null || JSON.stringify(draft) === lastServerKey.current) {
            setDraft(serverDraft);
        }

        lastServerKey.current = serverKey;
    }, [serverKey]);

    const isDirty = draft !== null && JSON.stringify(draft) !== serverKey;
    useUnsavedChangesGuard(isDirty);

    const save = () => {
        if (!draft || !selectedTile || !isDirty) {
            return;
        }

        setSaving(true);
        router.put(
            gridTiles.update.url([grid.id, selectedTile.id]),
            payloadOf(draft),
            {
                preserveScroll: true,
                preserveState: true,
                onError: setErrors,
                onSuccess: () => setErrors({}),
                onFinish: () => setSaving(false),
            },
        );
    };

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (
                (event.metaKey || event.ctrlKey) &&
                event.key.toLowerCase() === 's'
            ) {
                event.preventDefault();
                save();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    });

    const select = async (tileId: number | null) => {
        if (tileId === selectedId) {
            return;
        }

        if (
            isDirty &&
            !(await confirmDialog({
                title: 'Discard your tile changes?',
                description: 'You have unsaved edits on this tile.',
                confirmLabel: 'Discard',
                tone: 'destructive',
            }))
        ) {
            return;
        }

        const next = tiles.find((tile) => tile.id === tileId) ?? null;
        setErrors({});
        setSelectedId(tileId);
        setDraft(next ? draftOf(next) : null);
        lastServerKey.current = JSON.stringify(next ? draftOf(next) : null);
    };

    const reorder = (ids: number[]) => {
        setLocalOrder(ids);
        router.post(
            gridTiles.reorder.url(grid.id),
            { tile_ids: ids, expected_version: grid.version },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setLocalOrder(null),
            },
        );
    };

    const problemTileIds = grid.checklist.items
        .filter((item) => !item.passed && !item.soft)
        .flatMap((item) => item.tile_ids);
    const commentCounts = (comments ?? []).reduce<Record<number, number>>(
        (counts, comment) => {
            if (comment.grid_tile_id) {
                counts[comment.grid_tile_id] =
                    (counts[comment.grid_tile_id] ?? 0) + 1;
            }

            return counts;
        },
        {},
    );
    const PlatformIcon = platformIcon('instagram');
    const displayStatus = gridDisplayStatus(grid.status, grid.progress);
    const lockReason =
        grid.status === 'waiting_client'
            ? `This grid is with ${client.name}. It’s locked while they look.`
            : grid.status === 'archived'
              ? 'This grid is archived. Restore it to draft to change it.'
              : readOnly
                ? 'You can view this grid. Only the studio team can change it.'
                : null;

    return (
        <>
            <Head title={`${grid.name} — ${client.name}`} />
            <Page>
                <div className="-mb-4">
                    <Link
                        href={clientGrids(client.id)}
                        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm transition-colors"
                    >
                        <ArrowLeft className="size-4" />
                        {client.name} grids
                    </Link>
                </div>

                <PageHeader
                    eyebrow="GRID PLANNER"
                    title={grid.name}
                    meta={
                        <>
                            <Badge variant="outline" className="gap-1.5">
                                <PlatformIcon className="size-3.5" />
                                {grid.account.handle}
                            </Badge>
                            <StatusPill kind="gridPlan" value={displayStatus} />
                            {grid.campaign && (
                                <Link href={showCampaign(grid.campaign.id)}>
                                    <CampaignChip campaign={grid.campaign} />
                                </Link>
                            )}
                        </>
                    }
                />

                <GridWorkflow
                    grid={grid}
                    clientName={client.name}
                    allowedTransitions={allowedTransitions}
                    isDirty={isDirty}
                    canDelete={can.delete}
                    canOverrideApproval={can.override_approval}
                    canDuplicate={can.produce}
                />

                {lockReason && (
                    <p className="border-border bg-muted/50 text-muted-foreground -mt-2 flex items-center gap-2 rounded-xl border px-4 py-3 text-sm">
                        <Lock className="size-4 shrink-0" />
                        {lockReason}
                    </p>
                )}

                <div className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
                    <div className="grid min-w-0 gap-5">
                        <div className="grid gap-5 xl:grid-cols-2 xl:items-start">
                            <Panel
                                eyebrow={`${tiles.length} ${tiles.length === 1 ? 'tile' : 'tiles'} · ${grid.pinned_count} pinned`}
                                title="The board"
                                icon={LayoutGrid}
                                actions={
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.post(
                                                refreshFeed.url(grid.id),
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    only: ['feed'],
                                                },
                                            )
                                        }
                                    >
                                        <RefreshCw />
                                        Refresh profile
                                    </Button>
                                }
                            >
                                {tiles.length === 0 ? (
                                    <EmptyState
                                        compact
                                        icon={LayoutGrid}
                                        title="No tiles yet."
                                        description="Add a few images and we’ll show you how the profile will look."
                                        action={
                                            !readOnly && (
                                                <Button
                                                    type="button"
                                                    onClick={() => setAddAt(1)}
                                                >
                                                    <Plus />
                                                    Add tiles
                                                </Button>
                                            )
                                        }
                                    />
                                ) : (
                                    <GridBoard
                                        tiles={tiles}
                                        pinnedCount={grid.pinned_count}
                                        outside={grid.outside}
                                        selectedTileId={selectedId}
                                        hoveredTileIds={
                                            hoverTile ? [hoverTile] : []
                                        }
                                        problemTileIds={problemTileIds}
                                        commentCounts={commentCounts}
                                        readOnly={readOnly}
                                        onSelect={(tileId) =>
                                            void select(tileId)
                                        }
                                        onReorder={reorder}
                                        onAdd={setAddAt}
                                        onAddOutside={(postId) =>
                                            router.post(
                                                addOutsidePost.url([
                                                    grid.id,
                                                    postId,
                                                ]),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    />
                                )}
                            </Panel>

                            {selectedTile && draft ? (
                                <TileInspector
                                    key={selectedTile.id}
                                    gridId={grid.id}
                                    clientId={client.id}
                                    campaignId={grid.campaign_id}
                                    tile={selectedTile}
                                    draft={draft}
                                    errors={errors}
                                    timezone={grid.account.timezone}
                                    readOnly={readOnly}
                                    placeholderColours={placeholderColours}
                                    onChange={setDraft}
                                    onAddBefore={setAddAt}
                                    onClose={() => void select(null)}
                                />
                            ) : (
                                tiles.length > 0 && (
                                    <EmptyState
                                        compact
                                        icon={MousePointerClick}
                                        title="Pick a tile."
                                        description="Select a tile on the board to set its media, framing, caption idea and date."
                                    />
                                )
                            )}
                        </div>

                        {isDirty && (
                            <div
                                className={cn(
                                    'bg-card/95 shadow-paper border-foreground/20 sticky bottom-4 z-10 flex items-center justify-between gap-3 rounded-full border py-2 pr-2 pl-5 backdrop-blur',
                                )}
                            >
                                <span className="flex items-center gap-2 text-sm">
                                    <span className="bg-warning size-2 rounded-full" />
                                    Unsaved changes to tile{' '}
                                    {selectedTile?.position}
                                    <kbd className="text-muted-foreground hidden font-mono text-[11px] sm:inline">
                                        Ctrl/⌘ S
                                    </kbd>
                                </span>
                                <span className="flex items-center gap-1">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        disabled={saving}
                                        onClick={() => setDraft(serverDraft)}
                                    >
                                        Discard
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        disabled={saving}
                                        onClick={save}
                                    >
                                        {saving ? 'Saving…' : 'Save tile'}
                                    </Button>
                                </span>
                            </div>
                        )}

                        {grid.status === 'approved' && can.produce && (
                            <GridProduction
                                grid={grid}
                                clientName={client.name}
                                minGapMinutes={minGapMinutes}
                            />
                        )}

                        {can.use_ai && (
                            <GridAiPanel grid={grid} readOnly={readOnly} />
                        )}

                        <GridSettings
                            grid={grid}
                            campaignOptions={campaignOptions}
                            readOnly={readOnly}
                        />

                        <Deferred
                            data={['comments', 'activity']}
                            fallback={<Skeleton className="h-48 rounded-2xl" />}
                        >
                            <PostDiscussion<GridCommentData>
                                postId={grid.id}
                                comments={comments ?? []}
                                activityLogs={activity ?? []}
                                canComment={can.comment}
                                canResendReviewEmail={false}
                                mentionableUsers={mentionableUsers}
                                commentForm={storeGridComment.form(grid.id)}
                                statusKind="gridPlan"
                                composerExtra={
                                    tiles.length > 0 && (
                                        <label className="flex items-center gap-2 text-xs">
                                            About
                                            <select
                                                name="grid_tile_id"
                                                defaultValue={selectedId ?? ''}
                                                className="border-input bg-card rounded-lg border-2 px-2 py-1 text-xs"
                                            >
                                                <option value="">
                                                    The whole grid
                                                </option>
                                                {tiles.map((tile) => (
                                                    <option
                                                        key={tile.id}
                                                        value={tile.id}
                                                    >
                                                        Tile {tile.position}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                    )
                                }
                                renderChip={(comment) =>
                                    comment.tile_position ? (
                                        <Badge
                                            variant="electric"
                                            className="font-mono"
                                        >
                                            Tile {comment.tile_position}
                                        </Badge>
                                    ) : null
                                }
                                onCommentHover={(comment) =>
                                    setHoverTile(comment?.grid_tile_id ?? null)
                                }
                            />
                        </Deferred>
                    </div>

                    <aside className="grid gap-5 lg:sticky lg:top-20">
                        <ReadinessChecklist
                            title="Ready for the client"
                            items={grid.checklist.items.map((item) => ({
                                ...item,
                                detail: item.detail ?? undefined,
                            }))}
                            onJump={(key) => {
                                const item = grid.checklist.items.find(
                                    (candidate) => candidate.key === key,
                                );
                                const tileId = item?.tile_ids[0];

                                if (tileId) {
                                    void select(tileId);
                                }
                            }}
                            note={
                                isDirty
                                    ? 'Reflects the saved grid — save the tile to update it.'
                                    : undefined
                            }
                        />
                        <Panel
                            eyebrow="Preview"
                            title="On the profile"
                            bodyClassName="p-3 sm:p-4"
                        >
                            <GridPreview
                                grid={{ ...grid, tiles }}
                                feed={feed}
                                feedLoading={feed === undefined}
                                selectedTileId={selectedId}
                                highlightTileIds={hoverTile ? [hoverTile] : []}
                                onSelectTile={(tileId) => void select(tileId)}
                            />
                        </Panel>
                        {!isDirty && selectedTile && (
                            <p className="text-muted-foreground flex items-center justify-center gap-1.5 text-xs">
                                <Check className="text-success size-3.5" />
                                Tile {selectedTile.position} saved
                            </p>
                        )}
                    </aside>
                </div>

                {addAt !== null && (
                    <AddTilesDialog
                        open
                        onOpenChange={(open) => !open && setAddAt(null)}
                        gridId={grid.id}
                        clientId={client.id}
                        campaignId={grid.campaign_id}
                        atPosition={addAt}
                        studioImages={studioImages}
                        canUpload={can.create_asset}
                        placeholderColours={placeholderColours}
                    />
                )}
            </Page>
        </>
    );
}

GridEdit.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
