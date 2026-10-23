import { Deferred, Head, Link } from '@inertiajs/react';
import {
    Clock,
    FileText,
    Heart,
    LineChart as LineChartIcon,
    Sparkles,
    TrendingUp,
    Trophy,
    Users,
} from 'lucide-react';
import {
    EmptyState,
    Page,
    PageHeader,
    Panel,
    StatStrip,
} from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { index as insightsIndex } from '@/routes/business/insights';
import { index as mediaKitIndex } from '@/routes/business/media-kit';

type Week = { week_start: string; label: string; published: number };
type PlatformRow = {
    platform: string;
    platform_label: string;
    published: number;
};

type Totals = {
    impressions: number | null;
    reach: number | null;
    views: number | null;
    likes: number | null;
    comments: number | null;
    shares: number | null;
    saves: number | null;
    engagement: number | null;
};

type EngagementPlatform = {
    platform: string;
    label: string;
    available: boolean;
    note: string | null;
    published: number;
    measured: number;
    engagement: number | null;
    impressions: number | null;
    reach: number | null;
    engagement_rate: number | null;
};

type FollowerAccount = {
    social_account_id: number;
    platform: string;
    platform_label: string;
    handle: string;
    followers: number;
    growth: number | null;
};

type TopPost = {
    post_id: number;
    caption: string | null;
    thumbnail_url: string | null;
    published_at: string;
    platforms: string[];
    post_url: string | null;
    engagement: number | null;
    reach: number | null;
    impressions: number | null;
    engagement_rate: number | null;
};

type Engagement = {
    published_channels: number;
    measured_channels: number;
    totals: Totals;
    engagement_rate: number | null;
    by_platform: EngagementPlatform[];
    followers: {
        current: number | null;
        growth: number | null;
        accounts: FollowerAccount[];
    };
    top_posts: TopPost[];
    last_synced_at: string | null;
};

type BestTime = {
    social_account_id: number;
    platform: string;
    platform_label: string;
    handle: string;
    source: 'data' | 'default';
    sample: number;
    slot: { weekday: number; hour: number; label: string };
    alternatives: { weekday: number; hour: number; label: string }[];
};

type Takeaway = { text: string };
type Takeaways = {
    available: boolean;
    generated_at: string | null;
    takeaways: Takeaway[];
};

type Insights = {
    consistency: Week[];
    streak: number;
    publishedTotal: number;
    byPlatform: PlatformRow[];
    goalPerWeek: number | null;
    engagement: Engagement;
    bestTimes: BestTime[];
    windowDays: number;
    hasMetrics: boolean;
};

const nf = new Intl.NumberFormat();
const fmt = (value: number | null | undefined): string =>
    value === null || value === undefined ? '—' : nf.format(value);

export default function BusinessInsights({
    insights,
    takeaways,
}: {
    insights?: Insights;
    takeaways?: Takeaways;
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
                description="Your posting rhythm, reach and best times to post — for your brand, all in one place."
                actions={
                    <Link href={mediaKitIndex().url}>
                        <Button variant="outline">
                            <FileText className="size-4" />
                            Media kit
                        </Button>
                    </Link>
                }
            />

            <Deferred data="takeaways" fallback={null}>
                <TakeawaysPanel takeaways={takeaways} />
            </Deferred>

            <Deferred data="insights" fallback={<InsightsSkeleton />}>
                <InsightsBody insights={insights} />
            </Deferred>
        </Page>
    );
}

