import { Head, Link, router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    AlertTriangle,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    Grid3x3,
    ImageOff,
    Megaphone,
    Recycle,
    WifiOff,
} from 'lucide-react';
import { CampaignChip } from '@/components/campaigns/campaign-chip';
import { HomeViewTabs } from '@/components/home-view-tabs';
import { Page, PageHeader, StatStrip } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { dashboard, needsAttention } from '@/routes';
import { show as showCampaign } from '@/routes/campaigns';
import { show as showClient } from '@/routes/clients';
import { edit as editGrid } from '@/routes/grids';
import { edit as editPost, recycle as recyclePost } from '@/routes/posts';
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
    /** Step M-6.5: past the org's approval SLA. */
    overdue?: boolean;
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
    /** Step M-2.7: why a still-connected account is here (its connection is expiring). */
    reason?: string | null;
};

/** Step I-2.2: each lane carries a bounded page of rows plus the lane's full count. */
type Bucket<Row> = { items: Row[]; total: number };

/** Step M-5.8: an evergreen post past its recycle window, ready to repost. */
type RecycleRow = PostRow & { recycle_after_days: number };

type NeedsAttentionItems = {
    failedPublishes: Bucket<PostRow>;
    internalReviews: Bucket<PostRow>;
    waitingApprovals: Bucket<PostRow>;
    missingMedia: Bucket<PostRow>;
    disconnectedAccounts: Bucket<DisconnectedAccountRow>;
    campaigns: Bucket<CampaignRow>;
    /** Step 18.11: grids whose publish order is at risk. */
    gridRisks?: Bucket<GridRiskRow>;
    /** Step M-5.8: evergreen posts ready to recycle. */
    recyclingSuggestions?: Bucket<RecycleRow>;
};

type GridRiskRow = {
    grid_plan_id: number;
    name: string;
    client_id: number;
    client_name: string;
    reason: string;
    later_count: number;
};

/** Campaigns enhancement C-1.4: an open campaign in trouble, with the reason in words. */
type CampaignRow = {
    campaign_id: number;
    name: string;
    code: string | null;
    colour: string | null;
    client_id: number;
    client_name: string | null;
    status: string;
    status_label: string;
    reason: string;
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
    shown,
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
    /** How many of `count` rows are rendered (the server caps each lane). */
    shown: number;
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
                <div className="flex flex-col gap-2">
                    <ul className="border-border/80 bg-card divide-border/70 flex flex-col divide-y overflow-hidden rounded-2xl border shadow-sm">
                        {children}
                    </ul>
                    {shown < count && (
                        <p className="text-muted-foreground px-1 text-xs">
                            Showing the oldest {shown} of {count}.
                        </p>
                    )}
                </div>
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
                <span className="flex shrink-0 items-center gap-2">
                    {row.overdue && (
                        <span className="bg-warning/15 text-warning inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold">
                            Overdue
                        </span>
                    )}
                    <StatusPill
                        kind="post"
                        value={row.status}
                        label={row.status_label}
                    />
                </span>
            </Link>
        </li>
    );
}

