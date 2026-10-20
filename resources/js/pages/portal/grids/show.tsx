import { Deferred, Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, MessagesSquare } from 'lucide-react';
import { GridDecision } from '@/components/grids/grid-decision';
import { GridPreview } from '@/components/grids/grid-preview';
import type {
    ClientGridPlan,
    GridCommentData,
    ProfileFeed,
} from '@/components/grids/types';
import InputError from '@/components/input-error';
import type { MentionableUser } from '@/components/mention-textarea';
import {
    MentionTextarea,
    renderCommentBody,
} from '@/components/mention-textarea';
import { Page, PageHeader, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/dates';
import { decide, index as portalGrids } from '@/routes/portal/grids';
import { store as commentOnGrid } from '@/routes/portal/grids/comments';

/**
 * Step 18.8 — one grid in the client portal: the same preview as the review link, the decision
 * beside it (when it's waiting on them), and a conversation with the studio — every comment can be
 * about the whole grid or one tile.
 */
export default function PortalGridShow({
    grid,
    feed,
    comments,
    can,
    mentionableUsers,
}: {
    grid: ClientGridPlan;
    feed?: ProfileFeed | null;
    comments: GridCommentData[];
    can: { decide: boolean; comment: boolean };
    mentionableUsers: MentionableUser[];
}) {
    const title =
        grid.status === 'waiting_client' ? (
            <>
                Ready for your <em>review</em>.
            </>
        ) : grid.status === 'changes_requested' ? (
            <>
                Back with the <em>studio</em>.
            </>
        ) : (
            <>
                The look is <em>approved</em>.
            </>
        );

    return (
        <>
            <Head title={`${grid.name} — Client portal`} />
            <Page>
                <div className="-mb-4">
                    <Link
                        href={portalGrids()}
                        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm transition-colors"
                    >
                        <ArrowLeft className="size-4" />
                        Your grids
                    </Link>
                </div>
                <PageHeader
                    eyebrow={grid.name}
                    title={title}
                    meta={
                        <StatusPill
                            kind="gridPlan"
                            value={grid.status}
                            label={grid.status_label}
                        />
                    }
                />

                <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_400px] lg:gap-10">
                    <section className="border-border bg-card flex flex-col items-center gap-4 rounded-2xl border p-4 shadow-sm sm:p-8">
                        <Deferred
                            data="feed"
                            fallback={<GridPreview grid={grid} feedLoading />}
                        >
                            <GridPreview grid={grid} feed={feed} />
                        </Deferred>
                        <p className="eyebrow text-muted-foreground">
                            Tap a tile to see the full post.
                        </p>
                    </section>

                    <div className="flex flex-col gap-6 lg:sticky lg:top-20">
                        {grid.review_message && (
                            <figure className="bg-ink text-ink-foreground relative rounded-2xl p-6">
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

                        {can.decide ? (
                            <GridDecision
                                url={decide.url(grid.id)}
                                version={grid.version}
                                tiles={grid.tiles}
                            />
                        ) : (
                            <div className="border-border text-muted-foreground flex items-center gap-3 rounded-2xl border border-dashed p-5 text-sm">
                                <CheckCircle2 className="text-success size-5 shrink-0" />
                                Nothing needed from you on this grid right now.
                            </div>
                        )}

                        <Panel title="Conversation" icon={MessagesSquare}>
                            <div className="grid gap-4">
                                {comments.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        No comments yet.
                                    </p>
                                ) : (
                                    <ol className="grid gap-3">
                                        {comments.map((comment) => (
                                            <li
                                                key={comment.id}
                                                className="border-border rounded-2xl rounded-tl-sm border p-3 text-sm"
                                            >
                                                {comment.tile_position && (
                                                    <p className="eyebrow text-muted-foreground mb-1">
                                                        Tile{' '}
                                                        {comment.tile_position}
                                                    </p>
                                                )}
                                                <p className="whitespace-pre-wrap">
                                                    {renderCommentBody(
                                                        comment.body,
                                                    )}
                                                </p>
                                                <p className="text-muted-foreground mt-2 flex justify-between gap-2 text-xs">
                                                    <span>
                                                        —{' '}
                                                        {comment.user_name ??
                                                            'Team'}
                                                    </span>
                                                    <span className="font-mono">
                                                        {formatDateTime(
                                                            comment.created_at,
                                                        )}
                                                    </span>
                                                </p>
                                            </li>
                                        ))}
                                    </ol>
                                )}
                                {can.comment && (
                                    <Form
                                        {...commentOnGrid.form(grid.id)}
                                        resetOnSuccess={['body']}
                                        options={{ preserveScroll: true }}
                                        className="flex flex-col gap-2.5"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <MentionTextarea
                                                    name="body"
                                                    rows={3}
                                                    placeholder="Write to the team… type @ to mention someone"
                                                    users={mentionableUsers}
                                                />
                                                <label className="flex items-center gap-2 text-xs">
                                                    About
                                                    <select
                                                        name="grid_tile_id"
                                                        defaultValue=""
                                                        className="border-input bg-card rounded-lg border-2 px-2 py-1 text-xs"
                                                    >
                                                        <option value="">
                                                            The whole grid
                                                        </option>
                                                        {grid.tiles.map(
                                                            (tile) => (
                                                                <option
                                                                    key={
                                                                        tile.id
                                                                    }
                                                                    value={
                                                                        tile.id
                                                                    }
                                                                >
                                                                    Tile{' '}
                                                                    {
                                                                        tile.position
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                </label>
                                                <InputError
                                                    message={
                                                        errors.body ??
                                                        errors.grid_tile_id
                                                    }
                                                />
                                                <Button
                                                    type="submit"
                                                    variant="secondary"
                                                    size="sm"
                                                    className="self-end"
                                                    disabled={processing}
                                                >
                                                    Post comment
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                )}
                            </div>
                        </Panel>
                    </div>
                </div>
            </Page>
        </>
    );
}
