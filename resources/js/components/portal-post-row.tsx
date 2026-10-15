import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { PlatformIcon } from '@/components/platform-icon';
import { StatusPill } from '@/components/status-pill';
import { show as portalPostsShow } from '@/routes/portal/posts';

export type PortalPostRowData = {
    id: number;
    status: string;
    status_label: string;
    master_caption: string | null;
    platform: string | null;
    handle: string | null;
};

/**
 * One post in any client portal list (posts, campaign detail, dashboard): platform tile, caption
 * (the client's mental handle for a post), account, status, and an optional trailing slot (e.g. a
 * scheduled date). Always links to the portal post detail page.
 */
export function PortalPostRow({
    post,
    trailing,
}: {
    post: PortalPostRowData;
    trailing?: ReactNode;
}) {
    return (
        <Link
            href={portalPostsShow(post.id)}
            className="group hover:bg-muted/60 flex items-center gap-3.5 rounded-xl px-3 py-3 transition-colors"
        >
            <PlatformIcon platform={post.platform} />
            <span className="flex min-w-0 flex-1 flex-col">
                <span className="group-hover:text-primary truncate text-sm font-medium">
                    {post.master_caption || 'Untitled post'}
                </span>
                <span className="text-muted-foreground truncate text-xs capitalize">
                    {post.platform ?? 'No platform yet'}
                    {post.handle ? ` · ${post.handle}` : ''}
                </span>
            </span>
            {trailing ?? (
                <StatusPill
                    kind="post"
                    value={post.status}
                    label={post.status_label}
                    className="hidden sm:inline-flex"
                />
            )}
            <ArrowUpRight className="text-muted-foreground group-hover:text-primary size-4 shrink-0 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
        </Link>
    );
}
