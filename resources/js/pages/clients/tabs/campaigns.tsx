import { Link, router } from '@inertiajs/react';
import { Clapperboard, LayoutTemplate, UserRound } from 'lucide-react';
import { useState } from 'react';
import { CampaignChip } from '@/components/campaigns/campaign-chip';
import type { CampaignFormOptions } from '@/components/campaigns/campaign-form-fields';
import { HealthPill } from '@/components/campaigns/health-pill';
import { NewCampaignDialog } from '@/components/campaigns/new-campaign-dialog';
import type { Option, StaffCampaign } from '@/components/campaigns/types';
import type { TemplateSummary } from '@/components/campaigns/use-template-dialog';
import { UseTemplateDialog } from '@/components/campaigns/use-template-dialog';
import type { ClientPageProps } from '@/components/clients/client-layout';
import {
    ClientLayout,
    TabHeading,
    TabPanel,
} from '@/components/clients/client-layout';
import { EmptyState } from '@/components/page';
import type { Paginated } from '@/components/pagination';
import { Pagination } from '@/components/pagination';
import { StatusPill } from '@/components/status-pill';
import { formatDateRange } from '@/lib/dates';
import { show as showCampaign } from '@/routes/campaigns';
import { index } from '@/routes/clients';

type CampaignRow = StaffCampaign & {
    can: { update: boolean; delete: boolean };
};

/**
 * Step I-2.4 — Campaigns tab (`clients.campaigns.index`), paginated. Campaigns enhancement: rows
 * share the hub's presenter (chip, timeline line, health with reasons); "New campaign" opens the
 * shared dialog and lands in the new workspace.
 */
export default function ClientCampaignsTab({
    campaigns: campaignPage,
    formOptions,
    starterTemplates,
    platformOptions,
    ...page
}: ClientPageProps & {
    campaigns: Paginated<CampaignRow>;
    formOptions: CampaignFormOptions;
    starterTemplates: TemplateSummary[];
    platformOptions: Option[];
}) {
    const { client, can } = page;
    const campaigns = campaignPage.data;
    const [template, setTemplate] = useState<TemplateSummary | null>(null);

    return (
        <ClientLayout {...page}>
            <TabPanel>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <TabHeading
                        icon={Clapperboard}
                        title="Campaigns"
                        description="Launches, seasons and pushes for this client"
                    />
                    {can.createCampaign && (
                        <NewCampaignDialog
                            options={formOptions}
                            clientId={client.id}
                        />
                    )}
                </div>

                {campaigns.length === 0 && starterTemplates.length > 0 ? (
                    <FirstCampaignPrompt
                        templates={starterTemplates}
                        onPick={setTemplate}
                    />
                ) : campaigns.length === 0 ? (
                    <EmptyState
                        compact
                        icon={Clapperboard}
                        title="No campaigns yet."
                        description="Start one and you’ll have a brief, a plan and a board in one place."
                    />
                ) : (
                    <ol className="divide-border/70 flex flex-col divide-y">
                        {campaigns.map((campaign) => (
                            <li key={campaign.id}>
                                <Link
                                    href={showCampaign(campaign.id)}
                                    className="group hover:bg-muted/40 flex flex-col gap-2 rounded-lg px-2 py-3.5 sm:flex-row sm:items-center sm:gap-4"
                                >
                                    <span className="flex min-w-0 flex-1 flex-col gap-1">
                                        <span className="flex min-w-0 items-center gap-2">
                                            <CampaignChip
                                                campaign={campaign}
                                                compact
                                            />
                                            <span className="group-hover:text-primary truncate text-sm font-semibold">
                                                {campaign.name}
                                            </span>
                                        </span>
                                        <span className="text-muted-foreground flex flex-wrap items-center gap-x-3 text-xs">
                                            <span className="font-mono">
                                                {formatDateRange(
                                                    campaign.start_date,
                                                    campaign.end_date,
                                                )}
                                            </span>
                                            <span>
                                                {campaign.timeline.sentence}
                                            </span>
                                            {campaign.owner && (
                                                <span className="inline-flex items-center gap-1">
                                                    <UserRound className="size-3" />
                                                    {campaign.owner.name}
                                                </span>
                                            )}
                                        </span>
                                    </span>
                                    <span className="flex shrink-0 items-center gap-2">
                                        <StatusPill
                                            kind="campaign"
                                            value={campaign.status}
                                            label={campaign.status_label}
                                        />
                                        {campaign.health && (
                                            <HealthPill
                                                health={campaign.health}
                                            />
                                        )}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ol>
                )}
            </TabPanel>
            {template && (
                <UseTemplateDialog
                    key={template.id}
                    template={template}
                    clients={[]}
                    clientId={client.id}
                    platformOptions={platformOptions}
                    open
                    onOpenChange={(open) => !open && setTemplate(null)}
                />
            )}
            <Pagination
                page={campaignPage}
                label="campaigns"
                onPageChange={(next) =>
                    router.reload({ data: { page: next }, only: ['campaigns'] })
                }
            />
        </ClientLayout>
    );
}

/**
 * Campaigns enhancement C-9.3 — a client's first campaign: rather than a blank page, offer the
 * studio's templates (starters first). Shown only while the tab is empty, so it appears once.
 */
function FirstCampaignPrompt({
    templates,
    onPick,
}: {
    templates: TemplateSummary[];
    onPick: (template: TemplateSummary) => void;
}) {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <span className="eyebrow text-muted-foreground">
                    First campaign
                </span>
                <p className="font-display text-xl leading-tight">
                    Start from a template.
                </p>
                <p className="text-muted-foreground text-sm">
                    Phases, planned posts and a draft brief, laid out from your
                    start date. Or start blank with “New campaign”.
                </p>
            </div>
            <ul className="grid gap-3 sm:grid-cols-3">
                {templates.map((template) => (
                    <li key={template.id}>
                        <button
                            type="button"
                            onClick={() => onPick(template)}
                            className="border-border/70 hover:border-foreground/40 hover:bg-muted/40 focus-visible:ring-ring flex h-full w-full flex-col gap-2 rounded-xl border p-4 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <LayoutTemplate className="text-muted-foreground size-4" />
                            <span className="text-sm font-semibold">
                                {template.name}
                            </span>
                            {template.description && (
                                <span className="text-muted-foreground line-clamp-2 text-xs">
                                    {template.description}
                                </span>
                            )}
                            <span className="text-muted-foreground mt-auto font-mono text-[11px]">
                                {template.duration_days} days
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

ClientCampaignsTab.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
