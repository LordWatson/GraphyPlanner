import { Link } from '@inertiajs/react';

import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import ClientPortalLayout from '@/layouts/client-portal/client-portal-layout';
import { show as portalCampaignsShow } from '@/routes/portal/campaigns';

type CampaignRow = {
    id: number;
    name: string;
    status: string;
    status_label: string;
    start_date: string | null;
    end_date: string | null;
    goal: string | null;
    posts_count: number;
};

const campaignStatusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline'> = {
    planning: 'secondary',
    active: 'success',
    completed: 'outline',
    archived: 'outline',
};

/**
 * The client portal's campaigns list: every campaign for this contact's client, with a count of
 * the (visible-status) posts attached to it, linking through to the campaign's own post list.
 */
export default function PortalCampaignsIndex({ campaigns }: { campaigns: CampaignRow[] }) {
    return (
        <ClientPortalLayout title="Campaigns">
            <Heading title="Campaigns" description="Marketing campaigns for your brand and the posts attached to each." />

            <div className="mt-6 flex flex-col gap-2">
                {campaigns.length > 0 ? (
                    campaigns.map((campaign) => (
                        <Link
                            key={campaign.id}
                            href={portalCampaignsShow(campaign.id)}
                            className="flex items-center justify-between gap-3 rounded-xl border border-border bg-card px-4 py-3 text-sm shadow-sm hover:bg-muted/50"
                        >
                            <div className="flex flex-col">
                                <span className="font-medium">{campaign.name}</span>
                                <span className="truncate text-xs text-muted-foreground">
                                    {campaign.start_date ?? '—'}
                                    {campaign.end_date ? ` – ${campaign.end_date}` : ''}
                                    {' · '}
                                    {campaign.posts_count} {campaign.posts_count === 1 ? 'post' : 'posts'}
                                </span>
                            </div>
                            <Badge variant={campaignStatusVariant[campaign.status] ?? 'outline'} className="whitespace-nowrap text-[10px]">
                                {campaign.status_label}
                            </Badge>
                        </Link>
                    ))
                ) : (
                    <div className="rounded-xl border border-dashed border-border bg-muted/20 p-6 text-center text-sm text-muted-foreground">
                        No campaigns to show here yet.
                    </div>
                )}
            </div>
        </ClientPortalLayout>
    );
}
