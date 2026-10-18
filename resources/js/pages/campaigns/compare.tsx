import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, Columns3, X } from 'lucide-react';
import { CampaignChip } from '@/components/campaigns/campaign-chip';
import { formatKpi } from '@/components/campaigns/results/kpi-rows';
import type { KpiResult } from '@/components/campaigns/results/kpi-rows';
import InputError from '@/components/input-error';
import { EmptyState, Page, PageHeader, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateRange } from '@/lib/dates';
import { cn } from '@/lib/utils';
import {
    compare as compareRoute,
    index as campaignsIndex,
    show as showCampaign,
} from '@/routes/campaigns';

type CompareCampaign = {
    id: number;
    name: string;
    code: string | null;
    colour: string | null;
    status: string;
    status_label: string;
    client_name: string | null;
    objective_label: string | null;
    start_date: string | null;
    end_date: string | null;
    duration_days: number | null;
};

type CompareCell = {
    campaign_id: number;
    actual: number | null;
    available: boolean;
    target: number | null;
    met: boolean | null;
};

type CompareMetric = {
    metric: string;
    label: string;
    unit: KpiResult['unit'];
    lower_is_better: boolean;
    cells: CompareCell[];
};

type CampaignOption = { value: string; label: string; client: string | null };

/**
 * Campaigns enhancement C-7.4 — two to four campaigns side by side. Who each was for, its
 * objective and how long it ran sit above the numbers so the comparison stays fair; the best
 * result on each row is marked, and targets show where one was set.
 */
