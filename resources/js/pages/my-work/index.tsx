import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    AtSign,
    CalendarClock,
    CalendarDays,
    CheckCircle2,
    ClipboardList,
    Eye,
    Hand,
    type LucideIcon,
} from 'lucide-react';
import { HomeViewTabs } from '@/components/home-view-tabs';
import {
    ChapterHero,
    EmptyState,
    HeroStat,
    Page,
    Panel,
} from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { StatusPill } from '@/components/status-pill';
import type { StoryHandoff } from '@/components/stories/story-handoff-pill';
import { StoryHandoffPill } from '@/components/stories/story-handoff-pill';
import type { StoryQueueRow } from '@/components/stories/story-row';
import { StoryRow } from '@/components/stories/story-row';
import { Button } from '@/components/ui/button';
import { formatDate, formatSchedule } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { calendar, dashboard } from '@/routes';
import { index as contentIndex } from '@/routes/content';
import { index as myWorkIndex } from '@/routes/my-work';
import { edit as editPost } from '@/routes/posts';
import { queue as storiesQueue } from '@/routes/stories';

type Row = {
    id: number;
    status: string;
    status_label: string;
    /** Step S-5.1: the most urgent assisted-lane state across the post's channels. */
    handoff?: StoryHandoff | null;
    master_caption: string | null;
    client: { id: number; name: string | null };
    due_at: string | null;
    scheduled_local_date: string | null;
    scheduled_local_time: string | null;
    targets: { platform: string | null }[];
};

type Buckets = {
    /** Step S-5.2: the viewer's own Stories to post by hand, ready now or due within a day. */
    stories?: StoryQueueRow[];
    due: Row[];
    review: Row[];
    assigned: Row[];
    mentioned: Row[];
};

const SECTIONS: {
    key: Exclude<keyof Buckets, 'stories'>;
    title: string;
    description: string;
    icon: LucideIcon;
    empty: string;
}[] = [
    {
        key: 'due',
        title: 'Due soon',
        description:
            'Assigned to you and due in the next few days, or overdue.',
        icon: CalendarClock,
        empty: 'Nothing due soon.',
    },
    {
        key: 'review',
        title: 'Waiting on your review',
        description: 'Posts in internal review with you as the reviewer.',
        icon: Eye,
        empty: 'No reviews waiting.',
    },
    {
        key: 'assigned',
        title: 'Assigned to you',
        description: 'Everything else on your plate.',
        icon: ClipboardList,
        empty: 'Nothing else assigned.',
    },
    {
        key: 'mentioned',
        title: 'You were mentioned',
        description: 'Posts where a teammate tagged you.',
        icon: AtSign,
        empty: 'No mentions.',
    },
];

/**
 * Step M-5.4 — "My work": one staff member's posts across every client, in four lenses. Each row
 * opens the editor. The due date is overdue-red when it's past.
 *
 * Step S-5.2: when the viewer has Stories to post by hand, they lead the page — the ready ones
 * are the only thing here with a clock running.
 */
export default function MyWork({ buckets }: { buckets: Buckets }) {
    const stories = buckets.stories ?? [];
    const readyStories = stories.filter((story) => story.state === 'ready');
    const total =
        buckets.due.length +
        buckets.review.length +
        buckets.assigned.length +
        readyStories.length;

    return (
        <>
            <Head title="My work" />
            <Page>
                <ChapterHero
                    eyebrow="Across every client"
                    title={
                        total > 0 ? (
                            <>
                                Your <em>work</em>.
                            </>
                        ) : (
                            <>
                                A clear <em>desk</em>.
                            </>
                        )
                    }
                    description="Everything assigned to you, waiting on your review, due soon, or where you were mentioned."
                    actions={
                        <>
                            <Button variant="spark" size="lg" asChild>
                                <Link href={calendar()}>
                                    <CalendarDays />
                                    Open calendar
                                </Link>
                            </Button>
                            <Button variant="on-ink" size="lg" asChild>
                                <Link href={contentIndex()}>
                                    See everything
                                    <ArrowRight />
                                </Link>
                            </Button>
                        </>
                    }
                    aside={
                        <HeroStat
                            icon={ClipboardList}
                            eyebrow="On your plate"
                            value={String(total).padStart(2, '0')}
                            caption={total === 1 ? 'Open item' : 'Open items'}
                        />
                    }
                />

                <HomeViewTabs className="animate-rise" />

                {stories.length > 0 && (
                    <Panel
                        eyebrow={`${readyStories.length} ready`}
                        title="Stories to post"
                        description="Stories with a link or a sticker go out by hand. Open one when it's ready."
                        icon={Hand}
                        actions={
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                className="h-11 sm:h-8"
                            >
                                <Link href={storiesQueue()}>
                                    Open the queue
                                </Link>
                            </Button>
                        }
                        bodyClassName="p-0 sm:p-0"
                    >
                        <ul className="divide-border/70 divide-y">
                            {stories.map((story) => (
                                <li key={story.target_id}>
                                    <StoryRow story={story} />
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}

                {SECTIONS.map((section) => {
                    const rows = buckets[section.key];

                    return (
                        <Panel
                            key={section.key}
                            eyebrow={`${rows.length}`}
                            title={section.title}
                            description={section.description}
                            icon={section.icon}
                            bodyClassName="p-0 sm:p-0"
                        >
                            {rows.length === 0 ? (
                                <EmptyState
                                    compact
                                    icon={CheckCircle2}
                                    title={section.empty}
                                    description="Nothing to do here right now."
                                />
                            ) : (
                                <ul className="divide-border/70 divide-y">
                                    {rows.map((row) => (
                                        <WorkRow
                                            key={row.id}
                                            row={row}
                                            showDue={section.key === 'due'}
                                        />
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    );
                })}
            </Page>
        </>
    );
}

function WorkRow({ row, showDue }: { row: Row; showDue: boolean }) {
    const overdue =
        row.due_at !== null && new Date(row.due_at).getTime() < Date.now();

    return (
        <li>
            <Link
                href={editPost(row.id)}
                className="hover:bg-muted/50 flex items-center gap-3 px-5 py-3 transition-colors"
            >
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">
                        {row.master_caption || 'Untitled post'}
                    </span>
                    <span className="text-muted-foreground block truncate text-xs">
                        {row.client.name ?? '—'}
                    </span>
                    <StoryHandoffPill handoff={row.handoff} className="mt-1" />
                </span>
                <span className="text-muted-foreground hidden items-center gap-1.5 sm:flex">
                    {row.targets.map((target, i) => {
                        const Icon = platformIcon(target.platform);

                        return <Icon key={i} className="size-4" />;
                    })}
                </span>
                {showDue && row.due_at ? (
                    <span
                        className={cn(
                            'font-mono text-xs',
                            overdue
                                ? 'text-destructive'
                                : 'text-muted-foreground',
                        )}
                    >
                        {overdue ? 'Overdue · ' : 'Due '}
                        {formatDate(row.due_at)}
                    </span>
                ) : (
                    <span className="text-muted-foreground hidden font-mono text-xs md:block">
                        {formatSchedule(
                            row.scheduled_local_date,
                            row.scheduled_local_time,
                            'Unscheduled',
                        )}
                    </span>
                )}
                <StatusPill
                    kind="post"
                    value={row.status}
                    label={row.status_label}
                />
            </Link>
        </li>
    );
}

MyWork.layout = {
    breadcrumbs: [
        { title: 'Home', href: dashboard() },
        { title: 'My work', href: myWorkIndex() },
    ],
};
