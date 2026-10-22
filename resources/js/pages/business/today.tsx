import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CalendarClock,
    CalendarPlus,
    CircleDot,
    ImageIcon,
    Plus,
    Sparkles,
} from 'lucide-react';
import { ChapterHero, EmptyState, Page, Panel, Stat } from '@/components/page';
import { Button } from '@/components/ui/button';
import { formatSchedule } from '@/lib/dates';
import { today as businessToday } from '@/routes/business';
import { index as accounts } from '@/routes/business/accounts';
import { edit as brand } from '@/routes/business/brand';
import { index as ideas } from '@/routes/business/ideas';
import { create as createPost } from '@/routes/business/posts';
import { edit as editPost } from '@/routes/posts';

type UpNext = {
    post_id: number;
    target_id: number;
    platform: string | null;
    platform_label: string | null;
    handle: string | null;
    caption: string | null;
    thumbnail_url: string | null;
    date: string | null;
    time: string | null;
    scheduled_at_utc: string | null;
};

type GapDay = { date: string; weekday: number; label: string };

type NeedsYouItem = {
    key: string;
    type: 'failed' | 'disconnected' | 'review' | 'stale_draft';
    title: string;
    subtitle: string;
    post_id: number | null;
    account_id: number | null;
};

type Dashboard = {
    brand: { id: number; name: string };
    upNext: UpNext[];
    gaps: {
        days: GapDay[];
        count: number;
        goalDays: number[];
        hasGoal: boolean;
    };
    needsYou: { items: NeedsYouItem[]; total: number };
    thisWeek: {
        publishedLast7: number;
        scheduledNext7: number;
        hasMetrics: boolean;
    };
    ideaOfTheDay: { title: string; description: string } | null;
};

function greeting(): string {
    const hour = new Date().getHours();

    if (hour < 12) {
        return 'Good morning';
    }

    if (hour < 18) {
        return 'Good afternoon';
    }

    return 'Good evening';
}

export default function Today() {
    const { dashboard } = usePage<{ dashboard: Dashboard }>().props;
    const { brand, upNext, gaps, needsYou, thisWeek } = dashboard;

    return (
        <Page>
            <Head title="Today" />

            <ChapterHero
                eyebrow={brand.name}
                title={
                    <>
                        {greeting()}. Here's <em>today</em>.
                    </>
                }
                description="Everything going out, everything that needs you, and where your week has gaps to fill."
                actions={
                    <>
                        <Button variant="spark" asChild>
                            <Link href={createPost()} method="post" as="button">
                                <Plus />
                                New post
                            </Link>
                        </Button>
                        <Button variant="on-ink" asChild>
                            <Link href={ideas()}>
                                <Sparkles />
                                Get ideas
                            </Link>
                        </Button>
                    </>
                }
            />

            <div className="grid gap-5 lg:grid-cols-[1.5fr_1fr]">
                <UpNextPanel upNext={upNext} />
                <NeedsYouPanel needsYou={needsYou} />
            </div>

            <div className="grid gap-5 lg:grid-cols-[1fr_1.5fr]">
                <ThisWeekPanel
                    thisWeek={thisWeek}
                    gapCount={gaps.hasGoal ? gaps.count : null}
                />
                <GapsPanel gaps={gaps} />
            </div>
        </Page>
    );
}

