import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, Columns3, Target, Trophy, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    CampaignChip,
    campaignSwatch,
    CampaignSwatch,
} from '@/components/campaigns/campaign-chip';
import { formatKpi } from '@/components/campaigns/results/kpi-rows';
import type { KpiResult } from '@/components/campaigns/results/kpi-rows';
import InputError from '@/components/input-error';
import { MultiCombobox } from '@/components/multi-combobox';
import { EmptyState, Page, PageHeader, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
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

    // Optimistic local selection so toggles register instantly and successive picks compute from
    // the up-to-date set rather than racing the server round-trip. Reconciled to the server's
    // `selected` whenever it changes (e.g. a foreign id dropped by validation).
    const [picked, setPicked] = useState<number[]>(selected);
    const selectedKey = selected.join(',');
    useEffect(() => {
        setPicked(selected);
        // Resync only when the server's selection actually changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedKey]);

    const choose = (ids: number[]) => {
        setPicked(ids);
        router.get(compareRoute.url(), ids.length > 0 ? { ids } : {}, {
            preserveScroll: true,
            // Keep the multi-select open and its props in sync across toggles, so several
            // campaigns can be picked in one pass without the popover closing each time.
            preserveState: true,
        });
    };

    const byId = new Map(
        options.map((option) => [Number(option.value), option]),
    );

    // The dropdown shows the client for disambiguation and searches on it; the chips below stay
    // clean (campaign name only), reading from `byId`.
    const comboOptions = options.map((option) => ({
        value: option.value,
        label: option.client
            ? `${option.label} · ${option.client}`
            : option.label,
        keywords: option.client ? [option.client] : [],
    }));

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
                        {picked.map((id) => (
                            <span
                                key={id}
                                className="border-border/70 bg-card inline-flex items-center gap-1.5 rounded-full border py-1 pr-1 pl-3 text-sm"
                            >
                                {byId.get(id)?.label ?? `Campaign ${id}`}
                                <button
                                    type="button"
                                    onClick={() =>
                                        choose(
                                            picked.filter(
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
                        {options.length > 0 && (
                            <MultiCombobox
                                options={comboOptions}
                                value={picked.map(String)}
                                onChange={(values) =>
                                    choose(values.map(Number))
                                }
                                max={max}
                                placeholder="Select campaigns…"
                                searchPlaceholder="Search campaigns…"
                                emptyText="No campaigns match that."
                                className="w-64"
                            />
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
                    <ComparisonResults comparison={comparison} />
                )}
            </Page>
        </>
    );
}

/** The best value on a row (min or max), only once more than one campaign reports it. */
function bestValue(metric: CompareMetric): number | null {
    const values = metric.cells
        .filter((cell) => cell.available && cell.actual !== null)
        .map((cell) => cell.actual as number);
    if (values.length < 2) {
        return null;
    }
    return metric.lower_is_better ? Math.min(...values) : Math.max(...values);
}

/**
 * Results laid out the way the rest of Alima reads data: an identity card per campaign carrying
 * who it was for and how it did at a glance, then every metric as a set of proportional bars in
 * each campaign's own colour — the leader marked, the target drawn on the track.
 */
function ComparisonResults({
    comparison,
}: {
    comparison: {
        campaigns: CompareCampaign[];
        metrics: CompareMetric[];
    };
}) {
    const { campaigns, metrics } = comparison;

    // Per-campaign headline numbers for the identity cards: metrics led, and targets met out of
    // those set. Ties on a metric count a lead for each campaign that shares the best value.
    const leads = new Map<number, number>();
    const targetsHit = new Map<number, number>();
    const targetsSet = new Map<number, number>();
    const bump = (map: Map<number, number>, id: number) =>
        map.set(id, (map.get(id) ?? 0) + 1);

    for (const metric of metrics) {
        const best = bestValue(metric);
        for (const cell of metric.cells) {
            if (best !== null && cell.available && cell.actual === best) {
                bump(leads, cell.campaign_id);
            }
            if (cell.target !== null) {
                bump(targetsSet, cell.campaign_id);
                if (cell.met) {
                    bump(targetsHit, cell.campaign_id);
                }
            }
        }
    }

    const cardCols: Record<number, string> = {
        2: 'sm:grid-cols-2',
        3: 'sm:grid-cols-3',
        4: 'sm:grid-cols-2 lg:grid-cols-4',
    };

    return (
        <div className="flex flex-col gap-8">
            <div
                className={cn(
                    'grid grid-cols-1 gap-4',
                    cardCols[campaigns.length] ?? 'sm:grid-cols-2',
                )}
            >
                {campaigns.map((campaign) => (
                    <CampaignCard
                        key={campaign.id}
                        campaign={campaign}
                        leads={leads.get(campaign.id) ?? 0}
                        targetsHit={targetsHit.get(campaign.id) ?? 0}
                        targetsSet={targetsSet.get(campaign.id) ?? 0}
                    />
                ))}
            </div>

            <Panel
                icon={Columns3}
                eyebrow={`${campaigns.length} campaigns · ${metrics.length} ${metrics.length === 1 ? 'metric' : 'metrics'}`}
                title="Results"
            >
                {metrics.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No shared metrics to compare yet. Set targets or connect
                        results on these campaigns to see them line up here.
                    </p>
                ) : (
                    <div className="divide-border/70 flex flex-col divide-y">
                        {metrics.map((metric) => (
                            <MetricComparison
                                key={metric.metric}
                                metric={metric}
                                campaigns={campaigns}
                            />
                        ))}
                    </div>
                )}
            </Panel>
        </div>
    );
}

function CampaignCard({
    campaign,
    leads,
    targetsHit,
    targetsSet,
}: {
    campaign: CompareCampaign;
    leads: number;
    targetsHit: number;
    targetsSet: number;
}) {
    const facts: { label: string; value: React.ReactNode }[] = [
        { label: 'Client', value: campaign.client_name ?? '—' },
        { label: 'Objective', value: campaign.objective_label ?? '—' },
        {
            label: 'Dates',
            value: (
                <span className="font-mono text-xs">
                    {formatDateRange(campaign.start_date, campaign.end_date)}
                </span>
            ),
        },
        {
            label: 'Length',
            value:
                campaign.duration_days === null
                    ? '—'
                    : `${campaign.duration_days} days`,
        },
    ];

    return (
        <div className="border-border/80 bg-card relative flex flex-col overflow-hidden rounded-2xl border shadow-sm">
            <span
                aria-hidden
                className={cn(
                    'absolute inset-x-0 top-0 h-1',
                    campaignSwatch(campaign.colour),
                )}
            />
            <div className="flex flex-1 flex-col gap-4 p-5 pt-6">
                <div className="flex items-start justify-between gap-2">
                    <CampaignChip campaign={campaign} compact />
                    <StatusPill
                        kind="campaign"
                        value={campaign.status}
                        label={campaign.status_label}
                    />
                </div>
                <Link
                    href={showCampaign(campaign.id)}
                    className="font-display hover:text-primary text-lg leading-tight font-semibold tracking-[-0.01em]"
                >
                    {campaign.name}
                </Link>
                <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                    {facts.map((fact) => (
                        <div key={fact.label} className="min-w-0">
                            <dt className="eyebrow text-muted-foreground mb-0.5">
                                {fact.label}
                            </dt>
                            <dd
                                className="truncate"
                                title={
                                    typeof fact.value === 'string'
                                        ? fact.value
                                        : undefined
                                }
                            >
                                {fact.value}
                            </dd>
                        </div>
                    ))}
                </dl>
                <div className="border-border/70 text-muted-foreground mt-auto flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t pt-3 text-xs">
                    <span className="inline-flex items-center gap-1.5">
                        <Trophy className="size-3.5" />
                        <span className="text-foreground font-medium tabular-nums">
                            {leads}
                        </span>
                        {leads === 1 ? 'metric led' : 'metrics led'}
                    </span>
                    {targetsSet > 0 && (
                        <span className="inline-flex items-center gap-1.5">
                            <Target className="size-3.5" />
                            <span className="text-foreground font-medium tabular-nums">
                                {targetsHit}/{targetsSet}
                            </span>
                            targets hit
                        </span>
                    )}
                </div>
            </div>
        </div>
    );
}

function MetricComparison({
    metric,
    campaigns,
}: {
    metric: CompareMetric;
    campaigns: CompareCampaign[];
}) {
    const cellById = new Map(
        metric.cells.map((cell) => [cell.campaign_id, cell]),
    );
    const values = metric.cells
        .filter((cell) => cell.available && cell.actual !== null)
        .map((cell) => cell.actual as number);
    const best = bestValue(metric);
    // Bars are scaled to the largest value on the row (including any targets, so a target marker
    // never falls off the track). A floor of 1 keeps the maths safe when everything is zero.
    const targets = metric.cells
        .map((cell) => cell.target)
        .filter((target): target is number => target !== null);
    const scaleMax = Math.max(1, ...values, ...targets);

    return (
        <div className="flex flex-col gap-3 py-5 first:pt-0 last:pb-0">
            <div className="flex items-baseline justify-between gap-3">
                <h3 className="text-sm font-medium">{metric.label}</h3>
                {metric.lower_is_better && (
                    <span className="eyebrow text-muted-foreground tracking-[0.04em] normal-case">
                        Lower is better
                    </span>
                )}
            </div>
            <ul className="flex flex-col gap-3.5">
                {campaigns.map((campaign) => {
                    const cell = cellById.get(campaign.id);
                    const isBest =
                        best !== null &&
                        cell?.available === true &&
                        cell.actual === best;

                    return (
                        <li
                            key={campaign.id}
                            className="grid grid-cols-[6.5rem_1fr_auto] items-center gap-x-3 gap-y-1.5 sm:grid-cols-[10rem_1fr_auto]"
                        >
                            <span className="flex min-w-0 items-center gap-1.5 text-xs">
                                <CampaignSwatch colour={campaign.colour} />
                                <span
                                    className="truncate"
                                    title={campaign.name}
                                >
                                    {campaign.name}
                                </span>
                            </span>

                            {cell?.available && cell.actual !== null ? (
                                <>
                                    <div
                                        role="meter"
                                        aria-label={`${campaign.name}: ${formatKpi(metric.unit, cell.actual)}`}
                                        aria-valuemin={0}
                                        aria-valuemax={scaleMax}
                                        aria-valuenow={cell.actual}
                                        className="relative h-2.5"
                                    >
                                        <div className="bg-muted h-full overflow-hidden rounded-full">
                                            <div
                                                className={cn(
                                                    'h-full rounded-full transition-[width] duration-700',
                                                    campaignSwatch(
                                                        campaign.colour,
                                                    ),
                                                )}
                                                style={{
                                                    width: `${Math.max((cell.actual / scaleMax) * 100, cell.actual > 0 ? 3 : 0)}%`,
                                                }}
                                            />
                                        </div>
                                        {cell.target !== null && (
                                            <span
                                                aria-hidden
                                                title={`Target ${formatKpi(metric.unit, cell.target)}`}
                                                className="bg-foreground/55 absolute inset-y-[-3px] w-0.5 rounded-full"
                                                style={{
                                                    left: `calc(${Math.min(cell.target / scaleMax, 1) * 100}% - 1px)`,
                                                }}
                                            />
                                        )}
                                    </div>
                                    <span
                                        className={cn(
                                            'text-right text-sm tabular-nums',
                                            isBest && 'font-semibold',
                                        )}
                                    >
                                        {formatKpi(metric.unit, cell.actual)}
                                    </span>
                                    {(isBest || cell.target !== null) && (
                                        <span className="col-span-2 col-start-2 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs">
                                            {isBest && (
                                                <span className="text-foreground inline-flex items-center gap-1 font-medium">
                                                    <Trophy className="size-3" />
                                                    Best
                                                </span>
                                            )}
                                            {cell.target !== null && (
                                                <span
                                                    className={cn(
                                                        'inline-flex items-center gap-1',
                                                        cell.met
                                                            ? 'text-success'
                                                            : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {cell.met ? (
                                                        <Check className="size-3" />
                                                    ) : (
                                                        <Target className="size-3" />
                                                    )}
                                                    Target{' '}
                                                    {formatKpi(
                                                        metric.unit,
                                                        cell.target,
                                                    )}
                                                </span>
                                            )}
                                        </span>
                                    )}
                                </>
                            ) : (
                                <span className="text-muted-foreground col-span-2 col-start-2 text-xs">
                                    Not tracked yet
                                </span>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

CampaignCompare.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};
