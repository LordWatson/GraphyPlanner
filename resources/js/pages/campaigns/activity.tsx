import { Link, router } from '@inertiajs/react';
import { Activity, ArrowUpRight } from 'lucide-react';
import { CampaignLayout } from '@/components/campaigns/campaign-layout';
import type {
    ActivityItem,
    CampaignWorkspaceProps,
} from '@/components/campaigns/types';
import { EmptyState } from '@/components/page';
import type { Paginated } from '@/components/pagination';
import { Pagination } from '@/components/pagination';
import { StatusPill } from '@/components/status-pill';
import { formatDateTime } from '@/lib/dates';
import { index as campaignsIndex } from '@/routes/campaigns';
import { edit as editPost } from '@/routes/posts';

/**
 * Step C-1.2 — the Activity tab: the campaign's own log merged with its posts' status changes,
 * newest first, as ruled rows.
 */
export default function CampaignActivity(
    props: CampaignWorkspaceProps & { activity: Paginated<ActivityItem> },
) {
    const { activity } = props;

    return (
        <CampaignLayout {...props}>
            {activity.data.length === 0 ? (
                <EmptyState
                    icon={Activity}
                    title="Nothing yet."
                    description="Every change to the campaign and its posts is recorded here."
                />
            ) : (
                <ol className="border-foreground/85 flex flex-col border-t">
                    {activity.data.map((item) => (
                        <li
                            key={item.key}
                            className="border-border grid gap-1 border-b py-4 sm:grid-cols-[180px_minmax(0,1fr)_auto] sm:items-center sm:gap-6"
                        >
                            <span className="text-muted-foreground font-mono text-[11px]">
                                {formatDateTime(item.created_at)}
                            </span>
                            <div className="flex min-w-0 flex-col gap-1">
                                <span className="text-sm">
                                    <span className="font-medium">
                                        {item.user ?? 'System'}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        ·{' '}
                                    </span>
                                    {item.summary}
                                </span>
                                {item.post && (
                                    <Link
                                        href={editPost(item.post.id)}
                                        className="text-muted-foreground hover:text-primary inline-flex min-w-0 items-center gap-1 text-xs"
                                    >
                                        <span className="truncate">
                                            {item.post.caption ??
                                                `Post ${item.post.id}`}
                                        </span>
                                        <ArrowUpRight className="size-3 shrink-0" />
                                    </Link>
                                )}
                                {item.note && (
                                    <p className="border-lilac bg-lilac/15 rounded-md border-l-2 px-2.5 py-1.5 text-xs">
                                        “{item.note}”
                                    </p>
                                )}
                            </div>
                            {item.source === 'post' && item.to ? (
                                <StatusPill kind="post" value={item.to} />
                            ) : item.event === 'status.changed' && item.to ? (
                                <StatusPill kind="campaign" value={item.to} />
                            ) : (
                                <span />
                            )}
                        </li>
                    ))}
                </ol>
            )}
            <Pagination
                page={activity}
                label="events"
                onPageChange={(page) =>
                    router.reload({ data: { page }, only: ['activity'] })
                }
            />
        </CampaignLayout>
    );
}

CampaignActivity.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};