function TakeawaysPanel({ takeaways }: { takeaways?: Takeaways }) {
    if (
        !takeaways ||
        !takeaways.available ||
        takeaways.takeaways.length === 0
    ) {
        return null;
    }

    return (
        <Panel
            icon={Sparkles}
            eyebrow="In plain English"
            title="What the numbers say"
        >
            <ul className="flex flex-col gap-3">
                {takeaways.takeaways.map((item, index) => (
                    <li
                        key={index}
                        className="flex items-start gap-3 text-[15px]"
                    >
                        <span className="bg-lime text-ink mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold">
                            {index + 1}
                        </span>
                        <span className="text-pretty">{item.text}</span>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

function InsightsBody({ insights }: { insights?: Insights }) {
    if (!insights) {
        return <InsightsSkeleton />;
    }

    const { engagement } = insights;

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
                        label: `Reach · ${insights.windowDays}d`,
                        value: fmt(engagement.totals.reach),
                        caption: insights.hasMetrics
                            ? 'people reached'
                            : 'arrives with metrics',
                    },
                    {
                        label: 'Followers',
                        value: fmt(engagement.followers.current),
                        caption: growthCaption(engagement.followers.growth),
                    },
                ]}
            />

            <ConsistencyPanel weeks={insights.consistency} />

            <div className="grid gap-5 lg:grid-cols-2">
                <ReachPanel
                    engagement={engagement}
                    fallback={insights.byPlatform}
                />
                <FollowersPanel followers={engagement.followers} />
            </div>

            <TopPostsPanel posts={engagement.top_posts} />

            <BestTimesPanel bestTimes={insights.bestTimes} />
        </>
    );
}

function ConsistencyPanel({ weeks }: { weeks: Week[] }) {
    const max = Math.max(1, ...weeks.map((week) => week.published));

    return (
        <Panel
            icon={LineChartIcon}
            eyebrow="Last 8 weeks"
            title="Posting consistency"
        >
            <div className="flex items-end justify-between gap-2 sm:gap-3">
                {weeks.map((week) => (
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
    );
}

function ReachPanel({
    engagement,
    fallback,
}: {
    engagement: Engagement;
    fallback: PlatformRow[];
}) {
    const rows = engagement.by_platform.filter((row) => row.published > 0);

    if (engagement.measured_channels === 0) {
        return (
            <Panel
                icon={Heart}
                eyebrow="Reach & engagement"
                title="By platform"
            >
                {fallback.length === 0 ? (
                    <EmptyState
                        compact
                        icon={Heart}
                        title="Nothing published yet."
                        description="Publish a few posts and your reach and engagement will show up here as the platforms report back."
                    />
                ) : (
                    <>
                        <p className="text-muted-foreground mb-4 text-[13px]">
                            Reach and engagement land once the platforms report
                            on your posts. Here's where you've been posting:
                        </p>
                        <ul className="flex flex-col gap-3">
                            {fallback.map((row) => {
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
                    </>
                )}
            </Panel>
        );
    }

    return (
        <Panel
            icon={Heart}
            eyebrow="Reach & engagement"
            title="By platform"
            description={
                engagement.engagement_rate !== null
                    ? `${engagement.engagement_rate}% average engagement rate`
                    : undefined
            }
        >
            <ul className="flex flex-col gap-4">
                {rows.map((row) => {
                    const Icon = platformIcon(row.platform);

                    return (
                        <li
                            key={row.platform}
                            className="flex items-center gap-3"
                        >
                            <Icon className="text-muted-foreground size-5 shrink-0" />
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">
                                    {row.label}
                                </p>
                                <p className="text-muted-foreground text-[12px]">
                                    {row.available
                                        ? `${fmt(row.reach ?? row.impressions)} reached · ${row.engagement_rate ?? '—'}% engaged`
                                        : row.note}
                                </p>
                            </div>
                            <span className="font-display font-bold tabular-nums">
                                {fmt(row.engagement)}
                            </span>
                        </li>
                    );
                })}
            </ul>
        </Panel>
    );
}

function FollowersPanel({ followers }: { followers: Engagement['followers'] }) {
    if (followers.accounts.length === 0) {
        return (
            <Panel icon={Users} eyebrow="Audience" title="Followers">
                <EmptyState
                    compact
                    icon={Users}
                    title="No follower data yet."
                    description="Connect your accounts and your follower counts and growth will appear here."
                />
            </Panel>
        );
    }

    return (
        <Panel icon={Users} eyebrow="Audience" title="Followers">
            <ul className="flex flex-col gap-3">
                {followers.accounts.map((account) => {
                    const Icon = platformIcon(account.platform);

                    return (
                        <li
                            key={account.social_account_id}
                            className="flex items-center gap-3"
                        >
                            <Icon className="text-muted-foreground size-4 shrink-0" />
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm">
                                    @{account.handle}
                                </p>
                            </div>
                            <span className="font-display font-bold tabular-nums">
                                {fmt(account.followers)}
                            </span>
                            {account.growth !== null &&
                                account.growth !== 0 && (
                                    <span
                                        className={cn(
                                            'text-[12px] font-medium tabular-nums',
                                            account.growth > 0
                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {account.growth > 0 ? '+' : ''}
                                        {fmt(account.growth)}
                                    </span>
                                )}
                        </li>
                    );
                })}
            </ul>
        </Panel>
    );
}

function TopPostsPanel({ posts }: { posts: TopPost[] }) {
    return (
        <Panel icon={Trophy} eyebrow="What worked" title="Top posts">
            {posts.length === 0 ? (
                <EmptyState
                    compact
                    icon={Trophy}
                    title="No results yet."
                    description="Once your posts start getting engagement, your best performers will be ranked here."
                />
            ) : (
                <ol className="divide-border/70 flex flex-col divide-y">
                    {posts.map((post, index) => (
                        <li
                            key={post.post_id}
                            className="flex items-center gap-4 py-3 first:pt-0 last:pb-0"
                        >
                            <span className="font-display text-muted-foreground w-5 shrink-0 text-center text-lg font-bold tabular-nums">
                                {index + 1}
                            </span>
                            {post.thumbnail_url ? (
                                <img
                                    src={post.thumbnail_url}
                                    alt=""
                                    className="size-12 shrink-0 rounded-lg object-cover"
                                />
                            ) : (
                                <span className="bg-muted size-12 shrink-0 rounded-lg" />
                            )}
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm">
                                    {post.caption ?? 'Untitled post'}
                                </p>
                                <div className="mt-1 flex items-center gap-1.5">
                                    {post.platforms.map((platform) => {
                                        const Icon = platformIcon(platform);
                                        return (
                                            <Icon
                                                key={platform}
                                                className="text-muted-foreground size-3.5"
                                            />
                                        );
                                    })}
                                </div>
                            </div>
                            <div className="text-right">
                                <p className="font-display font-bold tabular-nums">
                                    {fmt(post.engagement)}
                                </p>
                                <p className="text-muted-foreground text-[11px]">
                                    interactions
                                </p>
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </Panel>
    );
}

function BestTimesPanel({ bestTimes }: { bestTimes: BestTime[] }) {
    return (
        <Panel icon={Clock} eyebrow="When to post" title="Your best times">
            {bestTimes.length === 0 ? (
                <EmptyState
                    compact
                    icon={Clock}
                    title="Connect an account."
                    description="Once you've connected an account, we'll suggest the best time to post on it."
                />
            ) : (
                <ul className="grid gap-3 sm:grid-cols-2">
                    {bestTimes.map((best) => {
                        const Icon = platformIcon(best.platform);

                        return (
                            <li
                                key={best.social_account_id}
                                className="border-border/70 flex items-center gap-3 rounded-xl border p-3"
                            >
                                <Icon className="text-muted-foreground size-5 shrink-0" />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-[13px]">
                                        @{best.handle}
                                    </p>
                                    <p className="font-display text-lg font-bold">
                                        {best.slot.label}
                                    </p>
                                </div>
                                <Badge
                                    variant={
                                        best.source === 'data'
                                            ? 'default'
                                            : 'secondary'
                                    }
                                >
                                    {best.source === 'data'
                                        ? 'From your posts'
                                        : 'Starting point'}
                                </Badge>
                            </li>
                        );
                    })}
                </ul>
            )}
            <p className="text-muted-foreground mt-4 flex items-center gap-1.5 text-[12px]">
                <TrendingUp className="size-3.5" />
                Times get sharper as you publish more and we learn what works
                for you.
            </p>
        </Panel>
    );
}

function growthCaption(growth: number | null): string {
    if (growth === null || growth === 0) {
        return 'total followers';
    }

    return `${growth > 0 ? '+' : ''}${nf.format(growth)} this period`;
}

function InsightsSkeleton() {
    return (
        <div className="flex flex-col gap-5">
            <Skeleton className="h-28 rounded-2xl" />
            <Skeleton className="h-56 rounded-2xl" />
            <div className="grid gap-5 lg:grid-cols-2">
                <Skeleton className="h-64 rounded-2xl" />
                <Skeleton className="h-64 rounded-2xl" />
            </div>
        </div>
    );
}

BusinessInsights.layout = {
    breadcrumbs: [{ title: 'Insights', href: insightsIndex() }],
};