function UpNextPanel({ upNext }: { upNext: UpNext[] }) {
    return (
        <Panel
            icon={CalendarClock}
            eyebrow="Going out next"
            title="Up next"
            bodyClassName="p-0"
        >
            {upNext.length === 0 ? (
                <div className="p-6">
                    <EmptyState
                        compact
                        icon={CalendarPlus}
                        title="Nothing scheduled yet."
                        description="Create a post and pick a time — we'll put it out for you."
                        action={
                            <Button variant="brand" size="sm" asChild>
                                <Link
                                    href={createPost()}
                                    method="post"
                                    as="button"
                                >
                                    <Plus />
                                    New post
                                </Link>
                            </Button>
                        }
                    />
                </div>
            ) : (
                <ul className="divide-border/70 divide-y">
                    {upNext.map((item) => (
                        <li key={item.target_id}>
                            <Link
                                href={editPost(item.post_id)}
                                className="hover:bg-muted/50 flex items-center gap-4 px-5 py-3.5 transition-colors sm:px-6"
                            >
                                <Thumbnail url={item.thumbnail_url} />
                                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span className="truncate text-sm font-medium">
                                        {item.caption ?? 'Untitled post'}
                                    </span>
                                    <span className="text-muted-foreground truncate text-xs">
                                        {item.platform_label}
                                        {item.handle
                                            ? ` · @${item.handle}`
                                            : ''}
                                    </span>
                                </div>
                                <span className="eyebrow text-muted-foreground shrink-0 text-right normal-case">
                                    {formatSchedule(item.date, item.time)}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

function NeedsYouPanel({ needsYou }: { needsYou: Dashboard['needsYou'] }) {
    return (
        <Panel
            icon={AlertTriangle}
            eyebrow={
                needsYou.total > 0 ? `${needsYou.total} to handle` : 'All clear'
            }
            title="Needs you"
            bodyClassName="p-0"
        >
            {needsYou.items.length === 0 ? (
                <div className="p-6">
                    <EmptyState
                        compact
                        icon={CircleDot}
                        title="Nothing needs you."
                        description="No failed posts, expired connections or forgotten drafts."
                    />
                </div>
            ) : (
                <ul className="divide-border/70 divide-y">
                    {needsYou.items.map((item) => (
                        <li key={item.key}>
                            <Link
                                href={
                                    item.post_id
                                        ? editPost(item.post_id)
                                        : accounts()
                                }
                                className="hover:bg-muted/50 flex items-start gap-3 px-5 py-3.5 transition-colors sm:px-6"
                            >
                                <span
                                    aria-hidden
                                    className={
                                        'mt-1.5 size-2 shrink-0 rounded-full ' +
                                        (item.type === 'failed' ||
                                        item.type === 'disconnected'
                                            ? 'bg-destructive'
                                            : 'bg-warning')
                                    }
                                />
                                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span className="truncate text-sm font-medium">
                                        {item.title}
                                    </span>
                                    <span className="text-muted-foreground truncate text-xs">
                                        {item.subtitle}
                                    </span>
                                </div>
                                <ArrowRight className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

function ThisWeekPanel({
    thisWeek,
    gapCount,
}: {
    thisWeek: Dashboard['thisWeek'];
    gapCount: number | null;
}) {
    return (
        <div className="grid grid-cols-2 gap-5">
            <Stat
                tone="ink"
                label="Published · 7 days"
                value={thisWeek.publishedLast7}
                hint="Posts that went live this week."
            />
            <Stat
                tone="accent"
                label="Scheduled · 7 days"
                value={thisWeek.scheduledNext7}
                hint="Queued to go out next."
            />
            {gapCount !== null && (
                <Stat
                    label="Open days"
                    value={gapCount}
                    hint="In the next two weeks, vs. your goal."
                    className="col-span-2"
                />
            )}
        </div>
    );
}

function GapsPanel({ gaps }: { gaps: Dashboard['gaps'] }) {
    if (!gaps.hasGoal) {
        return (
            <Panel icon={CalendarPlus} eyebrow="Your rhythm" title="Gaps">
                <EmptyState
                    compact
                    icon={CalendarPlus}
                    title="Set a posting goal."
                    description="Tell us how often you want to post and we'll flag the days you've left open."
                    action={
                        <Button variant="brand" size="sm" asChild>
                            <Link href={brand()}>Set a goal</Link>
                        </Button>
                    }
                />
            </Panel>
        );
    }

    return (
        <Panel
            icon={CalendarPlus}
            eyebrow="Next two weeks"
            title="Gaps to fill"
            description={
                gaps.count === 0
                    ? "You're on track with your goal."
                    : `${gaps.count} day${gaps.count === 1 ? '' : 's'} with nothing scheduled.`
            }
        >
            {gaps.count === 0 ? (
                <EmptyState
                    compact
                    icon={CalendarClock}
                    title="No gaps. Nicely done."
                    description="Every day you aim to post on is covered."
                />
            ) : (
                <div className="flex flex-wrap gap-2">
                    {gaps.days.map((day) => (
                        <Link
                            key={day.date}
                            href={createPost()}
                            method="post"
                            as="button"
                            className="border-border bg-card hover:border-foreground/40 inline-flex items-center gap-2 rounded-full border px-3.5 py-2 text-sm font-medium transition-colors hover:shadow-sm"
                        >
                            <Plus className="text-muted-foreground size-3.5" />
                            {day.label}
                        </Link>
                    ))}
                </div>
            )}
        </Panel>
    );
}

function Thumbnail({ url }: { url: string | null }) {
    if (!url) {
        return (
            <span className="bg-muted text-muted-foreground flex size-11 shrink-0 items-center justify-center rounded-xl">
                <ImageIcon className="size-4" />
            </span>
        );
    }

    return (
        <img
            src={url}
            alt=""
            className="size-11 shrink-0 rounded-xl object-cover"
        />
    );
}

Today.layout = {
    breadcrumbs: [{ title: 'Today', href: businessToday() }],
};
