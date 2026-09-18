import { Link, router } from '@inertiajs/react';

import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import ClientPortalLayout from '@/layouts/client-portal/client-portal-layout';
import { index as portalPostsIndex, show as portalPostsShow } from '@/routes/portal/posts';

type PostRow = {
    id: number;
    status: string;
    status_label: string;
    master_caption: string | null;
    platform: string | null;
    handle: string | null;
};

type StatusOption = { value: string; label: string };

const postStatusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline' | 'destructive'> = {
    waiting_client: 'warning',
    changes_requested: 'destructive',
    approved: 'success',
    published: 'success',
    failed: 'destructive',
};

/**
 * Step 6.5 — the client portal's posts list: every post visible to this client contact
 * (pre-review internal stages are excluded server-side), filterable by status.
 */
export default function PortalPostsIndex({
    posts,
    filters,
    statuses,
}: {
    posts: PostRow[];
    filters: { status: string | null };
    statuses: StatusOption[];
}) {
    return (
        <ClientPortalLayout title="Posts & approvals">
            <Heading title="Posts & approvals" description="Everything currently in flight or scheduled for your brand." />

            <div className="mt-6 flex flex-wrap gap-2">
                <button
                    type="button"
                    onClick={() => router.get(portalPostsIndex())}
                    className={`rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                        !filters.status
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'border-border text-muted-foreground hover:bg-muted'
                    }`}
                >
                    All
                </button>
                {statuses.map((status) => (
                    <button
                        key={status.value}
                        type="button"
                        onClick={() => router.get(portalPostsIndex({ query: { status: status.value } }))}
                        className={`rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                            filters.status === status.value
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-border text-muted-foreground hover:bg-muted'
                        }`}
                    >
                        {status.label}
                    </button>
                ))}
            </div>

            <div className="mt-6 flex flex-col gap-2">
                {posts.length > 0 ? (
                    posts.map((post) => (
                        <Link
                            key={post.id}
                            href={portalPostsShow(post.id)}
                            className="flex items-center justify-between gap-3 rounded-xl border border-border bg-card px-4 py-3 text-sm shadow-sm hover:bg-muted/50"
                        >
                            <div className="flex flex-col">
                                <span className="font-medium">{post.master_caption ?? 'Untitled post'}</span>
                                <span className="truncate text-xs text-muted-foreground">
                                    {post.platform ?? 'No platform'}
                                    {post.handle ? ` · ${post.handle}` : ''}
                                </span>
                            </div>
                            <Badge variant={postStatusVariant[post.status] ?? 'outline'} className="whitespace-nowrap text-[10px]">
                                {post.status_label}
                            </Badge>
                        </Link>
                    ))
                ) : (
                    <div className="rounded-xl border border-dashed border-border bg-muted/20 p-6 text-center text-sm text-muted-foreground">
                        No posts to show here yet.
                    </div>
                )}
            </div>
        </ClientPortalLayout>
    );
}
