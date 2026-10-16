import { Head, Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    AlertTriangle,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    ImageOff,
    WifiOff,
} from 'lucide-react';
import { Page, PageHeader, StatStrip } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { needsAttention } from '@/routes';
import { show as showClient } from '@/routes/clients';
import { edit as editPost } from '@/routes/posts';
import { cn } from '@/lib/utils';

type PostRow = {
    post_id: number;
    client_id: number;
    client_name: string | null;
    status: string;
    status_label: string;
    reason: string;
    master_caption: string | null;
    platform: string | null;
    handle: string | null;
};

type DisconnectedAccountRow = {
    account_id: number;
    client_id: number;
    client_name: string | null;
    platform: string;
    platform_label: string;
    handle: string;
    connection_status: string;
    connection_status_label: string;
};

type NeedsAttentionItems = {
    failedPublishes: PostRow[];
    internalReviews: PostRow[];
    waitingApprovals: PostRow[];
    missingMedia: PostRow[];
    disconnectedAccounts: DisconnectedAccountRow[];
};

type HomeProps = {
    items: NeedsAttentionItems;
};

function Lane({
    id,
    index,
    icon: Icon,
    title,
    description,
    count,
    accentClassName,
    emptyLabel,
    children,
}: {
    id: string;
    index: string;
    icon: LucideIcon;
    title: string;
    description: string;
    count: number;
    accentClassName: string;
    emptyLabel: string;
    children: React.ReactNode;
}) {
    return (
        <section
            id={id}
            aria-labelledby={`${id}-title`}
            className="border-foreground/85 grid scroll-mt-24 grid-cols-1 gap-x-10 gap-y-4 border-t py-6 md:grid-cols-[minmax(0,300px)_minmax(0,1fr)]"
        >
            <header className="flex items-start gap-3.5">
                <span
                    className={cn(
                        'flex size-9 shrink-0 items-center justify-center rounded-full',
                        count > 0
                            ? accentClassName
                            : 'bg-muted text-muted-foreground',
                    )}
                >
                    <Icon className="size-[18px]" />
                </span>
                <div className="flex min-w-0 flex-col gap-1">
                    <span className="eyebrow text-muted-foreground">
                        {index}
                    </span>
                    <h2
                        id={`${id}-title`}
                        className="flex items-baseline gap-2.5 text-[17px] font-semibold tracking-[-0.01em]"
                    >
                        {title}
                        <span
                            className={cn(
                                'font-mono text-[13px] font-medium tabular-nums',
                                count === 0 && 'text-muted-foreground',
                            )}
                        >
                            {String(count).padStart(2, '0')}
                        </span>
                    </h2>
                    <p className="text-muted-foreground text-[13px]">
                        {description}
                    </p>
                </div>
            </header>
            {count > 0 ? (
                <ul className="border-border/80 bg-card divide-border/70 flex flex-col divide-y overflow-hidden rounded-2xl border shadow-sm">
                    {children}
                </ul>
            ) : (
                <p className="text-muted-foreground flex items-center gap-2 self-center text-sm">
                    <CheckCircle2 className="text-success size-4 shrink-0" />
                    <span>
                        <span className="text-foreground font-medium">
                            All clear.
                        </span>{' '}
                        {emptyLabel}
                    </span>
                </p>
            )}
        </section>
    );
}

function PostRowItem({ row }: { row: PostRow }) {
    return (
        <li>
            <Link
                href={editPost(row.post_id)}
                className="group hover:bg-muted/50 flex items-center justify-between gap-3 px-5 py-3.5 transition-colors"
            >
                <span className="flex min-w-0 flex-col">
                    <span className="group-hover:text-primary truncate text-sm font-medium">
                        {row.client_name ?? 'Unknown client'}
                    </span>
                    <span className="text-muted-foreground truncate text-xs">
                        <span className="capitalize">
                            {row.platform ?? 'No platform'}
                        </span>
                        {row.handle ? ` · ${row.handle}` : ''}
                        {row.master_caption ? ` · ${row.master_caption}` : ''}
                    </span>
                </span>
                <StatusPill
                    kind="post"
                    value={row.status}
                    label={row.status_label}
                />
            </Link>
        </li>
    );
}

