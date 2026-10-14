import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Clock, ImageOff, WifiOff } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { needsAttention } from '@/routes';
import { edit as editPost } from '@/routes/posts';
import { show as showClient } from '@/routes/clients';

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
    waitingApprovals: PostRow[];
    missingMedia: PostRow[];
    disconnectedAccounts: DisconnectedAccountRow[];
};

type HomeProps = {
    items: NeedsAttentionItems;
};

function PostRowItem({ row }: { row: PostRow }) {
    return (
        <Link
            href={editPost(row.post_id)}
            className="flex items-center justify-between gap-3 rounded-xl border border-border bg-card px-3 py-2.5 text-sm shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-agency"
        >
            <div className="flex flex-col">
                <span className="font-medium">{row.client_name ?? 'Unknown client'}</span>
                <span className="truncate text-xs text-muted-foreground">
                    {row.platform ?? 'No platform'}
                    {row.handle ? ` · ${row.handle}` : ''}
                    {row.master_caption ? ` · ${row.master_caption}` : ''}
                </span>
            </div>
            <Badge variant="outline" className="whitespace-nowrap text-[10px]">
                {row.status_label}
            </Badge>
        </Link>
    );
}

function Section({
    icon: Icon,
    title,
    description,
    rows,
    emptyLabel,
}: {
    icon: typeof AlertTriangle;
    title: string;
    description: string;
    rows: PostRow[];
    emptyLabel: string;
}) {
    return (
        <div className="relative flex flex-col gap-3 overflow-hidden rounded-2xl border border-border bg-card p-5 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-agency">
            <span aria-hidden className="absolute inset-x-0 top-0 h-1 bg-gradient-brand" />
            <div className="flex items-center gap-2">
                <span className="flex size-9 items-center justify-center rounded-xl bg-gradient-brand text-white shadow-sm">
                    <Icon className="size-4" />
                </span>
                <div className="flex flex-col">
                    <h2 className="font-serif text-sm font-semibold italic">{title}</h2>
                    <p className="text-xs text-muted-foreground">{description}</p>
                </div>
            </div>
            {rows.length > 0 ? (
                <div className="flex flex-col gap-2">
                    {rows.map((row) => (
                        <PostRowItem key={row.post_id} row={row} />
                    ))}
                </div>
            ) : (
                <p className="py-6 text-center text-xs text-muted-foreground">{emptyLabel}</p>
            )}
        </div>
    );
}

export default function Home({ items }: HomeProps) {
    return (
        <>
            <Head title="Home" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="bg-noise relative flex flex-wrap items-center gap-3 overflow-hidden rounded-2xl bg-gradient-brand-animated p-6 text-primary-foreground shadow-agency sm:p-8">
                    <span
                        aria-hidden
                        className="pointer-events-none absolute -top-16 -right-16 size-56 rounded-full bg-white/10 blur-2xl"
                    />
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 bg-[radial-gradient(white_1px,transparent_1px)] bg-[size:22px_22px] opacity-[0.08]"
                    />
                    <div className="relative flex items-center gap-3">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/15 text-primary-foreground">
                            <AlertTriangle className="size-5" />
                        </span>
                        <div className="flex flex-col gap-1">
                            <span className="inline-flex w-fit items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-[10px] font-medium tracking-widest uppercase">
                                Priority queue
                            </span>
                            <h1 className="font-serif text-2xl font-semibold tracking-tight italic sm:text-3xl">Needs attention</h1>
                            <p className="text-sm text-primary-foreground/80">
                                Everything blocking a post or account from moving forward, in one place.
                            </p>
                        </div>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <Section
                        icon={AlertTriangle}
                        title="Failed publishes"
                        description="Posts that failed to publish"
                        rows={items.failedPublishes}
                        emptyLabel="No failed publishes."
                    />
                    <Section
                        icon={Clock}
                        title="Waiting on approval"
                        description="Posts waiting on client review"
                        rows={items.waitingApprovals}
                        emptyLabel="Nothing waiting on a client."
                    />
                    <Section
                        icon={ImageOff}
                        title="Missing media"
                        description="Posts that still need media attached"
                        rows={items.missingMedia}
                        emptyLabel="Every active post has its required media."
                    />
                    <div className="relative flex flex-col gap-3 overflow-hidden rounded-2xl border border-border bg-card p-5 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-agency">
                        <span aria-hidden className="absolute inset-x-0 top-0 h-1 bg-gradient-brand" />
                        <div className="flex items-center gap-2">
                            <span className="flex size-9 items-center justify-center rounded-xl bg-gradient-brand text-white shadow-sm">
                                <WifiOff className="size-4" />
                            </span>
                            <div className="flex flex-col">
                                <h2 className="font-serif text-sm font-semibold italic">Disconnected accounts</h2>
                                <p className="text-xs text-muted-foreground">Social accounts needing reconnection</p>
                            </div>
                        </div>
                        {items.disconnectedAccounts.length > 0 ? (
                            <div className="flex flex-col gap-2">
                                {items.disconnectedAccounts.map((account) => (
                                    <Link
                                        key={account.account_id}
                                        href={showClient(account.client_id)}
                                        className="flex items-center justify-between gap-3 rounded-xl border border-border bg-card px-3 py-2.5 text-sm shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-agency"
                                    >
                                        <div className="flex flex-col">
                                            <span className="font-medium">{account.client_name ?? 'Unknown client'}</span>
                                            <span className="truncate text-xs text-muted-foreground">
                                                {account.platform_label} · {account.handle}
                                            </span>
                                        </div>
                                        <Badge variant="outline" className="whitespace-nowrap text-[10px]">
                                            {account.connection_status_label}
                                        </Badge>
                                    </Link>
                                ))}
                            </div>
                        ) : (
                            <p className="py-6 text-center text-xs text-muted-foreground">All accounts are connected.</p>
                        )}
                    </div>
                </div>
            </div>
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
