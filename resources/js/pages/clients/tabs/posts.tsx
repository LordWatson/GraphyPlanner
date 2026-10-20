import { CampaignChip } from '@/components/campaigns/campaign-chip';
import type { CampaignChipData } from '@/components/campaigns/types';
import { Link } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Plus, Send } from 'lucide-react';
import type { PostTargetAccount } from '@/components/posts/new-post-dialog';
import { NewPostDialog } from '@/components/posts/new-post-dialog';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { formatSchedule } from '@/lib/dates';
import { calendar } from '@/routes';
import { index } from '@/routes/clients';
import { index as postsIndex } from '@/routes/clients/posts';
import { edit as editPost } from '@/routes/posts';
import type { ClientPageProps } from '@/components/clients/client-layout';
import {
    ClientLayout,
    TabHeading,
    TabPanel,
    usePlatformOptions,
} from '@/components/clients/client-layout';
import { platformIcon } from '@/components/platform-icon';
import type { TabFilterOption } from '@/components/clients/tab-filters';
import {
    TabSearchFilter,
    TabSelectFilter,
    visitClientTab,
} from '@/components/clients/tab-filters';
import type { Paginated } from '@/components/pagination';
import { DataTable } from '@/components/data-table';

type PostTargetData = {
    id: number;
    social_account_id: number;
    platform: string | null;
    handle: string | null;
    scheduled_local_date: string | null;
    scheduled_local_time: string | null;
    scheduled_at_utc: string | null;
};

type PostData = {
    id: number;
    campaign_id: number | null;
    campaign_name: string | null;
    campaign: CampaignChipData | null;
    status: string;
    approval_mode: string;
    master_caption: string | null;
    hashtags: string[] | null;
    targets: PostTargetData[];
    created_at: string | null;
    can: { update: boolean; delete: boolean };
};

type PostFilters = {
    search: string | null;
    status: string | null;
    campaign: number | null;
    platform: string | null;
};

/** Step M-1.4: the client's posts on the shared `DataTable`; each row opens the editor. */
const postColumns: ColumnDef<PostData, unknown>[] = [
    {
        id: 'status',
        header: 'Status',
        enableHiding: false,
        meta: { className: 'w-0' },
        cell: ({ row }) => (
            <StatusPill kind="post" value={row.original.status} />
        ),
    },
    {
        id: 'caption',
        header: 'Caption',
        enableHiding: false,
        cell: ({ row }) => (
            <Link
                href={editPost(row.original.id)}
                className="hover:text-primary line-clamp-2 max-w-xl text-sm whitespace-normal transition-colors"
            >
                {row.original.master_caption || (
                    <span className="text-muted-foreground">
                        No caption yet
                    </span>
                )}
            </Link>
        ),
    },
    {
        id: 'campaign',
        header: 'Campaign',
        meta: { label: 'Campaign', hideBelow: 'lg' },
        cell: ({ row }) =>
            row.original.campaign ? (
                <CampaignChip campaign={row.original.campaign} />
            ) : (
                <span className="text-muted-foreground">—</span>
            ),
    },
    {
        id: 'channels',
        header: 'Channels',
        meta: { label: 'Channels', hideBelow: 'md' },
        cell: ({ row }) => (
            <div className="flex flex-wrap gap-1.5">
                {row.original.targets.map((target) => {
                    const AccountIcon = platformIcon(target.platform);

                    return (
                        <span
                            key={target.id}
                            className="border-border bg-background text-foreground inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs whitespace-nowrap"
                        >
                            <AccountIcon className="text-primary size-3.5" />
                            {target.handle} ·{' '}
                            {formatSchedule(
                                target.scheduled_local_date,
                                target.scheduled_local_time,
                                '—',
                            )}
                        </span>
                    );
                })}
            </div>
        ),
    },
];

/**
 * Step I-2.4 — Posts tab (`clients.posts.index`), paginated newest first and filtered server-side
 * by caption search, status, campaign and channel.
 */
export default function ClientPostsTab({
    posts: postPage,
    filters,
    filterOptions,
    targetAccounts,
    ...page
}: ClientPageProps & {
    posts: Paginated<PostData>;
    filters: PostFilters;
    filterOptions: {
        statuses: TabFilterOption[];
        campaigns: TabFilterOption[];
    };
    targetAccounts: PostTargetAccount[];
}) {
    const platformOptions = usePlatformOptions();
    const { client, can } = page;
    const posts = postPage.data;
    const filtered =
        filters.search !== null ||
        filters.status !== null ||
        filters.campaign !== null ||
        filters.platform !== null;
    const visit = (changes: Partial<PostFilters> & { page?: number }) =>
        visitClientTab(postsIndex(client.id).url, filters, changes, [
            'posts',
            'filters',
        ]);
    return (
        <ClientLayout {...page}>
            <TabPanel>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <TabHeading
                        icon={Send}
                        title="Posts"
                        description="Scheduled content across social accounts"
                    />
                    <div className="flex items-center gap-2">
                        <Button size="sm" variant="ghost" asChild>
                            <Link
                                href={calendar({
                                    query: { client_id: client.id },
                                })}
                            >
                                View calendar
                            </Link>
                        </Button>
                        {can.createPost && (
                            <NewPostDialog
                                clientId={client.id}
                                targetAccounts={targetAccounts}
                                campaigns={filterOptions.campaigns}
                                trigger={
                                    <Button size="sm">
                                        <Plus />
                                        New post
                                    </Button>
                                }
                            />
                        )}
                    </div>
                </div>

                <>
                    <>
                        <div className="flex flex-wrap gap-3">
                            <TabSearchFilter
                                id="posts-search"
                                label="Search captions"
                                placeholder="Search captions"
                                value={filters.search}
                                onSearch={(search) => visit({ search })}
                            />
                            <TabSelectFilter
                                id="posts-status-filter"
                                label="Filter by status"
                                allLabel="All statuses"
                                value={filters.status}
                                options={filterOptions.statuses}
                                onChange={(status) => visit({ status })}
                            />
                            <TabSelectFilter
                                id="posts-campaign-filter"
                                label="Filter by campaign"
                                allLabel="All campaigns"
                                value={
                                    filters.campaign === null
                                        ? null
                                        : String(filters.campaign)
                                }
                                options={filterOptions.campaigns}
                                onChange={(campaign) =>
                                    visit({
                                        campaign:
                                            campaign === null
                                                ? null
                                                : Number(campaign),
                                    })
                                }
                            />
                            <TabSelectFilter
                                id="posts-platform-filter"
                                label="Filter by channel"
                                allLabel="All channels"
                                value={filters.platform}
                                options={platformOptions}
                                onChange={(platform) => visit({ platform })}
                            />
                        </div>

                        <DataTable
                            caption="Posts"
                            columns={postColumns}
                            data={posts}
                            getRowId={(post) => String(post.id)}
                            rowHref={(post) => editPost.url(post.id)}
                            storageKey="client-posts"
                            pagination={postPage}
                            paginationLabel="posts"
                            onPageChange={(page) => visit({ page })}
                            empty={
                                <p className="text-muted-foreground text-sm">
                                    {filtered
                                        ? 'No posts match the current filters.'
                                        : 'No posts yet.'}
                                </p>
                            }
                        />
                    </>
                </>
            </TabPanel>
        </ClientLayout>
    );
}

ClientPostsTab.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
