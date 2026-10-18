import { Deferred, Link } from '@inertiajs/react';
import {
    CalendarRange,
    Columns3,
    LayoutTemplate,
    List,
    Megaphone,
    UserRound,
} from 'lucide-react';
import { CampaignChip } from '@/components/campaigns/campaign-chip';
import type { CampaignFormOptions } from '@/components/campaigns/campaign-form-fields';
import { CampaignGantt } from '@/components/campaigns/campaign-gantt';
import type { GanttCampaign } from '@/components/campaigns/campaign-gantt';
import { HealthPill, ReadinessMeter } from '@/components/campaigns/health-pill';
import { NewCampaignDialog } from '@/components/campaigns/new-campaign-dialog';
import type { Option, StaffCampaign } from '@/components/campaigns/types';
import type { TabFilterValue } from '@/components/clients/tab-filters';
import {
    TabSearchFilter,
    TabSelectFilter,
    visitClientTab,
} from '@/components/clients/tab-filters';
import { EmptyState, Page, PageHeader, StatStrip } from '@/components/page';
import type { Paginated } from '@/components/pagination';
import { Pagination } from '@/components/pagination';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatDateRange } from '@/lib/dates';
import { index as templatesIndex } from '@/routes/campaign-templates';
import { compare as compareRoute } from '@/routes/campaigns';
import {
    index as campaignsIndex,
    show as showCampaign,
} from '@/routes/campaigns';

type HubCampaign = StaffCampaign & {
    posts_total: number;
    posts_published: number;
    deliverables_total: number;
};

type Filters = {
    view: 'list' | 'timeline';
    search: string | null;
    client_id: number | null;
    status: string | null;
    timeline: string | null;
    owner_id: number | null;
    objective: string | null;
    health: string | null;
    sort: string;
};

type HubProps = {
    view: 'list' | 'timeline';
    filters: Filters;
    stats?: {
        live: number;
        launching: number;
        at_risk: number;
        ended_this_month: number;
    };
    filterOptions: {
        clients: Option[];
        owners: Option[];
        statuses: Option[];
        objectives: Option[];
    };
    formOptions: CampaignFormOptions;
    can: { create: boolean; templates: boolean };
    today: string;
    campaigns: Paginated<HubCampaign> | null;
    timeline: GanttCampaign[] | null;
};

const pad = (value: number) => String(value).padStart(2, '0');

/**
 * Step C-1.1 — the org-wide campaigns hub: what's live, what's launching, what's at risk, then
 * every campaign as ruled rows (or a 90-day timeline). Filters are query-string state, so a
 * filtered view can be shared or bookmarked.
 */
