import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, Clapperboard, FileSignature } from 'lucide-react';
import { campaignSwatch } from '@/components/campaigns/campaign-chip';
import type { CampaignTimeline } from '@/components/campaigns/types';
import { EmptyState, Page, PageHeader } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Badge } from '@/components/ui/badge';
import { formatDateRange } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { show as portalCampaignsShow } from '@/routes/portal/campaigns';

type CampaignRow = {
    id: number;
    name: string;
    code: string | null;
    colour: string | null;
    status: string;
    status_label: string;
    start_date: string | null;
    end_date: string | null;
    goal: string | null;
    timeline: CampaignTimeline;
    posts_count: number;
    awaiting_count: number;
    brief_waiting: boolean;
};

/**
 * The client portal's campaigns (C-5.1): each campaign as an editorial card — its colour, where it
 * sits in time, and anything waiting on the client (a brief to sign, posts to review) up front.
 */
export default function PortalCampaignsIndex({
    campaigns,
}: {
    campaigns: CampaignRow[];
}) {
    return (
        <>
            <Head title="Campaigns — Client portal" />
            <Page>
                <PageHeader
                    eyebrow={`Campaigns — ${campaigns.length} in total`}
                    title={
                        <>
                            Your <em>campaigns</em>
                        </>
                    }
                    description="What we’re running for your brand, when, and anything we need from you."
                />

                {campaigns.length === 0 ? (
                    <EmptyState
                        icon={Clapperboard}
                        title="No campaigns yet."
                        description="Campaigns appear here once the studio has planned one with you."
                    />
                ) : (
                    <div className="animate-rise grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {campaigns.map((campaign) => (
                            <Link
                                key={campaign.id}
                                href={portalCampaignsShow(campaign.id)}
                                className="group border-border bg-card hover:border-foreground/20 relative flex min-h-56 flex-col justify-between gap-6 overflow-hidden rounded-2xl border p-5 pt-6 transition-colors"
                            >
                                <span
                                    aria-hidden
                                    className={cn(
                                        'absolute inset-x-0 top-0 h-1.5',
                                        campaignSwatch(campaign.colour),
                                    )}
                                />
                                <div className="flex flex-col gap-3">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-muted-foreground font-mono text-xs">
                                            {campaign.code ?? ''}
                                        </span>
                                        <StatusPill
                                            kind="campaign"
                                            value={campaign.status}
                                            label={campaign.status_label}
                                        />
                                    </div>
                                    <h2 className="group-hover:text-primary font-display text-2xl leading-[1.05]">
                                        {campaign.name}
                                    </h2>
                                    <p className="text-sm">
                                        {campaign.timeline.sentence}
                                    </p>
                                    {(campaign.brief_waiting ||
                                        campaign.awaiting_count > 0) && (
                                        <div className="flex flex-wrap gap-1.5">
                                            {campaign.brief_waiting && (
                                                <Badge variant="info">
                                                    <FileSignature />
                                                    Brief to sign off
                                                </Badge>
                                            )}
                                            {campaign.awaiting_count > 0 && (
                                                <Badge variant="info">
                                                    {campaign.awaiting_count} to
                                                    review
                                                </Badge>
                                            )}
                                        </div>
                                    )}
                                </div>
                                <div className="border-border/70 flex items-end justify-between gap-3 border-t pt-4">
                                    <span className="flex flex-col gap-0.5">
                                        <span className="eyebrow text-muted-foreground">
                                            Runs
                                        </span>
                                        <span className="font-mono text-xs tabular-nums">
                                            {formatDateRange(
                                                campaign.start_date,
                                                campaign.end_date,
                                            )}
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-2">
                                        <span className="text-right">
                                            <span className="font-display block text-xl leading-none font-bold tabular-nums">
                                                {campaign.posts_count}
                                            </span>
                                            <span className="eyebrow text-muted-foreground">
                                                {campaign.posts_count === 1
                                                    ? 'post'
                                                    : 'posts'}
                                            </span>
                                        </span>
                                        <ArrowUpRight className="text-muted-foreground group-hover:text-primary size-4 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
                                    </span>
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </Page>
        </>
    );
}
