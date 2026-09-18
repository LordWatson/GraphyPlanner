import { Link } from '@inertiajs/react';
import { CalendarClock, Clock, MessageSquareWarning } from 'lucide-react';

import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import ClientPortalLayout from '@/layouts/client-portal/client-portal-layout';
import { show as showPortalPost } from '@/routes/portal/posts';

type PostRow = {
    post_id: number;
    status: string;
    status_label: string;
    reason: string;
    master_caption: string | null;
    platform: string | null;
    handle: string | null;
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

function PostRowItem({ row }: { row: PostRow }) {
    return (
        <Link
            href={showPortalPost(row.post_id)}
            className="flex items-center justify-between gap-3 rounded-md border border-border bg-card px-3 py-2 text-sm hover:bg-muted/50"
        >
            <div className="flex flex-col">
                <span className="font-medium">{row.master_caption ?? 'Untitled post'}</span>
                <span className="truncate text-xs text-muted-foreground">
                    {row.platform ?? 'No platform'}
                    {row.handle ? ` · ${row.handle}` : ''}
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
    icon: typeof Clock;
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

export default function PortalDashboard({ client, items }: { client: { name: string }; items: DashboardItems }) {
    return (
        <ClientPortalLayout title="Dashboard">
            <Heading title={`Welcome, ${client.name}`} description="Here's what's happening with your content." />

            <div className="mt-6 grid gap-4 md:grid-cols-2">
                <Section
                    icon={Clock}
                    title="Waiting on your approval"
                    description="Posts ready for your review"
                    rows={items.waitingApprovals}
                    emptyLabel="Nothing waiting on your approval."
                />
                <Section
                    icon={MessageSquareWarning}
                    title="Changes requested"
                    description="Posts you've asked to be revised"
                    rows={items.changesRequested}
                    emptyLabel="No changes currently requested."
                />

                <div className="relative flex flex-col gap-3 overflow-hidden rounded-xl border border-border bg-card p-4 shadow-sm md:col-span-2">
                    <span aria-hidden className="absolute inset-x-0 top-0 h-1 bg-gradient-brand" />
                    <div className="flex items-center gap-2">
                        <span className="flex size-8 items-center justify-center rounded-md bg-gradient-brand text-white shadow-sm">
                            <CalendarClock className="size-4" />
                        </span>
                        <div className="flex flex-col">
                            <h2 className="text-sm font-semibold">Upcoming scheduled posts</h2>
                            <p className="text-xs text-muted-foreground">What's going out next for your brand</p>
                        </div>
                    </div>
                    {items.upcomingPosts.length > 0 ? (
                        <div className="flex flex-col gap-2">
                            {items.upcomingPosts.map((row) => (
                                <Link
                                    key={row.target_id}
                                    href={showPortalPost(row.post_id)}
                                    className="flex items-center justify-between gap-3 rounded-md border border-border bg-card px-3 py-2 text-sm hover:bg-muted/50"
                                >
                                    <div className="flex flex-col">
                                        <span className="font-medium">{row.master_caption ?? 'Untitled post'}</span>
                                        <span className="truncate text-xs text-muted-foreground">
                                            {row.platform ?? 'No platform'}
                                            {row.handle ? ` · ${row.handle}` : ''}
                                        </span>
                                    </div>
                                    <Badge variant="outline" className="whitespace-nowrap text-[10px]">
                                        {row.date} · {row.time}
                                    </Badge>
                                </Link>
                            ))}
                        </div>
                    ) : (
                        <p className="py-6 text-center text-xs text-muted-foreground">No scheduled posts in the next two weeks.</p>
                    )}
                </div>
            </div>
        </ClientPortalLayout>
    );
}