export default function Home({ items }: HomeProps) {
    const total =
        items.failedPublishes.length +
        items.internalReviews.length +
        items.waitingApprovals.length +
        items.missingMedia.length +
        items.disconnectedAccounts.length;

    const lanes = [
        {
            id: 'lane-failed',
            label: '01 Failed',
            caption: 'Publishes rejected',
            count: items.failedPublishes.length,
        },
        {
            id: 'lane-accounts',
            label: '02 Accounts',
            caption: 'Need reconnecting',
            count: items.disconnectedAccounts.length,
        },
        {
            id: 'lane-review',
            label: '03 Review',
            caption: 'Studio sign-off',
            count: items.internalReviews.length,
        },
        {
            id: 'lane-approvals',
            label: '04 Approvals',
            caption: 'With the client',
            count: items.waitingApprovals.length,
        },
        {
            id: 'lane-media',
            label: '05 Assets',
            caption: 'Missing media',
            count: items.missingMedia.length,
        },
    ];

    return (
        <>
            <Head title="Needs attention" />
            <Page>
                <PageHeader
                    eyebrow="Triage — sorted by what to fix first"
                    title={
                        total > 0 ? (
                            <>
                                <em>{total}</em> thing{total === 1 ? '' : 's'}{' '}
                                need you.
                            </>
                        ) : (
                            <>
                                Nothing is <em>blocked</em>.
                            </>
                        )
                    }
                    description="Everything stopping a post or account from moving forward. Clear the top lanes first."
                />

                <StatStrip
                    className="animate-rise"
                    items={lanes.map((lane) => ({
                        label: lane.label,
                        value: (
                            <span
                                className={cn(
                                    lane.count === 0 &&
                                        'text-muted-foreground/45',
                                )}
                            >
                                {String(lane.count).padStart(2, '0')}
                            </span>
                        ),
                        caption: lane.caption,
                        render: (cell) => (
                            <a
                                href={`#${lane.id}`}
                                className="hover:bg-muted/50 block h-full rounded-lg transition-colors"
                            >
                                {cell}
                            </a>
                        ),
                    }))}
                />

                <div className="animate-rise flex flex-col [animation-delay:80ms]">
                    <Lane
                        id="lane-failed"
                        index="01 — Publishing"
                        icon={AlertTriangle}
                        title="Failed publishes"
                        description="Posts the platform rejected"
                        count={items.failedPublishes.length}
                        accentClassName="bg-destructive/10 text-destructive"
                        emptyLabel="No failed publishes."
                    >
                        {items.failedPublishes.map((row) => (
                            <PostRowItem key={row.post_id} row={row} />
                        ))}
                    </Lane>
                    <Lane
                        id="lane-accounts"
                        index="02 — Accounts"
                        icon={WifiOff}
                        title="Disconnected accounts"
                        description="Social accounts needing reconnection"
                        count={items.disconnectedAccounts.length}
                        accentClassName="bg-warning/12 text-warning"
                        emptyLabel="All accounts are connected."
                    >
                        {items.disconnectedAccounts.map((account) => (
                            <li key={account.account_id}>
                                <Link
                                    href={showClient(account.client_id)}
                                    className="group hover:bg-muted/50 flex items-center justify-between gap-3 px-5 py-3.5 transition-colors"
                                >
                                    <span className="flex min-w-0 flex-col">
                                        <span className="group-hover:text-primary truncate text-sm font-medium">
                                            {account.client_name ??
                                                'Unknown client'}
                                        </span>
                                        <span className="text-muted-foreground truncate text-xs">
                                            {account.platform_label} ·{' '}
                                            {account.handle}
                                        </span>
                                    </span>
                                    <StatusPill
                                        kind="connection"
                                        value={account.connection_status}
                                        label={account.connection_status_label}
                                    />
                                </Link>
                            </li>
                        ))}
                    </Lane>
                    <Lane
                        id="lane-review"
                        index="03 — Review"
                        icon={ClipboardCheck}
                        title="Internal review"
                        description="Posts waiting for the studio to sign off"
                        count={items.internalReviews.length}
                        accentClassName="bg-primary/10 text-primary"
                        emptyLabel="Nothing waiting on internal review."
                    >
                        {items.internalReviews.map((row) => (
                            <PostRowItem key={row.post_id} row={row} />
                        ))}
                    </Lane>
                    <Lane
                        id="lane-approvals"
                        index="04 — Approvals"
                        icon={Clock}
                        title="Waiting on the client"
                        description="Posts sitting in client review"
                        count={items.waitingApprovals.length}
                        accentClassName="bg-info/10 text-info"
                        emptyLabel="Nothing waiting on a client."
                    >
                        {items.waitingApprovals.map((row) => (
                            <PostRowItem key={row.post_id} row={row} />
                        ))}
                    </Lane>
                    <Lane
                        id="lane-media"
                        index="05 — Assets"
                        icon={ImageOff}
                        title="Missing media"
                        description="Posts that still need media attached"
                        count={items.missingMedia.length}
                        accentClassName="bg-muted text-foreground/60"
                        emptyLabel="Every active post has its required media."
                    >
                        {items.missingMedia.map((row) => (
                            <PostRowItem key={row.post_id} row={row} />
                        ))}
                    </Lane>
                </div>
            </Page>
        </>
    );
}

Home.layout = {
    breadcrumbs: [
        {
            title: 'Needs attention',
            href: needsAttention(),
        },
    ],
};
