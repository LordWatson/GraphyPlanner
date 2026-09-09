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
            className="flex items-center justify-between gap-3 rounded-md border border-border bg-card px-3 py-2 text-sm hover:bg-muted/50"
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
        <div className="relative flex flex-col gap-3 overflow-hidden rounded-xl border border-border bg-card p-4 shadow-sm">
            <span aria-hidden className="absolute inset-x-0 top-0 h-1 bg-gradient-brand" />
            <div className="flex items-center gap-2">
                <span className="flex size-8 items-center justify-center rounded-md bg-gradient-brand text-white shadow-sm">
                    <Icon className="size-4" />
                </span>
                <div className="flex flex-col">
                    <h2 className="text-sm font-semibold">{title}</h2>
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
                <div className="relative overflow-hidden rounded-xl border border-border p-6 shadow-lg shadow-primary/10">
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 -z-10 bg-gradient-brand opacity-[0.14]"
                    />
                    <div className="flex flex-col gap-1">
                        <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                            <span className="text-gradient-brand">Needs attention</span>
                        </h1>
                        <p className="max-w-xl text-sm text-muted-foreground">
                            Everything blocking a post or account from moving forward, in one place.
                        </p>
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
                    <div className="relative flex flex-col gap-3 overflow-hidden rounded-xl border border-border bg-card p-4 shadow-sm">
                        <span aria-hidden className="absolute inset-x-0 top-0 h-1 bg-gradient-brand" />
                        <div className="flex items-center gap-2">
                            <span className="flex size-8 items-center justify-center rounded-md bg-gradient-brand text-white shadow-sm">
                                <WifiOff className="size-4" />
                            </span>
                            <div className="flex flex-col">
                                <h2 className="text-sm font-semibold">Disconnected accounts</h2>
                                <p className="text-xs text-muted-foreground">Social accounts needing reconnection</p>
                            </div>
                        </div>
                        {items.disconnectedAccounts.length > 0 ? (
                            <div className="flex flex-col gap-2">
                                {items.disconnectedAccounts.map((account) => (
                                    <Link
                                        key={account.account_id}
                                        href={showClient(account.client_id)}
                                        className="flex items-center justify-between gap-3 rounded-md border border-border bg-card px-3 py-2 text-sm hover:bg-muted/50"
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
