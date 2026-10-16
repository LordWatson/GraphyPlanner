import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Inbox } from 'lucide-react';

import { EmptyState, Page, PageHeader, Panel } from '@/components/page';
import type { PortalPostRowData } from '@/components/portal-post-row';
import { PortalPostRow } from '@/components/portal-post-row';
import { StatusPill } from '@/components/status-pill';
import { index as portalCampaignsIndex } from '@/routes/portal/campaigns';
import { formatDateRange } from '@/lib/dates';

type CampaignData = {
    id: number;
    name: string;
    status: string;
    status_label: string;
    start_date: string | null;
    end_date: string | null;
    goal: string | null;
};

/**
 * The client portal's campaign detail: campaign summary plus the (visible-status) posts attached
 * to it, each linking into the same `portal.posts.show` detail used by the posts list.
 */
export default function PortalCampaignShow({
    campaign,
    posts,
}: {
    campaign: CampaignData;
    posts: PortalPostRowData[];
}) {
    return (
        <>
            <Head title={`${campaign.name} — Client portal`} />
            <Page>
                <Link
                    href={portalCampaignsIndex()}
                    className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" />
                    All campaigns
                </Link>

                <PageHeader
                    eyebrow={`Campaign — ${formatDateRange(campaign.start_date, campaign.end_date)}`}
                    title={campaign.name}
                    description={campaign.goal ?? undefined}
                    meta={
                        <StatusPill
                            kind="campaign"
                            value={campaign.status}
                            label={campaign.status_label}
                        />
                    }
                />

                {posts.length === 0 ? (
                    <EmptyState
                        icon={Inbox}
                        title="No posts here yet."
                        description="Posts for this campaign appear once the studio shares them with you."
                    />
                ) : (
                    <Panel
                        eyebrow="In this campaign"
                        title={`${posts.length} post${posts.length === 1 ? '' : 's'}`}
                        bodyClassName="p-2"
                        className="animate-rise"
                    >
                        {posts.map((post) => (
                            <PortalPostRow key={post.id} post={post} />
                        ))}
                    </Panel>
                )}
            </Page>
        </>
    );
}
