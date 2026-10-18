import { CampaignChip } from '@/components/campaigns/campaign-chip';
import type { CampaignChipData } from '@/components/campaigns/types';
import { Link, router } from '@inertiajs/react';
import { Pencil, Plus, Send, Share2 } from 'lucide-react';
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
    platformIcon,
    platformOptions,
} from '@/components/clients/client-layout';
import type { TabFilterOption } from '@/components/clients/tab-filters';
import {
    TabSearchFilter,
    TabSelectFilter,
    visitClientTab,
} from '@/components/clients/tab-filters';
import type { Paginated } from '@/components/pagination';
import { Pagination } from '@/components/pagination';

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

                        {posts.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {filtered
                                    ? 'No posts match the current filters.'
                                    : 'No posts yet.'}
                            </p>
                        ) : (
                            <div className="grid gap-3">
                                {posts.map((post) => (
                                    <div
                                        key={post.id}
                                        role="button"
                                        tabIndex={0}
                                        onClick={() =>
                                            router.visit(editPost(post.id).url)
                                        }
                                        onKeyDown={(e) => {
                                            if (
                                                e.key === 'Enter' ||
                                                e.key === ' '
                                            ) {
                                                e.preventDefault();
                                                router.visit(
                                                    editPost(post.id).url,
                                                );
                                            }
                                        }}
                                        className="border-border bg-card hover:border-primary/30 hover:bg-muted/40 flex cursor-pointer flex-col gap-2 rounded-xl border p-3 shadow-sm transition-colors"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="flex items-center gap-2">
                                                <StatusPill
                                                    kind="post"
                                                    value={post.status}
                                                />
                                                {post.campaign && (
                                                    <CampaignChip
                                                        campaign={post.campaign}
                                                    />
                                                )}
                                            </div>
                                            <span className="text-muted-foreground inline-flex items-center gap-1.5 text-xs">
                                                <Pencil className="size-3.5" />
                                                Open editor
                                            </span>
                                        </div>
                                        {post.master_caption && (
                                            <p className="text-sm">
                                                {post.master_caption}
                                            </p>
                                        )}
                                        {post.targets.length > 0 && (
                                            <div className="flex flex-wrap gap-2">
                                                {post.targets.map((target) => {
                                                    const AccountIcon =
                                                        platformIcon[
                                                            target.platform ??
                                                                ''
                                                        ] ?? Share2;
                                                    return (
                                                        <span
                                                            key={target.id}
                                                            className="border-border bg-background text-foreground inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
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
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}

                        <Pagination
                            className="pt-2"
                            page={postPage}
                            label="posts"
                            onPageChange={(page) => visit({ page })}
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
