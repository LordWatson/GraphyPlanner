import { Head, Link } from '@inertiajs/react';
import {
    AtSign,
    CalendarClock,
    CheckCircle2,
    ClipboardList,
    Eye,
    type LucideIcon,
} from 'lucide-react';
import { HomeViewTabs } from '@/components/home-view-tabs';
import { EmptyState, Page, PageHeader, Panel } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { StatusPill } from '@/components/status-pill';
import { formatDate, formatSchedule } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as contentIndex } from '@/routes/content';
import { index as myWorkIndex } from '@/routes/my-work';
import { edit as editPost } from '@/routes/posts';

type Row = {
    id: number;
    status: string;
    status_label: string;
    master_caption: string | null;
    client: { id: number; name: string | null };
    due_at: string | null;
    scheduled_local_date: string | null;
    scheduled_local_time: string | null;
    targets: { platform: string | null }[];
};

type Buckets = {
    due: Row[];
    review: Row[];
    assigned: Row[];
    mentioned: Row[];
};

const SECTIONS: {
    key: keyof Buckets;
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
 */
export default function MyWork({ buckets }: { buckets: Buckets }) {
    const total =
        buckets.due.length + buckets.review.length + buckets.assigned.length;

    return (
        <>
            <Head title="My work" />
            <Page>
                <PageHeader
                    eyebrow={`${String(total).padStart(2, '0')} on your plate`}
                    title={
                        <>
                            Your <em>work</em>.
                        </>
                    }
                    description="Everything assigned to you, waiting on your review, due soon, or where you were mentioned."
                />

                <HomeViewTabs className="animate-rise" />

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

                <p className="text-muted-foreground text-sm">
                    Looking for everything across the studio?{' '}
                    <Link
                        href={contentIndex()}
                        className="text-primary underline"
                    >
                        Open the content hub
                    </Link>
                    .
                </p>
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
