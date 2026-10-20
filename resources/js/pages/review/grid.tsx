import { Head } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { GridDecision } from '@/components/grids/grid-decision';
import { GridPreview } from '@/components/grids/grid-preview';
import type {
    ClientGridPlan,
    GridCommentData,
    ProfileFeed,
} from '@/components/grids/types';
import { PublicShell, PublicTitle } from '@/components/public-shell';
import { StatusPill } from '@/components/status-pill';
import { decide } from '@/routes/review/grid';

/**
 * Step 18.8 — a grid review link (no account needed): the grid on the client's real profile is
 * the hero, beside the studio's note and exactly two decisions. Tap a tile for the full post.
 */
export default function ReviewGrid({
    token,
    client,
    grid,
    feed,
    comments,
    canDecide,
}: {
    token: string;
    client: { name: string };
    grid: ClientGridPlan;
    feed: ProfileFeed | null;
    comments: GridCommentData[];
    canDecide: boolean;
}) {
    return (
        <>
            <Head title={`Review a grid — ${client.name}`} />
            <PublicShell width="xl" context={client.name}>
                <PublicTitle
                    kicker={`For ${client.name}`}
                    title={
                        canDecide ? (
                            <>
                                Your new grid is ready to <em>look</em> at.
                            </>
                        ) : (
                            <>
                                Thanks — it&apos;s <em>reviewed</em>.
                            </>
                        )
                    }
                >
                    <StatusPill
                        kind="gridPlan"
                        value={grid.status}
                        label={grid.status_label}
                    />
                </PublicTitle>

                <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_400px] lg:gap-10">
                    {grid.review_message && (
                        <figure className="bg-ink text-ink-foreground relative rounded-2xl p-6 lg:col-start-2 lg:row-start-1">
                            <span
                                aria-hidden
                                className="text-lime font-display absolute -top-3 left-6 text-5xl leading-none font-bold"
                            >
                                “
                            </span>
                            <blockquote className="text-xl leading-snug font-light whitespace-pre-wrap">
                                {grid.review_message}
                            </blockquote>
                            <figcaption className="eyebrow text-ink-foreground/55 mt-3">
                                A note from the studio
                            </figcaption>
                        </figure>
                    )}

                    <section
                        aria-label="Grid preview"
                        className="border-border bg-card flex flex-col items-center gap-4 rounded-2xl border p-4 shadow-sm sm:p-8 lg:col-start-1 lg:row-span-3 lg:row-start-1"
                    >
                        <GridPreview grid={grid} feed={feed} />
                        <p className="eyebrow text-muted-foreground">
                            Tap a tile to see the full post.
                        </p>
                    </section>

                    <div className="flex flex-col gap-6 lg:sticky lg:top-6 lg:col-start-2">
                        {canDecide ? (
                            <GridDecision
                                url={decide.url(token)}
                                version={grid.version}
                                tiles={grid.tiles}
                            />
                        ) : (
                            <div className="border-border text-muted-foreground flex items-center gap-3 rounded-2xl border border-dashed p-5 text-sm">
                                <CheckCircle2 className="text-success size-5 shrink-0" />
                                This grid has already been reviewed — no further
                                action is needed.
                            </div>
                        )}

                        {comments.length > 0 && (
                            <section className="flex flex-col gap-3">
                                <h2 className="eyebrow text-muted-foreground border-foreground/85 border-b pb-2.5">
                                    Conversation
                                </h2>
                                {comments.map((comment) => (
                                    <div
                                        key={comment.id}
                                        className="border-border bg-card rounded-2xl rounded-tl-sm border p-4 text-sm"
                                    >
                                        {comment.tile_position && (
                                            <p className="eyebrow text-muted-foreground mb-1">
                                                Tile {comment.tile_position}
                                            </p>
                                        )}
                                        <p className="whitespace-pre-wrap">
                                            {comment.body}
                                        </p>
                                        <p className="text-muted-foreground mt-2 text-xs">
                                            — {comment.user_name ?? 'Team'}
                                        </p>
                                    </div>
                                ))}
                            </section>
                        )}
                    </div>
                </div>
            </PublicShell>
        </>
    );
}
