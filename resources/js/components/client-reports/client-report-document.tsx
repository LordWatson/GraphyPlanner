import type { InertiaLinkProps } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    CheckCheck,
    Heart,
    Medal,
    Megaphone,
    Share2,
    UsersRound,
} from 'lucide-react';
import {
    reported,
    signed,
    TopPostsList,
} from '@/components/analytics/engagement-section';
import { FigureList, ShareBars } from '@/components/analytics/figures';
import { formatHours, formatPercent, pad } from '@/components/analytics/format';
import { CountBarChart } from '@/components/analytics/series-charts';
import type {
    EngagementSection,
    EngagementTopPost,
} from '@/components/analytics/types';
import { EmptyState, Panel, StatStrip } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { Badge } from '@/components/ui/badge';
import { formatDate } from '@/lib/dates';

/** `BuildClientReportSnapshotAction` — client-safe by construction (no billing). */
export type ClientReportSnapshot = {
    period: { start: string; end: string; label: string; timezone: string };
    client: { name: string };
    studio: { name: string };
    posts: {
        published: number;
        channels: number;
        by_platform: { platform: string; label: string; published: number }[];
        waiting_on_client: number;
    };
    engagement: {
        measured_channels: number;
        totals: EngagementSection['totals'];
        engagement_rate: number | null;
        by_platform: Omit<
            EngagementSection['by_platform'][number],
            'impressions'
        >[];
        followers: {
            current: number | null;
            growth: number | null;
            accounts: Omit<
                EngagementSection['followers']['accounts'][number],
                'client_id'
            >[];
        };
        series: EngagementSection['series'];
    };
    top_posts: Omit<
        EngagementTopPost,
        'client_id' | 'client_name' | 'thumbnail_url' | 'views'
    >[];
    approvals: {
        sent_to_client: number;
        approved: number;
        changes_requested: number;
        first_time_approval_rate: number | null;
        median_hours: number | null;
        decisions: number;
    };
    campaigns: {
        campaign_id: number;
        name: string;
        shared_at: string | null;
        summary: string | null;
    }[];
    generated_at: string;
};

/** `ClientReportPresenter::row()`. */
export type ClientReportRow = {
    id: number;
    period_start: string;
    period_label: string;
    status: 'draft' | 'published';
    status_label: string;
    published_at: string | null;
    sent_at: string | null;
    recipients_count: number;
    posts_published: number | null;
    engagement: number | null;
    follower_growth: number | null;
};

/** `ClientReportPresenter::document()`. */
export type ClientReportDocumentData = ClientReportRow & {
    commentary: string | null;
    snapshot_at: string | null;
    snapshot: ClientReportSnapshot | null;
    thumbnails: Record<string, string | null>;
};

type Href = NonNullable<InertiaLinkProps['href']>;

/**
 * Step M-3.3 — the monthly report itself, exactly as the client sees it (the studio's page shows
 * the same document above its commentary editor). Expressive enough for a client-facing page —
 * the spec row, the studio's note as an Ink block — but no patterns or peeks: it's data. Status
 * is never colour alone; unreported figures are dashes, never zeros.
 */