export default function CampaignCompare({
    selected,
    max,
    comparison,
    options,
}: {
    selected: number[];
    max: number;
    comparison: {
        campaigns: CompareCampaign[];
        metrics: CompareMetric[];
    } | null;
    options: CampaignOption[];
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const idError = Object.entries(errors).find(
        ([key]) => key === 'ids' || key.startsWith('ids.'),
    )?.[1];

    const visit = (ids: number[]) =>
        router.get(compareRoute.url(), ids.length > 0 ? { ids } : {}, {
            preserveScroll: true,
        });

    const available = options.filter(
        (option) => !selected.includes(Number(option.value)),
    );
    const byId = new Map(
        options.map((option) => [Number(option.value), option]),
    );

    return (
        <>
            <Head title="Compare campaigns" />
            <Page>
                <Link
                    href={campaignsIndex()}
                    className="text-muted-foreground hover:text-foreground -mb-4 inline-flex items-center gap-1.5 self-start text-sm"
                >
                    <ArrowLeft className="size-4" />
                    Campaigns
                </Link>
                <PageHeader
                    eyebrow="Campaigns — compare"
                    title={
                        <>
                            Side by <em>side</em>.
                        </>
                    }
                    description={`Pick two to ${max} campaigns. Objective and length sit above the numbers, so you compare like with like.`}
                />

                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        {selected.map((id) => (
                            <span
                                key={id}
                                className="border-border/70 bg-card inline-flex items-center gap-1.5 rounded-full border py-1 pr-1 pl-3 text-sm"
                            >
                                {byId.get(id)?.label ?? `Campaign ${id}`}
                                <button
                                    type="button"
                                    onClick={() =>
                                        visit(
                                            selected.filter(
                                                (other) => other !== id,
                                            ),
                                        )
                                    }
                                    className="hover:bg-muted text-muted-foreground rounded-full p-1"
                                    aria-label={`Remove ${byId.get(id)?.label ?? 'campaign'}`}
                                >
                                    <X className="size-3.5" />
                                </button>
                            </span>
                        ))}
                        {selected.length < max && available.length > 0 && (
                            <Select
                                value=""
                                onValueChange={(value) =>
                                    visit([...selected, Number(value)])
                                }
                            >
                                <SelectTrigger className="w-64">
                                    <SelectValue placeholder="Add a campaign…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {available.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                            {option.client && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    · {option.client}
                                                </span>
                                            )}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                    </div>
                    <InputError message={idError} />
                </div>

                {comparison === null ? (
                    <EmptyState
                        icon={Columns3}
                        title={
                            selected.length === 1
                                ? 'Add one more.'
                                : 'Pick two campaigns to start.'
                        }
                        description="Results line up here, metric by metric, with each campaign’s targets."
                    />
                ) : (
                    <Panel
                        icon={Columns3}
                        eyebrow={`${comparison.campaigns.length} campaigns`}
                        title="Results"
                        bodyClassName="p-0 sm:p-0"
                    >
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[640px] text-sm">
                                <thead>
                                    <tr className="border-border/70 border-b">
                                        <th
                                            scope="col"
                                            className="w-48 px-5 py-4 text-left"
                                        >
                                            <span className="sr-only">
                                                Measure
                                            </span>
                                        </th>
                                        {comparison.campaigns.map(
                                            (campaign) => (
                                                <th
                                                    key={campaign.id}
                                                    scope="col"
                                                    className="px-5 py-4 text-left align-top font-normal"
                                                >
                                                    <Link
                                                        href={showCampaign(
                                                            campaign.id,
                                                        )}
                                                        className="group flex flex-col gap-1.5"
                                                    >
                                                        <CampaignChip
                                                            campaign={campaign}
                                                            compact
                                                        />
                                                        <span className="group-hover:text-primary font-semibold">
                                                            {campaign.name}
                                                        </span>
                                                    </Link>
                                                </th>
                                            ),
                                        )}
                                    </tr>
                                </thead>
                                <tbody className="divide-border/70 divide-y">
                                    <FactRow
                                        label="Client"
                                        campaigns={comparison.campaigns}
                                        render={(campaign) =>
                                            campaign.client_name ?? '—'
                                        }
                                    />
                                    <FactRow
                                        label="Objective"
                                        campaigns={comparison.campaigns}
                                        render={(campaign) =>
                                            campaign.objective_label ?? '—'
                                        }
                                    />
                                    <FactRow
                                        label="Dates"
                                        campaigns={comparison.campaigns}
                                        render={(campaign) => (
                                            <span className="font-mono text-xs">
                                                {formatDateRange(
                                                    campaign.start_date,
                                                    campaign.end_date,
                                                )}
                                            </span>
                                        )}
                                    />
                                    <FactRow
                                        label="Length"
                                        campaigns={comparison.campaigns}
                                        render={(campaign) =>
                                            campaign.duration_days === null
                                                ? '—'
                                                : `${campaign.duration_days} days`
                                        }
                                    />
                                    <FactRow
                                        label="Status"
                                        campaigns={comparison.campaigns}
                                        render={(campaign) => (
                                            <StatusPill
                                                kind="campaign"
                                                value={campaign.status}
                                                label={campaign.status_label}
                                            />
                                        )}
                                    />
                                    {comparison.metrics.map((metric) => (
                                        <MetricRow
                                            key={metric.metric}
                                            metric={metric}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Panel>
                )}
            </Page>
        </>
    );
}

function FactRow({
    label,
    campaigns,
    render,
}: {
    label: string;
    campaigns: CompareCampaign[];
    render: (campaign: CompareCampaign) => React.ReactNode;
}) {
    return (
        <tr>
            <th
                scope="row"
                className="eyebrow text-muted-foreground px-5 py-3 text-left font-normal"
            >
                {label}
            </th>
            {campaigns.map((campaign) => (
                <td key={campaign.id} className="px-5 py-3">
                    {render(campaign)}
                </td>
            ))}
        </tr>
    );
}

function MetricRow({ metric }: { metric: CompareMetric }) {
    const values = metric.cells
        .filter((cell) => cell.available && cell.actual !== null)
        .map((cell) => cell.actual as number);
    const best =
        values.length > 1
            ? metric.lower_is_better
                ? Math.min(...values)
                : Math.max(...values)
            : null;

    return (
        <tr>
            <th scope="row" className="px-5 py-3 text-left font-medium">
                {metric.label}
            </th>
            {metric.cells.map((cell) => (
                <td key={cell.campaign_id} className="px-5 py-3 align-top">
                    {cell.available ? (
                        <span className="flex flex-col gap-0.5">
                            <span
                                className={cn(
                                    'tabular-nums',
                                    best !== null &&
                                        cell.actual === best &&
                                        'font-semibold',
                                )}
                            >
                                {formatKpi(metric.unit, cell.actual)}
                                {best !== null && cell.actual === best && (
                                    <span className="text-muted-foreground ml-1.5 text-xs font-normal">
                                        Best
                                    </span>
                                )}
                            </span>
                            {cell.target !== null && (
                                <span
                                    className={cn(
                                        'inline-flex items-center gap-1 text-xs',
                                        cell.met
                                            ? 'text-success'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {cell.met && <Check className="size-3" />}
                                    Target {formatKpi(metric.unit, cell.target)}
                                </span>
                            )}
                        </span>
                    ) : (
                        <span className="text-muted-foreground text-xs">
                            Not tracked yet
                        </span>
                    )}
                </td>
            ))}
        </tr>
    );
}

CampaignCompare.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};
