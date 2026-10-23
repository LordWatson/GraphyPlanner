import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback } from 'react';
import type { ConnectSlot } from '@/components/connect-slots';
import { ConnectSlotList } from '@/components/connect-slots';
import { useConnectPopup } from '@/hooks/use-connect-popup';
import { connect as connectPortalAccount } from '@/routes/portal/accounts';
import {
    Link2,
    ArrowRight,
    CalendarDays,
    CheckCircle2,
    Grid3x3,
    MessageSquareWarning,
} from 'lucide-react';

import { ChapterHero, EmptyState, Page, Panel } from '@/components/page';
import { PlatformIcon } from '@/components/platform-icon';
import { PortalPostRow } from '@/components/portal-post-row';
import { Button } from '@/components/ui/button';
import { calendar as portalCalendar } from '@/routes/portal';
import { show as portalGridsShow } from '@/routes/portal/grids';
import {
    index as portalPostsIndex,
    show as portalPostsShow,
} from '@/routes/portal/posts';
import type { Auth } from '@/types';

type PostRow = {
    post_id: number;
    status: string;
    status_label: string;
    reason: string;
    master_caption: string | null;
    platform: string | null;
    handle: string | null;
    /** Step M-6.5: this approval has been waiting past the agency's SLA. */
    overdue?: boolean;
};

type UpcomingPostRow = {
    post_id: number;
    target_id: number;
    master_caption: string | null;
    platform: string | null;
    handle: string | null;
    date: string;
    time: string;
    scheduled_at_utc: string;
};

type DashboardItems = {
    waitingApprovals: PostRow[];
    changesRequested: PostRow[];
    upcomingPosts: UpcomingPostRow[];
};

const dayFormatter = new Intl.DateTimeFormat('en-US', { day: '2-digit' });
const weekdayFormatter = new Intl.DateTimeFormat('en-US', { weekday: 'short' });
const monthFormatter = new Intl.DateTimeFormat('en-US', { month: 'short' });

function parseDate(date: string): Date {
    const [y, m, d] = date.split('-').map(Number);

    return new Date(y, m - 1, d);
}

function toRow(row: PostRow) {
    return {
        id: row.post_id,
        status: row.status,
        status_label: row.status_label,
        master_caption: row.master_caption,
        platform: row.platform,
        handle: row.handle,
    };
}

/**
 * Step 6.4 — the client portal's landing page. Built around the one job a client logs in for —
 * approving work — so outstanding approvals lead as an ink "action" block, with what's going out
 * next and what's back with the studio alongside.
 */
