import { Link } from '@inertiajs/react';
import { ArrowRight, Megaphone } from 'lucide-react';
import { CampaignChip } from '@/components/campaigns/campaign-chip';
import { HealthPill, ReadinessMeter } from '@/components/campaigns/health-pill';
import type {
    CampaignChipData,
    CampaignHealth,
    CampaignReadiness,
    CampaignTimeline,
} from '@/components/campaigns/types';
import { EmptyState, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    index as campaignsIndex,
    show as showCampaign,
} from '@/routes/campaigns';

export type LiveCampaign = CampaignChipData & {
    status: string;
    status_label: string;
    client_name: string | null;
    timeline: CampaignTimeline;
    health: CampaignHealth;
    readiness: CampaignReadiness;
};

/**
 * Step C-1.4 — the Overview's "Live campaigns": what's running today, ending soonest first, each
 * with its days-left line, readiness and health (with reasons).
 */
export function LiveCampaignsPanel({
    items,
    total,
}: {
    items: LiveCampaign[];
    total: number;
}) {
    return (
        <Panel
            eyebrow="Running now"
            title={`Live campaigns${total > 0 ? ` · ${total}` : ''}`}
            icon={Megaphone}
            bodyClassName="p-2"
            actions={
                <Button variant="ghost" size="sm" asChild>
                    <Link href={campaignsIndex()}>
                        All campaigns
                        <ArrowRight />
                    </Link>
                </Button>
            }
        >
            {items.length === 0 ? (
                <EmptyState
                    compact
                    icon={Megaphone}
                    title="Nothing live today."
                    description="Campaigns running today show here with how ready they are."
                    className="border-0"
                />
            ) : (
                <ul className="divide-border/70 flex flex-col divide-y">
                    {items.map((campaign) => (
                        <li key={campaign.id}>
                            <Link
                                href={showCampaign(campaign.id)}
                                className="group hover:bg-muted/50 grid gap-2 rounded-xl px-3 py-3 transition-colors sm:grid-cols-[minmax(0,1fr)_10rem_auto] sm:items-center sm:gap-4"
                            >
                                <span className="flex min-w-0 flex-col gap-1">
                                    <span className="flex min-w-0 items-center gap-2">
                                        <CampaignChip
                                            campaign={campaign}
                                            compact
                                        />
                                        <span className="group-hover:text-primary truncate text-sm font-medium">
                                            {campaign.name}
                                        </span>
                                    </span>
                                    <span className="text-muted-foreground truncate text-xs">
                                        {campaign.client_name} ·{' '}
                                        {campaign.timeline.sentence}
                                    </span>
                                </span>
                                <ReadinessMeter
                                    readiness={campaign.readiness}
                                />
                                <HealthPill health={campaign.health} />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}
