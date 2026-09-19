import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import ClientPortalLayout from '@/layouts/client-portal/client-portal-layout';
import { index as portalCampaignsIndex } from '@/routes/portal/campaigns';
import { show as portalPostsShow } from '@/routes/portal/posts';

type CampaignData = {
    id: number;
    name: string;
    status: string;
    status_label: string;
    start_date: string | null;
    end_date: string | null;
    goal: string | null;
};

type PostRow = {
    id: number;
    status: string;
    status_label: string;
    master_caption: string | null;
    platform: string | null;
    handle: string | null;
};

const campaignStatusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline'> = {
    planning: 'secondary',
    active: 'success',
    completed: 'outline',
    archived: 'outline',
};

const postStatusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline' | 'destructive'> = {
    waiting_client: 'warning',
    changes_requested: 'destructive',
    approved: 'success',
    published: 'success',
    failed: 'destructive',
};

/**
 * The client portal's campaign detail: campaign summary plus the (visible-status) posts attached
 * to it, each linking into the same `portal.posts.show` detail used by the posts list.
 */
export default function PortalCampaignShow({ campaign, posts }: { campaign: CampaignData; posts: PostRow[] }) {
    return (
        <ClientPortalLayout title={campaign.name}>
            <Link
                href={portalCampaignsIndex()}
                className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft className="size-4" />
                Back to campaigns
            </Link>

            <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                <Heading title={campaign.name} description={campaign.goal ?? 'No goal noted for this campaign.'} />
                <Badge variant={campaignStatusVariant[campaign.status] ?? 'outline'}>{campaign.status_label}</Badge>
            </div>

            <p className="text-sm text-muted-foreground">
                {campaign.start_date ?? '—'}
                {campaign.end_date ? ` – ${campaign.end_date}` : ''}
            </p>

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
                        No posts attached to this campaign yet.
                    </div>
                )}
            </div>
        </ClientPortalLayout>
    );
}
