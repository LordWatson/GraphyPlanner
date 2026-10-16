import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, Clapperboard } from 'lucide-react';

import { EmptyState, Page, PageHeader } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { show as portalCampaignsShow } from '@/routes/portal/campaigns';
import { formatDateRange } from '@/lib/dates';

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

/**
 * The client portal's campaigns list: every campaign for this contact's client as an editorial
 * card (name, goal, dates, visible-post count), linking through to the campaign's own post list.
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
                    description="The campaigns we're running for your brand, and the posts that make each one up."
                />

                {campaigns.length === 0 ? (
                    <EmptyState
                        icon={Clapperboard}
                        title="No campaigns yet."
                        description="Campaigns appear here once the studio has planned one with you."
                    />
                ) : (
                    <div className="animate-rise grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {campaigns.map((campaign, i) => (
                            <Link
                                key={campaign.id}
                                href={portalCampaignsShow(campaign.id)}
                                className="group border-border bg-card hover:border-foreground/20 flex min-h-56 flex-col justify-between gap-6 rounded-2xl border p-5 transition-colors"
                            >
                                <div className="flex flex-col gap-3">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-muted-foreground font-mono text-xs tabular-nums">
                                            {String(i + 1).padStart(2, '0')}
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
                                    {campaign.goal && (
                                        <p className="text-muted-foreground line-clamp-3 text-[13px] leading-relaxed">
                                            {campaign.goal}
                                        </p>
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