export default function CampaignsHub({
    view,
    filters,
    stats,
    filterOptions,
    formOptions,
    can,
    today,
    campaigns,
    timeline,
}: HubProps) {
    const query: Record<string, TabFilterValue> = {
        view: filters.view === 'timeline' ? 'timeline' : null,
        search: filters.search,
        client: filters.client_id,
        status: filters.status,
        timeline: filters.timeline,
        owner: filters.owner_id,
        objective: filters.objective,
        health: filters.health,
        sort: filters.sort === 'start_asc' ? null : filters.sort,
    };
    const visit = (changes: Record<string, TabFilterValue>) =>
        visitClientTab(campaignsIndex.url(), query, changes, [
            'campaigns',
            'timeline',
            'filters',
            'view',
            'today',
        ]);

    return (
        <Page>
            <PageHeader
                eyebrow="Campaigns"
                title={
                    <>
                        Every campaign, in <em>one</em> place.
                    </>
                }
                description="What’s live, what’s next and what needs a hand, across every client."
                actions={
                    can.create && (
                        <>
                            <Button asChild variant="outline">
                                <Link href={compareRoute()}>
                                    <Columns3 />
                                    Compare
                                </Link>
                            </Button>
                            {can.templates && (
                                <Button asChild variant="outline">
                                    <Link href={templatesIndex()}>
                                        <LayoutTemplate />
                                        Templates
                                    </Link>
                                </Button>
                            )}
                            <NewCampaignDialog
                                options={formOptions}
                                clients={filterOptions.clients}
                            />
                        </>
                    )
                }
            />

            <Deferred
                data="stats"
                fallback={<Skeleton className="h-36 rounded-2xl" />}
            >
                <StatStrip
                    items={[
                        {
                            label: 'Live now',
                            value: pad(stats?.live ?? 0),
                            caption: 'Running today',
                            render: (cell) => (
                                <button
                                    type="button"
                                    className="w-full text-left"
                                    onClick={() =>
                                        visit({
                                            timeline: 'live',
                                            health: null,
                                        })
                                    }
                                >
                                    {cell}
                                </button>
                            ),
                        },
                        {
                            label: 'Launching',
                            value: pad(stats?.launching ?? 0),
                            caption: 'In the next 14 days',
                            render: (cell) => (
                                <button
                                    type="button"
                                    className="w-full text-left"
                                    onClick={() =>
                                        visit({
                                            timeline: 'upcoming',
                                            health: null,
                                        })
                                    }
                                >
                                    {cell}
                                </button>
                            ),
                        },
                        {
                            label: 'At risk',
                            value: pad(stats?.at_risk ?? 0),
                            caption: 'Red health',
                            render: (cell) => (
                                <button
                                    type="button"
                                    className="w-full text-left"
                                    onClick={() =>
                                        visit({ health: 'red', timeline: null })
                                    }
                                >
                                    {cell}
                                </button>
                            ),
                        },
                        {
                            label: 'Wrapped',
                            value: pad(stats?.ended_this_month ?? 0),
                            caption: 'Ended this month',
                        },
                    ]}
                />
            </Deferred>

            <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div className="flex flex-wrap items-end gap-3">
                    <TabSearchFilter
                        id="hub-search"
                        label="Search"
                        placeholder="Name or code"
                        value={filters.search}
                        onSearch={(search) => visit({ search })}
                    />
                    <TabSelectFilter
                        id="hub-client"
                        label="Client"
                        allLabel="All clients"
                        value={
                            filters.client_id ? String(filters.client_id) : null
                        }
                        options={filterOptions.clients}
                        onChange={(client) => visit({ client })}
                    />
                    {view === 'list' && (
                        <>
                            <TabSelectFilter
                                id="hub-status"
                                label="Status"
                                allLabel="Open (not archived)"
                                value={filters.status}
                                options={filterOptions.statuses}
                                onChange={(status) => visit({ status })}
                            />
                            <TabSelectFilter
                                id="hub-timeline"
                                label="Timing"
                                allLabel="Any time"
                                value={filters.timeline}
                                options={[
                                    { value: 'live', label: 'Live' },
                                    { value: 'upcoming', label: 'Upcoming' },
                                    { value: 'ended', label: 'Ended' },
                                    { value: 'undated', label: 'No dates' },
                                ]}
                                onChange={(value) => visit({ timeline: value })}
                            />
                            <TabSelectFilter
                                id="hub-health"
                                label="Health"
                                allLabel="Any health"
                                value={filters.health}
                                options={[
                                    { value: 'red', label: 'Red' },
                                    { value: 'amber', label: 'Amber' },
                                    { value: 'green', label: 'Green' },
                                ]}
                                onChange={(health) => visit({ health })}
                            />
                            <TabSelectFilter
                                id="hub-owner"
                                label="Owner"
                                allLabel="Anyone"
                                value={
                                    filters.owner_id
                                        ? String(filters.owner_id)
                                        : null
                                }
                                options={filterOptions.owners}
                                onChange={(owner) => visit({ owner })}
                            />
                            <TabSelectFilter
                                id="hub-objective"
                                label="Objective"
                                allLabel="Any objective"
                                value={filters.objective}
                                options={filterOptions.objectives}
                                onChange={(objective) => visit({ objective })}
                            />
                            <TabSelectFilter
                                id="hub-sort"
                                label="Order"
                                allLabel="Starting soonest"
                                value={
                                    filters.sort === 'start_desc'
                                        ? 'start_desc'
                                        : null
                                }
                                options={[
                                    {
                                        value: 'start_desc',
                                        label: 'Starting latest',
                                    },
                                ]}
                                onChange={(sort) => visit({ sort })}
                            />
                        </>
                    )}
                </div>
                <ToggleGroup
                    type="single"
                    value={view}
                    onValueChange={(next) =>
                        next &&
                        visit({ view: next === 'timeline' ? 'timeline' : null })
                    }
                    aria-label="View"
                >
                    <ToggleGroupItem value="list" className="px-3">
                        <List />
                        List
                    </ToggleGroupItem>
                    <ToggleGroupItem value="timeline" className="px-3">
                        <CalendarRange />
                        Timeline
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            {view === 'timeline' && timeline ? (
                timeline.length === 0 ? (
                    <EmptyState
                        icon={CalendarRange}
                        title="A clear runway."
                        description="No dated campaigns in the next 90 days."
                    />
                ) : (
                    <CampaignGantt campaigns={timeline} today={today} />
                )
            ) : campaigns && campaigns.data.length > 0 ? (
                <>
                    <ol className="border-foreground/85 flex flex-col border-t">
                        {campaigns.data.map((campaign) => (
                            <li
                                key={campaign.id}
                                className="border-border hover:bg-muted/40 grid gap-3 border-b px-1 py-4 transition-colors md:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_minmax(0,1fr)_auto] md:items-center md:gap-6"
                            >
                                <Link
                                    href={showCampaign(campaign.id)}
                                    className="group flex min-w-0 flex-col gap-1.5"
                                >
                                    <span className="flex min-w-0 items-center gap-2">
                                        <CampaignChip
                                            campaign={campaign}
                                            compact
                                        />
                                        <span className="group-hover:text-primary truncate text-[15px] font-semibold">
                                            {campaign.name}
                                        </span>
                                    </span>
                                    <span className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                        <span>{campaign.client.name}</span>
                                        {campaign.owner && (
                                            <span className="inline-flex items-center gap-1">
                                                <UserRound className="size-3" />
                                                {campaign.owner.name}
                                            </span>
                                        )}
                                    </span>
                                </Link>
                                <div className="flex min-w-0 flex-col gap-1">
                                    <span className="text-sm">
                                        {campaign.timeline.sentence}
                                    </span>
                                    <span className="text-muted-foreground font-mono text-[11px]">
                                        {formatDateRange(
                                            campaign.start_date,
                                            campaign.end_date,
                                        )}
                                    </span>
                                </div>
                                <div className="flex min-w-0 flex-col gap-1.5">
                                    <span className="text-muted-foreground font-mono text-[11px] tabular-nums">
                                        {campaign.posts_published} of{' '}
                                        {Math.max(
                                            campaign.posts_total,
                                            campaign.deliverables_total,
                                        )}{' '}
                                        out
                                    </span>
                                    {campaign.readiness && (
                                        <ReadinessMeter
                                            readiness={campaign.readiness}
                                        />
                                    )}
                                </div>
                                <div className="flex flex-wrap items-center gap-2 md:justify-end">
                                    <StatusPill
                                        kind="campaign"
                                        value={campaign.status}
                                        label={campaign.status_label}
                                    />
                                    {campaign.health && (
                                        <HealthPill health={campaign.health} />
                                    )}
                                </div>
                            </li>
                        ))}
                    </ol>
                    <Pagination
                        page={campaigns}
                        label="campaigns"
                        onPageChange={(page) => visit({ page })}
                    />
                </>
            ) : (
                <EmptyState
                    icon={Megaphone}
                    title={
                        Object.values(query).some(Boolean)
                            ? 'Nothing matches.'
                            : 'No campaigns yet.'
                    }
                    description={
                        Object.values(query).some(Boolean)
                            ? 'Try fewer filters.'
                            : can.templates
                              ? 'Start a campaign from a template and you’ll have a plan in a minute.'
                              : 'Start one and you’ll have a brief, a plan and a board in one place.'
                    }
                    action={
                        can.templates && !Object.values(query).some(Boolean) ? (
                            <Button asChild>
                                <Link href={templatesIndex()}>
                                    <LayoutTemplate />
                                    Browse templates
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />
            )}
        </Page>
    );
}

CampaignsHub.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};
