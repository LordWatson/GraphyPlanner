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
import { EmptyState, Page, PageHeader } from '@/components/page';
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
    index,
    icon: Icon,
    title,
    description,
    count,
    accentClassName,
    emptyLabel,
    children,
}: {
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
        <section className="border-border bg-card flex flex-col overflow-hidden rounded-2xl border">
            <header className="border-border/70 flex items-start justify-between gap-4 border-b px-5 pt-4 pb-4">
                <div className="flex items-start gap-3">
                    <span
                        className={cn(
                            'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg',
                            accentClassName,
                        )}
                    >
                        <Icon className="size-4" />
                    </span>
                    <div className="flex flex-col gap-0.5">
                        <span className="eyebrow text-muted-foreground">
                            {index}
                        </span>
                        <h2 className="text-[15px] font-semibold">{title}</h2>
                        <p className="text-muted-foreground text-[13px]">
                            {description}
                        </p>
                    </div>
                </div>
                <span
                    className={cn(
                        'font-serif text-5xl leading-none tabular-nums',
                        count === 0 && 'text-muted-foreground/40',
                    )}
                >
                    {count}
                </span>
            </header>
            {count > 0 ? (
                <ul className="flex flex-col p-2">{children}</ul>
            ) : (
                <EmptyState
                    compact
                    icon={CheckCircle2}
                    title="Clear."
                    description={emptyLabel}
                    className="m-3 border-0"
                />
            )}
        </section>
    );
}

function PostRowItem({ row }: { row: PostRow }) {
    return (
        <li>
            <Link
                href={editPost(row.post_id)}
                className="group hover:bg-muted/70 flex items-center justify-between gap-3 rounded-xl px-3 py-2.5 transition-colors"
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

    return (
        <>
            <Head title="Needs attention" />
            <Page>
                <PageHeader
                    eyebrow="Triage"
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
                    description="Everything stopping a post or account from moving forward, sorted by what to fix first."
                />

                <div className="animate-rise grid grid-cols-1 gap-5 md:grid-cols-2">
                    <Lane
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
                                    className="group hover:bg-muted/70 flex items-center justify-between gap-3 rounded-xl px-3 py-2.5 transition-colors"
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
