import { Link } from '@inertiajs/react';
import { BarChart3, Gauge, Medal, Share2, Timer } from 'lucide-react';
import { ShareBars } from '@/components/analytics/figures';
import { CampaignLayout } from '@/components/campaigns/campaign-layout';
import { KpiEditor } from '@/components/campaigns/results/kpi-editor';
import type { MetricOption } from '@/components/campaigns/results/kpi-editor';
import { KpiRows } from '@/components/campaigns/results/kpi-rows';
import type { KpiResult } from '@/components/campaigns/results/kpi-rows';
import { PublishedByPhaseChart } from '@/components/campaigns/results/published-by-phase-chart';
import type { PhaseSeries } from '@/components/campaigns/results/published-by-phase-chart';
import { WrapReportPanel } from '@/components/campaigns/results/wrap-report-panel';
import type { WrapReport } from '@/components/campaigns/results/wrap-report-panel';
import type { CampaignWorkspaceProps } from '@/components/campaigns/types';
import { EmptyState, Panel, StatStrip } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { StatusPill } from '@/components/status-pill';
import { index as campaignsIndex } from '@/routes/campaigns';
import { edit as editPost } from '@/routes/posts';

type ResultsProps = CampaignWorkspaceProps & {
    kpis: KpiResult[];
    metrics: (MetricOption & {
        actual: number | null;
        detail: string | null;
    })[];
    series: PhaseSeries;
    platforms: { platform: string; published: number; scheduled: number }[];
    topPosts: {
        id: number;
        caption: string | null;
        status: string;
        status_label: string;
        platforms: string[];
        hours_to_approve: number;
    }[];
    approvals: {
        sent_to_client: number;
        approved: number;
        changes_requested: number;
        first_time_approval_rate: number | null;
        client_turnaround: { decisions: number; median_hours: number | null };
        waiting_now: number;
    };
    report: WrapReport | null;
    canEditResults: boolean;
};

const hours = (value: number | null) =>
    value === null
        ? '—'
        : value >= 48
          ? `${Math.round(value / 24)}d`
          : `${Math.round(value)}h`;
const platformLabel = (value: string) =>
    value.charAt(0).toUpperCase() + value.slice(1);

/**
 * Step C-7.2 — the Results tab: targets vs actuals, published over time by phase, the platform
 * split, the fastest sign-offs (until engagement data exists), approval turnaround, and the wrap
 * report (C-7.3). No fees or invoices here — those stay on Overview for Owner/Strategist.
 */
export default function CampaignResults(props: ResultsProps) {
    const {
        campaign,
        can,
        kpis,
        metrics,
        series,
        platforms,
        topPosts,
        approvals,
        report,
        canEditResults,
    } = props;
    const published = series.buckets.reduce(
        (sum, bucket) => sum + bucket.total,
        0,
    );

    return (
        <CampaignLayout {...props}>
            <StatStrip
                items={[
                    {
                        label: 'Published',
                        value: String(published).padStart(2, '0'),
                        caption: 'Posts live',
                    },
                    {
                        label: 'Client turnaround',
                        value: hours(approvals.client_turnaround.median_hours),
                        caption: `Median of ${approvals.client_turnaround.decisions} decisions`,
                    },
                    {
                        label: 'First-time approvals',
                        value:
                            approvals.first_time_approval_rate === null
                                ? '—'
                                : `${Math.round(approvals.first_time_approval_rate)}%`,
                        caption: 'Signed off without changes',
                    },
                    {
                        label: 'Waiting now',
                        value: String(approvals.waiting_now).padStart(2, '0'),
                        caption: 'With the client',
                    },
                ]}
            />

            <div className="grid gap-5 lg:grid-cols-[1.45fr_1fr]">
                <div className="flex min-w-0 flex-col gap-5">
                    <Panel
                        icon={Gauge}
                        eyebrow="Targets"
                        title="Are we hitting them?"
                        actions={
                            canEditResults && (
                                <KpiEditor
                                    campaignId={campaign.id}
                                    kpis={kpis}
                                    metrics={metrics}
                                />
                            )
                        }
                    >
                        {kpis.length === 0 ? (
                            <EmptyState
                                compact
                                icon={Gauge}
                                title="No targets yet."
                                description="Set a target or two and we’ll track them for you."
                            />
                        ) : (
                            <KpiRows kpis={kpis} />
                        )}
                    </Panel>

                    <Panel
                        icon={BarChart3}
                        eyebrow="Output"
                        title={`Published per ${series.granularity}, by phase`}
                    >
                        {published === 0 ? (
                            <EmptyState
                                compact
                                icon={BarChart3}
                                title="Nothing live yet."
                                description="Once posts publish, they line up here by day and phase."
                            />
                        ) : (
                            <PublishedByPhaseChart series={series} />
                        )}
                    </Panel>

                    {can.wrapReports && (
                        <WrapReportPanel
                            key={report?.version ?? 0}
                            campaignId={campaign.id}
                            report={report}
                            editable={canEditResults}
                            useAi={can.useAi}
                        />
                    )}
                </div>

                <div className="flex min-w-0 flex-col gap-5">
                    <Panel
                        icon={Share2}
                        eyebrow="Platforms"
                        title="Where it went out"
                    >
                        <ShareBars
                            rows={platforms.map((row) => ({
                                key: row.platform,
                                label: (
                                    <span className="inline-flex items-center gap-2">
                                        {(() => {
                                            const Icon = platformIcon(
                                                row.platform,
                                            );

                                            return <Icon className="size-4" />;
                                        })()}
                                        {platformLabel(row.platform)}
                                    </span>
                                ),
                                value: row.published,
                                hint: `${row.published} live · ${row.scheduled} queued`,
                                barClassName: 'bg-chart-1',
                            }))}
                            emptyLabel="No posts on any platform yet."
                        />
                    </Panel>

                    <Panel
                        icon={Medal}
                        eyebrow="Top posts"
                        title="Fastest sign-offs"
                        description="Ranked by approval speed until engagement data is on."
                    >
                        {topPosts.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No client approvals yet.
                            </p>
                        ) : (
                            <ol className="flex flex-col gap-3">
                                {topPosts.map((post, index) => (
                                    <li
                                        key={post.id}
                                        className="flex items-start gap-3"
                                    >
                                        <span className="text-muted-foreground w-5 shrink-0 pt-0.5 font-mono text-[11px] tabular-nums">
                                            {String(index + 1).padStart(2, '0')}
                                        </span>
                                        <Link
                                            href={editPost(post.id)}
                                            className="flex min-w-0 flex-1 flex-col gap-1"
                                        >
                                            <span className="hover:text-primary line-clamp-2 text-sm">
                                                {post.caption ??
                                                    'Untitled post'}
                                            </span>
                                            <span className="flex items-center gap-2">
                                                <StatusPill
                                                    kind="post"
                                                    value={post.status}
                                                    label={post.status_label}
                                                />
                                                <span className="text-muted-foreground inline-flex items-center gap-1 font-mono text-[11px]">
                                                    <Timer className="size-3" />
                                                    {hours(
                                                        post.hours_to_approve,
                                                    )}
                                                </span>
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </Panel>
                </div>
            </div>
        </CampaignLayout>
    );
}

CampaignResults.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};
