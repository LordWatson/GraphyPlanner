import { router } from '@inertiajs/react';
import { Check, Loader2, Scale, Sparkles, Wand2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { getXsrfTokenFromCookie } from '@/components/clients/client-layout';
import type { GridPlanData, GridTileData } from '@/components/grids/types';
import { Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import gridAi from '@/routes/grids/ai';
import gridTiles from '@/routes/grids/tiles';
import { show as showGeneration } from '@/routes/ai-image-generations';

async function postJson<T>(
    url: string,
    body: Record<string, unknown> = {},
): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': getXsrfTokenFromCookie(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    const json = (await response.json().catch(() => ({}))) as T & {
        message?: string;
    };

    if (!response.ok) {
        throw new Error(
            json.message ?? 'Something went wrong. Try again in a moment.',
        );
    }

    return json;
}

type StudioVariant = {
    id: number;
    ready: boolean;
    pending: boolean;
    previewUrl: string | null;
    error: string | null;
};

/**
 * Step 18.12 — "Fill this slot": a 3:4 Image Studio set drafted from the placeholder's note and
 * the Brand Brain. The team picks one; nothing lands on the tile before that.
 */
function FillSlotDialog({
    gridId,
    tile,
    onClose,
}: {
    gridId: number;
    tile: GridTileData | null;
    onClose: () => void;
}) {
    const [generationId, setGenerationId] = useState<number | null>(null);
    const [variants, setVariants] = useState<StudioVariant[]>([]);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!tile) {
            setGenerationId(null);
            setVariants([]);
            setError(null);

            return;
        }

        postJson<{ generation_id: number }>(
            gridTiles.ai.fill.url([gridId, tile.id]),
            { request_id: crypto.randomUUID() },
        )
            .then((json) => setGenerationId(json.generation_id))
            .catch((reason: Error) => setError(reason.message));
    }, [tile?.id]);

    useEffect(() => {
        if (generationId === null) {
            return;
        }

        let stopped = false;
        const poll = async () => {
            const response = await fetch(showGeneration.url(generationId), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const json = (await response.json()) as {
                generation?: { settled: boolean; variants: StudioVariant[] };
            };

            if (stopped || !json.generation) {
                return;
            }

            setVariants(json.generation.variants);

            if (!json.generation.settled) {
                window.setTimeout(() => void poll(), 2500);
            }
        };

        void poll();

        return () => {
            stopped = true;
        };
    }, [generationId]);

    return (
        <Dialog
            open={tile !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Fill tile {tile?.position}</DialogTitle>
                    <DialogDescription>
                        Drafted at 3:4 from your note and the Brand Brain. Pick
                        one to use it.
                    </DialogDescription>
                </DialogHeader>
                {error ? (
                    <p className="text-destructive text-sm">{error}</p>
                ) : (
                    <ul className="grid grid-cols-2 gap-3">
                        {(variants.length > 0 ? variants : [null, null]).map(
                            (variant, index) => (
                                <li
                                    key={variant?.id ?? index}
                                    className="flex flex-col gap-2"
                                >
                                    {variant?.ready && variant.previewUrl ? (
                                        <img
                                            src={variant.previewUrl}
                                            alt=""
                                            className="aspect-[3/4] w-full rounded-lg object-cover"
                                        />
                                    ) : variant?.error ? (
                                        <div className="bg-muted text-muted-foreground flex aspect-[3/4] items-center justify-center rounded-lg p-3 text-center text-xs">
                                            {variant.error}
                                        </div>
                                    ) : (
                                        <Skeleton className="aspect-[3/4] w-full rounded-lg" />
                                    )}
                                    <Button
                                        type="button"
                                        size="sm"
                                        disabled={!variant?.ready}
                                        onClick={() =>
                                            variant &&
                                            tile &&
                                            router.post(
                                                gridTiles.ai.use.url([
                                                    gridId,
                                                    tile.id,
                                                ]),
                                                { variant_id: variant.id },
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: onClose,
                                                },
                                            )
                                        }
                                    >
                                        <Check />
                                        Use this one
                                    </Button>
                                </li>
                            ),
                        )}
                    </ul>
                )}
            </DialogContent>
        </Dialog>
    );
}