export function ClientReportDocument({
    report,
    campaignHref,
    waitingHref,
    postLinks = false,
}: {
    report: ClientReportDocumentData;
    /** Where a shared wrap report opens (portal or studio campaign page). */
    campaignHref?: (campaignId: number) => Href;
    /** Portal only: where "waiting on you" goes. */
    waitingHref?: Href;
    /** Studio only: captions open the post editor. */
    postLinks?: boolean;
}) {
    const snapshot = report.snapshot;

    if (!snapshot) {
        return (
            <EmptyState
                icon={Heart}
                title="No figures yet."
                description="Refresh the figures to build this month's report."
            />
        );
    }

    const { posts, engagement, approvals } = snapshot;
    const hasEngagement = engagement.series.some(
        (bucket) => bucket.engagement > 0,
    );
    const topPosts: EngagementTopPost[] = snapshot.top_posts.map((post) => ({
        ...post,
        views: null,
        client_id: null,
        client_name: null,
        thumbnail_url: report.thumbnails[String(post.post_id)] ?? null,
    }));

    return (
        <div className="flex flex-col gap-8">
            <StatStrip
                items={[
                    {
                        label: 'Posts published',
                        value: pad(posts.published),
                        caption: `${posts.channels} across channels`,
                    },
                    {
                        label: 'Reach',
                        value: reported(engagement.totals.reach),
                        caption: 'People reached',
                    },
                    {
                        label: 'Interactions',
                        value: reported(engagement.totals.engagement),
                        caption:
                            engagement.engagement_rate === null
                                ? 'Likes, comments, shares, saves'
                                : `${formatPercent(engagement.engagement_rate)} engagement rate`,
                    },
                    {
                        label: 'Followers',
                        value: reported(engagement.followers.current),
                        caption: `${signed(engagement.followers.growth)} this month`,
                    },
                ]}
            />

            {report.commentary && (
                <section
                    aria-label={`A note from ${snapshot.studio.name}`}
                    className="bg-ink text-cream flex flex-col gap-3 rounded-2xl p-6 sm:p-8"
                >
                    <span className="eyebrow text-cream/70">
                        A note from {snapshot.studio.name}
                    </span>
                    <p className="text-[15px] leading-relaxed text-pretty whitespace-pre-line sm:text-base">
                        {report.commentary}
                    </p>
                </section>
            )}

            {posts.waiting_on_client > 0 && waitingHref && (
                <Link
                    href={waitingHref}
                    className="border-info/30 bg-info/10 hover:bg-info/15 flex items-center justify-between gap-3 rounded-2xl border px-5 py-4 text-sm transition-colors"
                >
                    <span>
                        <strong className="font-semibold">
                            {posts.waiting_on_client}{' '}
                            {posts.waiting_on_client === 1
                                ? 'post is'
                                : 'posts are'}
                        </strong>{' '}
                        waiting for your approval.
                    </span>
                    <ArrowRight className="size-4 shrink-0" aria-hidden />
                </Link>
            )}

            <div className="grid gap-5 lg:grid-cols-[1.45fr_1fr]">
                <Panel
                    eyebrow={`${snapshot.period.label} · per day`}
                    title="Engagement"
                    icon={Heart}
                    description="Likes, comments, shares and saves, by the day each post went out."
                >
                    {hasEngagement ? (
                        <CountBarChart
                            series={engagement.series}
                            daily
                            dataKey="engagement"
                            name="Interactions"
                        />
                    ) : (
                        <EmptyState
                            compact
                            className="border-0"
                            icon={Heart}
                            title="No readings for this month."
                            description="The platforms hadn't reported engagement for these posts yet."
                        />
                    )}
                </Panel>

                <Panel eyebrow="This month" title="By platform" icon={Share2}>
                    {engagement.by_platform.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            Nothing went out this month.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {engagement.by_platform.map((row) => {
                                const Icon = platformIcon(row.platform);

                                return (
                                    <li
                                        key={row.platform}
                                        className="flex items-center justify-between gap-3 text-sm"
                                    >
                                        <span className="flex min-w-0 items-center gap-2">
                                            <Icon
                                                className="text-muted-foreground size-4 shrink-0"
                                                aria-hidden
                                            />
                                            <span className="truncate">
                                                {row.label}
                                            </span>
                                        </span>
                                        {row.available ? (
                                            <span className="text-muted-foreground shrink-0 font-mono text-xs tabular-nums">
                                                {row.published} posts ·{' '}
                                                {reported(row.engagement)}{' '}
                                                interactions
                                            </span>
                                        ) : (
                                            <Badge variant="outline">
                                                {row.published} posts ·
                                                engagement not available
                                            </Badge>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Panel>
            </div>

            <Panel
                eyebrow="By interactions"
                title="Top posts"
                icon={Medal}
                bodyClassName="p-0 sm:p-0"
            >
                {topPosts.length === 0 ? (
                    <div className="p-5 sm:p-6">
                        <EmptyState
                            compact
                            icon={Medal}
                            title="Nothing to rank yet."
                            description="Posts rank here once the platforms report their engagement."
                        />
                    </div>
                ) : (
                    <TopPostsList
                        posts={topPosts}
                        links={postLinks}
                        showClient={false}
                    />
                )}
            </Panel>

            <div className="grid gap-5 lg:grid-cols-2">
                <Panel eyebrow="This month" title="Audience" icon={UsersRound}>
                    {engagement.followers.accounts.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No follower counts reported yet.
                        </p>
                    ) : (
                        <ShareBars
                            rows={engagement.followers.accounts.map(
                                (account) => ({
                                    key: String(account.social_account_id),
                                    label: `${account.handle} · ${account.platform_label}`,
                                    value: account.followers,
                                    hint: `${reported(account.followers)} · ${signed(account.growth)}`,
                                    barClassName: 'bg-chart-2',
                                }),
                            )}
                        />
                    )}
                </Panel>

                <Panel eyebrow="This month" title="Approvals" icon={CheckCheck}>
                    <FigureList
                        rows={[
                            ['Sent to you', String(approvals.sent_to_client)],
                            ['Approved', String(approvals.approved)],
                            [
                                'Changes requested',
                                String(approvals.changes_requested),
                            ],
                            [
                                'Approved first time',
                                formatPercent(
                                    approvals.first_time_approval_rate,
                                ),
                            ],
                            [
                                'Your median decision time',
                                formatHours(approvals.median_hours),
                            ],
                        ]}
                    />
                </Panel>
            </div>

            {snapshot.campaigns.length > 0 && (
                <Panel
                    eyebrow="Shared this month"
                    title="Campaign wrap reports"
                    icon={Megaphone}
                >
                    <ul className="divide-border/70 flex flex-col divide-y">
                        {snapshot.campaigns.map((campaign) => (
                            <li
                                key={campaign.campaign_id}
                                className="flex flex-col gap-1 py-3 first:pt-0 last:pb-0"
                            >
                                <span className="flex flex-wrap items-baseline justify-between gap-2">
                                    {campaignHref ? (
                                        <Link
                                            href={campaignHref(
                                                campaign.campaign_id,
                                            )}
                                            className="hover:text-primary text-sm font-semibold"
                                        >
                                            {campaign.name}
                                        </Link>
                                    ) : (
                                        <span className="text-sm font-semibold">
                                            {campaign.name}
                                        </span>
                                    )}
                                    <span className="text-muted-foreground font-mono text-[11px]">
                                        {formatDate(campaign.shared_at)}
                                    </span>
                                </span>
                                {campaign.summary && (
                                    <p className="text-muted-foreground text-sm text-pretty">
                                        {campaign.summary}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}

            <p className="text-muted-foreground text-xs">
                Figures for {snapshot.period.label} ({snapshot.period.timezone}
                ), as the platforms reported them on{' '}
                {formatDate(report.snapshot_at ?? snapshot.generated_at)}. A
                dash means a platform didn’t report that figure.
            </p>
        </div>
    );
}