export default function Home({ items }: HomeProps) {
    const total =
        items.failedPublishes.total +
        items.internalReviews.total +
        items.waitingApprovals.total +
        items.missingMedia.total +
        items.disconnectedAccounts.total +
        items.campaigns.total +
        (items.gridRisks?.total ?? 0) +
        (items.recyclingSuggestions?.total ?? 0);
    const gridRisks = items.gridRisks ?? { items: [], total: 0 };
    const recycling = items.recyclingSuggestions ?? { items: [], total: 0 };

    const lanes = [
        {
            id: 'lane-failed',
            label: '01 Failed',
            caption: 'Publishes rejected',
            count: items.failedPublishes.total,
        },
        {
            id: 'lane-accounts',
            label: '02 Accounts',
            caption: 'Need reconnecting',
            count: items.disconnectedAccounts.total,
        },
        {
            id: 'lane-review',
            label: '03 Review',
            caption: 'Studio sign-off',
            count: items.internalReviews.total,
        },
        {
            id: 'lane-approvals',
            label: '04 Approvals',
            caption: 'With the client',
            count: items.waitingApprovals.total,
        },
        {
            id: 'lane-media',
            label: '05 Assets',
            caption: 'Missing media',
            count: items.missingMedia.total,
        },
        {
            id: 'lane-campaigns',
            label: '06 Campaigns',
            caption: 'At risk',
            count: items.campaigns.total,
        },
        {
            id: 'lane-recycle',
            label: '07 Recycle',
            caption: 'Ready to repost',
            count: recycling.total,
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

                <HomeViewTabs attentionCount={total} className="animate-rise" />

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
                    {gridRisks.total > 0 && (
                        <Lane
                            id="lane-grids"
                            index="00 — Grids"
                            icon={Grid3x3}
                            title="Grid order at risk"
                            description="A grid post failed or left the schedule while later tiles are still going out"
                            count={gridRisks.total}
                            shown={gridRisks.items.length}
                            accentClassName="bg-destructive/10 text-destructive"
                            emptyLabel="Every grid is in order."
                        >
                            {gridRisks.items.map((row) => (
                                <li key={row.grid_plan_id}>
                                    <Link
                                        href={editGrid(row.grid_plan_id)}
                                        className="group hover:bg-muted/50 flex items-center justify-between gap-3 px-5 py-3.5 transition-colors"
                                    >
                                        <span className="flex min-w-0 flex-col gap-1">
                                            <span className="group-hover:text-primary truncate text-sm font-medium">
                                                {row.name}
                                            </span>
                                            <span className="text-muted-foreground truncate text-xs">
                                                {row.client_name} · {row.reason}{' '}
                                                {row.later_count} later{' '}
                                                {row.later_count === 1
                                                    ? 'post is'
                                                    : 'posts are'}{' '}
                                                still scheduled.
                                            </span>
                                        </span>
                                        <span className="text-destructive shrink-0 text-xs font-semibold">
                                            Hold the rest
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </Lane>
                    )}
                    <Lane
                        id="lane-failed"
                        index="01 — Publishing"
                        icon={AlertTriangle}
                        title="Failed publishes"
                        description="Posts the platform rejected"
                        count={items.failedPublishes.total}
                        shown={items.failedPublishes.items.length}
                        accentClassName="bg-destructive/10 text-destructive"
                        emptyLabel="No failed publishes."
                    >
                        {items.failedPublishes.items.map((row) => (
                            <PostRowItem key={row.post_id} row={row} />
                        ))}
                    </Lane>
                    <Lane
                        id="lane-accounts"
                        index="02 — Accounts"
                        icon={WifiOff}
                        title="Accounts to reconnect"
                        description="Disconnected, or connections about to expire"
                        count={items.disconnectedAccounts.total}
                        shown={items.disconnectedAccounts.items.length}
                        accentClassName="bg-warning/12 text-warning"
                        emptyLabel="All accounts are connected."
                    >
                        {items.disconnectedAccounts.items.map((account) => (
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
                                    {account.reason ? (
                                        <span className="text-warning flex shrink-0 items-center gap-1.5 text-xs font-medium">
                                            <Clock className="size-3.5" />
                                            {account.reason}
                                        </span>
                                    ) : (
                                        <StatusPill
                                            kind="connection"
                                            value={account.connection_status}
                                            label={
                                                account.connection_status_label
                                            }
                                        />
                                    )}
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
                        count={items.internalReviews.total}
                        shown={items.internalReviews.items.length}
                        accentClassName="bg-primary/10 text-primary"
                        emptyLabel="Nothing waiting on internal review."
                    >
                        {items.internalReviews.items.map((row) => (
                            <PostRowItem key={row.post_id} row={row} />
                        ))}
                    </Lane>
                    <Lane
                        id="lane-approvals"
                        index="04 — Approvals"
                        icon={Clock}
                        title="Waiting on the client"
                        description="Posts sitting in client review"
                        count={items.waitingApprovals.total}
                        shown={items.waitingApprovals.items.length}
                        accentClassName="bg-info/10 text-info"
                        emptyLabel="Nothing waiting on a client."
                    >
                        {items.waitingApprovals.items.map((row) => (
                            <PostRowItem key={row.post_id} row={row} />
                        ))}
                    </Lane>
                    <Lane
                        id="lane-media"
                        index="05 — Assets"
                        icon={ImageOff}
                        title="Missing media"
                        description="Posts that still need media attached"
                        count={items.missingMedia.total}
                        shown={items.missingMedia.items.length}
                        accentClassName="bg-muted text-foreground/60"
                        emptyLabel="Every active post has its required media."
                    >
                        {items.missingMedia.items.map((row) => (
                            <PostRowItem key={row.post_id} row={row} />
                        ))}
                    </Lane>
                    <Lane
                        id="lane-campaigns"
                        index="06 — Campaigns"
                        icon={Megaphone}
                        title="Campaigns at risk"
                        description="Launching with work unapproved, past their end date, or posting to a broken account"
                        count={items.campaigns.total}
                        shown={items.campaigns.items.length}
                        accentClassName="bg-warning/12 text-warning"
                        emptyLabel="Every campaign is on track."
                    >
                        {items.campaigns.items.map((row) => (
                            <li key={row.campaign_id}>
                                <Link
                                    href={showCampaign(row.campaign_id)}
                                    className="group hover:bg-muted/50 flex items-center justify-between gap-3 px-5 py-3.5 transition-colors"
                                >
                                    <span className="flex min-w-0 flex-col gap-1">
                                        <span className="flex min-w-0 items-center gap-2">
                                            <CampaignChip
                                                campaign={{
                                                    id: row.campaign_id,
                                                    name: row.name,
                                                    code: row.code,
                                                    colour: row.colour,
                                                }}
                                                compact
                                            />
                                            <span className="group-hover:text-primary truncate text-sm font-medium">
                                                {row.name}
                                            </span>
                                        </span>
                                        <span className="text-muted-foreground truncate text-xs">
                                            {row.client_name} · {row.reason}
                                        </span>
                                    </span>
                                    <StatusPill
                                        kind="campaign"
                                        value={row.status}
                                        label={row.status_label}
                                    />
                                </Link>
                            </li>
                        ))}
                    </Lane>
                    <Lane
                        id="lane-recycle"
                        index="07 — Recycle"
                        icon={Recycle}
                        title="Ready to recycle"
                        description="Evergreen posts past their reuse window — repost them in one tap"
                        count={recycling.total}
                        shown={recycling.items.length}
                        accentClassName="bg-success/12 text-success"
                        emptyLabel="Nothing is waiting to be recycled."
                    >
                        {recycling.items.map((row) => (
                            <li key={row.post_id}>
                                <div className="group hover:bg-muted/50 flex items-center justify-between gap-3 px-5 py-3.5 transition-colors">
                                    <Link
                                        href={editPost(row.post_id)}
                                        className="flex min-w-0 flex-1 flex-col gap-1"
                                    >
                                        <span className="group-hover:text-primary truncate text-sm font-medium">
                                            {row.master_caption ??
                                                'Untitled post'}
                                        </span>
                                        <span className="text-muted-foreground truncate text-xs">
                                            {row.client_name} · published over{' '}
                                            {row.recycle_after_days} day
                                            {row.recycle_after_days === 1
                                                ? ''
                                                : 's'}{' '}
                                            ago
                                        </span>
                                    </Link>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="shrink-0"
                                        onClick={() =>
                                            router.post(
                                                recyclePost(row.post_id).url,
                                            )
                                        }
                                    >
                                        <Recycle className="size-3.5" />
                                        Recycle
                                    </Button>
                                </div>
                            </li>
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
            title: 'Home',
            href: dashboard(),
        },
        {
            title: 'Needs attention',
            href: needsAttention(),
        },
    ],
};
