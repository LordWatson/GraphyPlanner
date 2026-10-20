import { router } from '@inertiajs/react';
import { CheckCircle2, Flag, MessageSquareWarning } from 'lucide-react';
import { useState } from 'react';
import { GridTileImage } from '@/components/grids/grid-tile-image';
import type { ClientGridTile } from '@/components/grids/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

const fieldClass =
    'border-input bg-background placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/40 flex w-full rounded-lg border-2 px-3.5 py-2.5 text-sm outline-none focus-visible:ring-[3px]';

/**
 * Step 18.8 — the client's decision on a whole grid: the note first, then exactly two decisions,
 * 56px tall. "Request changes" lets them flag tiles (tap a tile, say what to change), each becoming
 * a comment pinned to that tile. The grid `version` they saw goes with it, so a grid that changed
 * while they looked is never approved by mistake.
 */
export function GridDecision({
    url,
    version,
    tiles,
}: {
    url: string;
    version: number;
    tiles: ClientGridTile[];
}) {
    const [comment, setComment] = useState('');
    const [flagging, setFlagging] = useState(false);
    const [flagged, setFlagged] = useState<Record<number, string>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const decide = (decision: 'approved' | 'changes_requested') => {
        if (
            decision === 'changes_requested' &&
            !flagging &&
            comment.trim() === ''
        ) {
            setFlagging(true);

            return;
        }

        setProcessing(true);
        router.post(
            url,
            {
                decision,
                version,
                comment,
                tiles: decision === 'changes_requested' ? flagged : {},
            },
            {
                preserveScroll: true,
                onError: setErrors,
                onFinish: () => setProcessing(false),
            },
        );
    };

    const toggle = (tileId: number) =>
        setFlagged((current) => {
            const next = { ...current };

            if (tileId in next) {
                delete next[tileId];
            } else {
                next[tileId] = '';
            }

            return next;
        });

    return (
        <div className="border-border bg-card flex flex-col gap-4 rounded-2xl border p-5 shadow-sm sm:p-6">
            <p className="font-display text-xl leading-tight">
                How does the grid look?
            </p>
            <p className="text-muted-foreground -mt-2 text-sm text-pretty">
                You’re approving the look — the images, their order and framing.
                Each post’s caption comes to you separately.
            </p>
            <div className="grid gap-1.5">
                <textarea
                    rows={3}
                    value={comment}
                    onChange={(event) => setComment(event.target.value)}
                    aria-label="Note for the studio (optional)"
                    placeholder="Add a note (optional) — e.g. what you’d like changed"
                    className={fieldClass}
                />
                <InputError
                    message={errors.comment ?? errors.version ?? errors.to}
                />
            </div>

            {flagging && (
                <div className="grid gap-3">
                    <p className="flex items-center gap-2 text-sm font-medium">
                        <Flag className="size-4" />
                        Tap the tiles you’d like changed
                    </p>
                    <ul className="grid grid-cols-3 gap-1.5">
                        {tiles.map((tile) => {
                            const on = tile.id in flagged;

                            return (
                                <li key={tile.id}>
                                    <button
                                        type="button"
                                        aria-pressed={on}
                                        aria-label={`Tile ${tile.position}${on ? ', flagged' : ''}`}
                                        onClick={() => toggle(tile.id)}
                                        className={cn(
                                            'focus-visible:ring-ring relative block w-full overflow-hidden rounded-md outline-none focus-visible:ring-[3px]',
                                            on && 'ring-destructive ring-[3px]',
                                        )}
                                    >
                                        <GridTileImage tile={tile} />
                                        {on && (
                                            <span className="bg-destructive text-cream absolute top-1 right-1 flex size-5 items-center justify-center rounded-full">
                                                <Flag className="size-3" />
                                            </span>
                                        )}
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                    {Object.keys(flagged).map((id) => {
                        const tile = tiles.find(
                            (candidate) => candidate.id === Number(id),
                        );

                        return (
                            <div key={id} className="grid gap-1">
                                <label
                                    htmlFor={`flag-${id}`}
                                    className="text-xs font-medium"
                                >
                                    Tile {tile?.position} — what should change?
                                </label>
                                <Input
                                    id={`flag-${id}`}
                                    value={flagged[Number(id)]}
                                    onChange={(event) =>
                                        setFlagged((current) => ({
                                            ...current,
                                            [Number(id)]: event.target.value,
                                        }))
                                    }
                                    placeholder="e.g. A brighter photo here"
                                />
                            </div>
                        );
                    })}
                </div>
            )}

            <div className="flex flex-col gap-3">
                <Button
                    type="button"
                    size="lg"
                    className="bg-success text-success-foreground hover:bg-success/90 h-14 text-base"
                    disabled={processing}
                    onClick={() => decide('approved')}
                >
                    <CheckCircle2 className="size-5" />
                    Approve the grid
                </Button>
                <Button
                    type="button"
                    size="lg"
                    variant="outline"
                    className="border-destructive/35 text-destructive hover:border-destructive/60 hover:bg-destructive/5 h-14 text-base"
                    disabled={
                        processing ||
                        (flagging &&
                            comment.trim() === '' &&
                            Object.keys(flagged).length === 0)
                    }
                    onClick={() => decide('changes_requested')}
                >
                    <MessageSquareWarning className="size-5" />
                    {flagging ? 'Send my changes' : 'Request changes'}
                </Button>
            </div>
        </div>
    );
}