/**
 * Step 18.12 — AI help for the grid, suggestions only: caption ideas per tile ("Use" saves one as
 * the tile's caption idea) and a balance check the team acts on by hand. Shown only when AI is on
 * and the viewer may use it.
 */
export function GridAiPanel({
    grid,
    readOnly,
}: {
    grid: GridPlanData;
    readOnly: boolean;
}) {
    const [captions, setCaptions] = useState<Record<string, string[]> | null>(
        null,
    );
    const [notes, setNotes] = useState<string[] | null>(null);
    const [loading, setLoading] = useState<'captions' | 'balance' | null>(null);
    const [filling, setFilling] = useState<GridTileData | null>(null);
    const placeholders = grid.tiles.filter(
        (tile) => tile.kind === 'placeholder',
    );
    const tileById = new Map(grid.tiles.map((tile) => [String(tile.id), tile]));

    const run = async (kind: 'captions' | 'balance') => {
        setLoading(kind);

        try {
            if (kind === 'captions') {
                const json = await postJson<{
                    suggestions: Record<string, string[]>;
                }>(gridAi.captions.url(grid.id));
                setCaptions(json.suggestions);

                if (Object.keys(json.suggestions).length === 0) {
                    toast.info(
                        'No ideas came back this time. Try again in a moment.',
                    );
                }
            } else {
                const json = await postJson<{ notes: string[] }>(
                    gridAi.balance.url(grid.id),
                );
                setNotes(json.notes);
            }
        } catch (reason) {
            toast.error(
                reason instanceof Error ? reason.message : 'That didn’t work.',
            );
        } finally {
            setLoading(null);
        }
    };

    return (
        <Panel
            eyebrow="AI assist"
            title="A second opinion"
            icon={Sparkles}
            description="Suggestions only. Nothing changes until you use one."
        >
            <div className="grid gap-4">
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={loading !== null || readOnly}
                        onClick={() => void run('captions')}
                    >
                        {loading === 'captions' ? (
                            <Loader2 className="animate-spin" />
                        ) : (
                            <Wand2 />
                        )}
                        Suggest captions
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={loading !== null}
                        onClick={() => void run('balance')}
                    >
                        {loading === 'balance' ? (
                            <Loader2 className="animate-spin" />
                        ) : (
                            <Scale />
                        )}
                        Check the balance
                    </Button>
                    {!readOnly &&
                        placeholders.map((tile) => (
                            <Button
                                key={tile.id}
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => setFilling(tile)}
                            >
                                <Sparkles />
                                Fill tile {tile.position}
                            </Button>
                        ))}
                </div>

                {notes !== null && (
                    <ul className="grid gap-1.5 text-sm">
                        {notes.length === 0 ? (
                            <li className="text-muted-foreground">
                                Nothing to flag.
                            </li>
                        ) : (
                            notes.map((note) => (
                                <li
                                    key={note}
                                    className="border-border rounded-lg border px-3 py-2"
                                >
                                    {note}
                                </li>
                            ))
                        )}
                    </ul>
                )}

                {captions !== null && (
                    <ul className="grid gap-3">
                        {Object.entries(captions).map(([tileId, ideas]) => (
                            <li key={tileId} className="grid gap-1.5">
                                <span className="eyebrow text-muted-foreground">
                                    Tile {tileById.get(tileId)?.position}
                                </span>
                                {ideas.map((idea) => (
                                    <div
                                        key={idea}
                                        className="border-border flex items-start justify-between gap-3 rounded-lg border px-3 py-2 text-sm"
                                    >
                                        <span className="text-pretty">
                                            {idea}
                                        </span>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            disabled={readOnly}
                                            onClick={() =>
                                                router.put(
                                                    gridTiles.update.url([
                                                        grid.id,
                                                        Number(tileId),
                                                    ]),
                                                    { caption_idea: idea },
                                                    {
                                                        preserveScroll: true,
                                                        preserveState: true,
                                                    },
                                                )
                                            }
                                        >
                                            Use
                                        </Button>
                                    </div>
                                ))}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <FillSlotDialog
                gridId={grid.id}
                tile={filling}
                onClose={() => setFilling(null)}
            />
        </Panel>
    );
}