export default function PortalDashboard({
    client,
    items,
    connectAccounts = [],
    waitingGrids = [],
}: {
    client: { name: string };
    items: DashboardItems;
    /** Phase 18: grids sent to the client and waiting on their decision. */
    waitingGrids?: { id: number; name: string; tile_count: number }[];
    /** Step M-2.8: the client's own account slots, to connect without sharing a password. */
    connectAccounts?: ConnectSlot[];
}) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const refreshSlots = useCallback(
        () => router.reload({ only: ['connectAccounts'] }),
        [],
    );
    const { connectingId, connect } = useConnectPopup(refreshSlots);
    const unconnected = connectAccounts.filter((slot) => !slot.connected);
    const firstName = auth.user.name.split(' ')[0];
    const waiting = items.waitingApprovals;
    const next = waiting[0];

    return (
        <>
            <Head title={`${client.name} — Client portal`} />
            <Page>
                <ChapterHero
                    eyebrow={`Client portal — ${client.name}`}
                    title={
                        <>
                            Welcome back, <em>{firstName}</em>.
                        </>
                    }
                    description={
                        waiting.length > 0
                            ? `${waiting.length} post${waiting.length === 1 ? ' is' : 's are'} waiting on your sign-off. Everything else is moving along.`
                            : "You're all caught up — there's nothing waiting on your sign-off right now."
                    }
                    actions={
                        <>
                            {next && (
                                <Button size="lg" variant="spark" asChild>
                                    <Link href={portalPostsShow(next.post_id)}>
                                        Start reviewing
                                        <ArrowRight />
                                    </Link>
                                </Button>
                            )}
                            <Button size="lg" variant="on-ink" asChild>
                                <Link href={portalCalendar()}>
                                    <CalendarDays />
                                    Calendar
                                </Link>
                            </Button>
                        </>
                    }
                    aside={
                        waiting.length > 0 && (
                            <div className="flex flex-col items-start gap-2 lg:items-end">
                                <span className="eyebrow text-cream/70">
                                    Needs your approval
                                </span>
                                <span className="font-display text-lime text-[6.5rem] leading-[0.85] font-bold tracking-[-0.05em] tabular-nums sm:text-[8.5rem]">
                                    {String(waiting.length).padStart(2, '0')}
                                </span>
                                <span className="text-cream/80 text-sm">
                                    {waiting.length === 1
                                        ? 'Post ready for your review'
                                        : 'Posts ready for your review'}
                                </span>
                            </div>
                        )
                    }
                />

                {waitingGrids.length > 0 && (
                    <Panel
                        eyebrow="Your sign-off"
                        title={
                            waitingGrids.length === 1
                                ? 'Grid waiting on you'
                                : 'Grids waiting on you'
                        }
                        bodyClassName="p-2"
                    >
                        <ul className="flex flex-col">
                            {waitingGrids.map((grid) => (
                                <li key={grid.id}>
                                    <Link
                                        href={portalGridsShow(grid.id)}
                                        className="group hover:bg-muted/60 flex items-center gap-3.5 rounded-xl px-3 py-3 transition-colors"
                                    >
                                        <span className="bg-accent text-accent-foreground flex size-9 shrink-0 items-center justify-center rounded-full">
                                            <Grid3x3 className="size-4" />
                                        </span>
                                        <span className="flex min-w-0 flex-1 flex-col">
                                            <span className="group-hover:text-primary truncate text-sm font-medium">
                                                {grid.name}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                {grid.tile_count}{' '}
                                                {grid.tile_count === 1
                                                    ? 'tile'
                                                    : 'tiles'}{' '}
                                                · how your profile will look
                                            </span>
                                        </span>
                                        <span className="text-primary inline-flex shrink-0 items-center gap-1 text-xs font-semibold">
                                            See the grid
                                            <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}

                <div className="animate-rise grid grid-cols-1 gap-5 [animation-delay:80ms] lg:grid-cols-[1.35fr_1fr]">
                    {waiting.length > 0 ? (
                        <Panel
                            eyebrow="Your sign-off"
                            title="Ready for review"
                            actions={
                                waiting.length > 4 && (
                                    <Button variant="ghost" size="sm" asChild>
                                        <Link
                                            href={portalPostsIndex({
                                                query: {
                                                    status: 'waiting_client',
                                                },
                                            })}
                                        >
                                            All {waiting.length}
                                            <ArrowRight />
                                        </Link>
                                    </Button>
                                )
                            }
                            bodyClassName="p-2"
                        >
                            <ul className="flex flex-col">
                                {waiting.slice(0, 4).map((row) => (
                                    <li key={row.post_id}>
                                        <Link
                                            href={portalPostsShow(row.post_id)}
                                            className="group hover:bg-muted/60 flex items-center gap-3.5 rounded-xl px-3 py-3 transition-colors"
                                        >
                                            <PlatformIcon
                                                platform={row.platform}
                                            />
                                            <span className="flex min-w-0 flex-1 flex-col">
                                                <span className="group-hover:text-primary truncate text-sm font-medium">
                                                    {row.master_caption ||
                                                        'Untitled post'}
                                                </span>
                                                <span className="text-muted-foreground truncate text-xs capitalize">
                                                    {row.platform ??
                                                        'No platform yet'}
                                                    {row.handle
                                                        ? ` · ${row.handle}`
                                                        : ''}
                                                </span>
                                            </span>
                                            <span className="flex shrink-0 items-center gap-2">
                                                {row.overdue && (
                                                    <span className="bg-warning/15 text-warning inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold">
                                                        Overdue
                                                    </span>
                                                )}
                                                <span className="text-primary inline-flex items-center gap-1 text-xs font-semibold">
                                                    Review
                                                    <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
                                                </span>
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    ) : (
                        <EmptyState
                            icon={CheckCircle2}
                            title="All caught up."
                            description="Nothing needs your sign-off. We'll let you know the moment something's ready."
                            className="min-h-0 py-12"
                            action={
                                <Button variant="outline" asChild>
                                    <Link href={portalPostsIndex()}>
                                        Browse your posts
                                    </Link>
                                </Button>
                            }
                        />
                    )}

                    <Panel
                        eyebrow="Next 14 days"
                        title="Going out"
                        actions={
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={portalCalendar()}>
                                    Calendar
                                    <ArrowRight />
                                </Link>
                            </Button>
                        }
                        bodyClassName="p-2"
                    >
                        {items.upcomingPosts.length > 0 ? (
                            <ul className="flex flex-col">
                                {items.upcomingPosts.map((row) => {
                                    const date = parseDate(row.date);

                                    return (
                                        <li key={row.target_id}>
                                            <Link
                                                href={portalPostsShow(
                                                    row.post_id,
                                                )}
                                                className="group hover:bg-muted/60 flex items-center gap-4 rounded-xl px-3 py-2.5 transition-colors"
                                            >
                                                <span className="border-border flex w-11 shrink-0 flex-col items-center border-r pr-4">
                                                    <span className="font-display text-xl leading-none font-bold tabular-nums">
                                                        {dayFormatter.format(
                                                            date,
                                                        )}
                                                    </span>
                                                    <span className="eyebrow text-muted-foreground text-[9.5px]">
                                                        {weekdayFormatter.format(
                                                            date,
                                                        )}
                                                    </span>
                                                </span>
                                                <span className="flex min-w-0 flex-1 flex-col">
                                                    <span className="group-hover:text-primary truncate text-sm font-medium">
                                                        {row.master_caption ||
                                                            'Untitled post'}
                                                    </span>
                                                    <span className="text-muted-foreground truncate text-xs capitalize">
                                                        {row.platform}
                                                        {row.handle
                                                            ? ` · ${row.handle}`
                                                            : ''}{' '}
                                                        ·{' '}
                                                        {monthFormatter.format(
                                                            date,
                                                        )}
                                                    </span>
                                                </span>
                                                <span className="text-muted-foreground shrink-0 font-mono text-xs tabular-nums">
                                                    {row.time}
                                                </span>
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        ) : (
                            <EmptyState
                                compact
                                icon={CalendarDays}
                                title="A quiet fortnight."
                                description="Nothing is scheduled to go out in the next two weeks."
                                className="m-3 border-0"
                            />
                        )}
                    </Panel>
                </div>

                <Panel
                    eyebrow="With the studio"
                    title="Changes you've requested"
                    description="Posts you've sent back for revision — they'll return here for your approval."
                    icon={MessageSquareWarning}
                    bodyClassName="p-2"
                >
                    {items.changesRequested.length > 0 ? (
                        <div className="flex flex-col">
                            {items.changesRequested.map((row) => (
                                <PortalPostRow
                                    key={row.post_id}
                                    post={toRow(row)}
                                />
                            ))}
                        </div>
                    ) : (
                        <p className="text-muted-foreground px-3 py-6 text-center text-[13px]">
                            Nothing is back with the studio right now.
                        </p>
                    )}
                </Panel>

                {connectAccounts.length > 0 && (
                    <Panel
                        eyebrow="Your accounts"
                        title={
                            unconnected.length > 0
                                ? 'Connect your accounts'
                                : 'Your accounts are connected'
                        }
                        description={
                            unconnected.length > 0
                                ? 'Sign in on each network’s own page so the studio can publish for you — no passwords shared.'
                                : 'The studio can publish to every account. Reconnect here if a network asks you to.'
                        }
                        icon={Link2}
                    >
                        <ConnectSlotList
                            slots={connectAccounts}
                            connectingId={connectingId}
                            onConnect={(slot) =>
                                void connect(
                                    slot.id,
                                    connectPortalAccount.url(slot.id),
                                )
                            }
                        />
                    </Panel>
                )}
            </Page>
        </>
    );
}
