import { Deferred, Head } from '@inertiajs/react';
import { LineChart as LineChartIcon, Sparkles } from 'lucide-react';
import {
    EmptyState,
    Page,
    PageHeader,
    Panel,
    StatStrip,
} from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { index as insightsIndex } from '@/routes/business/insights';

type Week = { week_start: string; label: string; published: number };
type PlatformRow = {
    platform: string;
    platform_label: string;
    published: number;
};

type Insights = {
    consistency: Week[];
    streak: number;
    publishedTotal: number;
    byPlatform: PlatformRow[];
    goalPerWeek: number | null;
    hasMetrics: boolean;
};

export default function BusinessInsights({
    insights,
}: {
    insights?: Insights;
}) {
    return (
        <Page>
            <Head title="Insights" />

            <PageHeader
                eyebrow="How you're tracking"
                title={
                    <>
                        Your <em>insights</em>.
                    </>
                }
                description="Your posting consistency, week by week. Reach and engagement arrive once your accounts have some history."
            />

            <Deferred data="insights" fallback={<InsightsSkeleton />}>
                <InsightsBody insights={insights} />
            </Deferred>
        </Page>
    );
}

function InsightsBody({ insights }: { insights?: Insights }) {
    if (!insights) {
        return <InsightsSkeleton />;
    }

    const max = Math.max(
        1,
        ...insights.consistency.map((week) => week.published),
    );

    return (
        <>
            <StatStrip
                items={[
                    {
                        label: 'Current streak',
                        value: insights.streak,
                        caption:
                            insights.streak === 1
                                ? 'week with a post'
                                : 'weeks with a post',
                    },
                    {
                        label: 'Published · 8 weeks',
                        value: insights.publishedTotal,
                        caption: 'posts live',
                    },
                    {
                        label: 'Weekly goal',
                        value: insights.goalPerWeek ?? '—',
                        caption:
                            insights.goalPerWeek !== null
                                ? 'posts a week'
                                : 'not set yet',
                    },
                ]}
            />

            <Panel
                icon={LineChartIcon}
                eyebrow="Last 8 weeks"
                title="Posting consistency"
            >
                <div className="flex items-end justify-between gap-2 sm:gap-3">
                    {insights.consistency.map((week) => (
                        <div
                            key={week.week_start}
                            className="flex min-w-0 flex-1 flex-col items-center gap-2"
                        >
                            <span className="text-muted-foreground font-mono text-[11px] tabular-nums">
                                {week.published}
                            </span>
                            <div className="flex h-32 w-full items-end">
                                <div
                                    className={cn(
                                        'w-full rounded-t-md transition-all',
                                        week.published > 0
                                            ? 'bg-primary'
                                            : 'bg-muted',
                                    )}
                                    style={{
                                        height: `${Math.max(
                                            week.published > 0 ? 8 : 4,
                                            (week.published / max) * 100,
                                        )}%`,
                                    }}
                                />
                            </div>
                            <span className="text-muted-foreground truncate text-[11px]">
                                {week.label}
                            </span>
                        </div>
                    ))}
                </div>
            </Panel>

            <div className="grid gap-5 lg:grid-cols-2">
                <Panel eyebrow="Where you post" title="By platform">
                    {insights.byPlatform.length === 0 ? (
                        <EmptyState
                            compact
                            icon={LineChartIcon}
                            title="Nothing published yet."
                            description="Publish a few posts and you'll see the split here."
                        />
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {insights.byPlatform.map((row) => {
                                const Icon = platformIcon(row.platform);

                                return (
                                    <li
                                        key={row.platform}
                                        className="flex items-center gap-3"
                                    >
                                        <Icon className="text-muted-foreground size-4 shrink-0" />
                                        <span className="flex-1 text-sm">
                                            {row.platform_label}
                                        </span>
                                        <span className="font-display font-bold tabular-nums">
                                            {row.published}
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Panel>

                <Panel eyebrow="Coming soon" title="Reach & engagement">
                    <EmptyState
                        compact
                        icon={Sparkles}
                        title="Reach lands soon."
                        description="Once your accounts have published a while, you'll see reach, engagement and your best times to post here."
                    />
                </Panel>
            </div>
        </>
    );
}

function InsightsSkeleton() {
    return (
        <div className="flex flex-col gap-5">
            <Skeleton className="h-28 rounded-2xl" />
            <Skeleton className="h-56 rounded-2xl" />
        </div>
    );
}

BusinessInsights.layout = {
    breadcrumbs: [{ title: 'Insights', href: insightsIndex() }],
};
