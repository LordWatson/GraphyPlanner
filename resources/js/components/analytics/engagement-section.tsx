import { Link } from '@inertiajs/react';
import {
    CalendarClock,
    ExternalLink,
    Heart,
    Medal,
    Share2,
    UsersRound,
} from 'lucide-react';
import { EmptyState, Panel } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { Badge } from '@/components/ui/badge';
import { formatDate, formatDateTime } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { edit as editPost } from '@/routes/posts';
import { FigureList, ShareBars } from './figures';
import { formatPercent, integer } from './format';
import { CountBarChart, TotalLineChart } from './series-charts';
import type {
    AccountBestTime,
    EngagementSection,
    EngagementTopPost,
} from './types';

const compact = new Intl.NumberFormat('en-GB', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

/** A count the platform may not have reported: a dash, never a zero. */
export function reported(value: number | null | undefined): string {
    return value === null || value === undefined ? '—' : integer.format(value);
}

export function signed(value: number | null): string {
    if (value === null) {
        return '—';
    }

    return `${value > 0 ? '+' : value < 0 ? '−' : ''}${integer.format(Math.abs(value))}`;
}

/**
 * Step M-3.1 — the engagement panels of an analytics view: interactions over time with the
 * headline figures, the per-platform split (platforms with no metrics source say so in words),
 * follower growth per account, and the top posts. Data screen: hairlines, chart tokens, no
 * graphics. Unreported numbers are dashes, never zeros.
 */
export function EngagementPanels({
    engagement,
    daily,
    links = true,
}: {
    engagement: EngagementSection;
    daily: boolean;
    /** Off where studio pages can't be opened (super admin, client portal). */
    links?: boolean;
}) {
    const per = daily ? 'Per day' : 'Per month';
    const hasEngagement = engagement.series.some(
        (bucket) => bucket.engagement > 0,
    );
    const hasFollowers = engagement.series.some(
        (bucket) => bucket.followers !== null,
    );
    const synced = engagement.last_synced_at
        ? `Last read from the platforms ${formatDateTime(engagement.last_synced_at)}.`
        : 'Readings arrive the day after a post goes live.';

    return (
        <>
            <div className="grid gap-5 lg:grid-cols-[1.45fr_1fr]">
                <Panel
                    eyebrow={`${per} · by publish date`}
                    title="Engagement"
                    icon={Heart}
                    description={synced}
                >
                    {hasEngagement ? (
                        <CountBarChart
                            series={engagement.series}
                            daily={daily}
                            dataKey="engagement"
                            name="Interactions"
                        />
                    ) : (
                        <EmptyState
                            compact
                            className="border-0"
                            icon={Heart}
                            title="No readings yet."
                            description="Likes, comments, shares and saves chart here once the platforms report them."
                        />
                    )}
                    <FigureList
                        className="border-border/70 mt-5 border-t pt-4"
                        rows={[
                            [
                                'Interactions',
                                reported(engagement.totals.engagement),
                            ],
                            ['Reach', reported(engagement.totals.reach)],
                            [
                                'Impressions',
                                reported(engagement.totals.impressions),
                            ],
                            [
                                'Engagement rate',
                                formatPercent(engagement.engagement_rate),
                            ],
                            [
                                'Channels measured',
                                `${integer.format(engagement.measured_channels)} of ${integer.format(engagement.published_channels)}`,
                            ],
                        ]}
                    />
                </Panel>

                <Panel
                    eyebrow="Published in range"
                    title="Engagement by platform"
                    icon={Share2}
                >
                    {engagement.by_platform.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No accounts on these platforms yet.
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
                                                {reported(row.engagement)} ·{' '}
                                                {formatPercent(
                                                    row.engagement_rate,
                                                )}{' '}
                                                · {row.measured}/{row.published}{' '}
                                                posts
                                            </span>
                                        ) : (
                                            <Badge
                                                variant="outline"
                                                title={row.note ?? undefined}
                                            >
                                                Not available for {row.label}
                                            </Badge>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Panel>
            </div>

            <div className="grid gap-5 lg:grid-cols-[1fr_1.45fr]">
                <Panel
                    eyebrow={per}
                    title="Followers"
                    icon={UsersRound}
                    description="Account audiences, as each network reports them."
                >
                    {engagement.followers.accounts.length === 0 ? (
                        <EmptyState
                            compact
                            className="border-0"
                            icon={UsersRound}
                            title="No follower counts yet."
                            description="Connected accounts report their followers once a day."
                        />
                    ) : (
                        <div className="flex flex-col gap-5">
                            <div className="flex items-baseline justify-between gap-3">
                                <span className="font-display text-3xl font-bold tabular-nums">
                                    {reported(engagement.followers.current)}
                                </span>
                                <span
                                    className={cn(
                                        'font-mono text-sm tabular-nums',
                                        (engagement.followers.growth ?? 0) < 0
                                            ? 'text-destructive'
                                            : 'text-success',
                                    )}
                                >
                                    {signed(engagement.followers.growth)} in
                                    range
                                </span>
                            </div>
                            {hasFollowers && (
                                <TotalLineChart
                                    className="h-44"
                                    series={engagement.series}
                                    daily={daily}
                                    dataKey="followers"
                                    name="Followers"
                                />
                            )}
                            <ShareBars
                                rows={engagement.followers.accounts.map(
                                    (account) => ({
                                        key: String(account.social_account_id),
                                        label: `${account.handle} · ${account.platform_label}`,
                                        value: account.followers,
                                        hint: `${compact.format(account.followers)} · ${signed(account.growth)}`,
                                        barClassName: 'bg-chart-2',
                                    }),
                                )}
                            />
                        </div>
                    )}
                </Panel>

                <Panel
                    eyebrow="By interactions"
                    title="Top posts"
                    icon={Medal}
                    bodyClassName="p-0 sm:p-0"
                >
                    {engagement.top_posts.length === 0 ? (
                        <div className="p-5 sm:p-6">
                            <EmptyState
                                compact
                                icon={Medal}
                                title="Nothing to rank yet."
                                description="Posts rank here once their first readings arrive."
                            />
                        </div>
                    ) : (
                        <TopPostsList
                            posts={engagement.top_posts}
                            links={links}
                        />
                    )}
                </Panel>
            </div>

            {engagement.best_times && engagement.best_times.length > 0 && (
                <BestTimesPanel accounts={engagement.best_times} />
            )}
        </>
    );
}

/**
 * Step M-3.2 — each account's best slots on its own clock, from its own engagement. Accounts
 * still learning say how many measured posts they need.
 */
export function BestTimesPanel({ accounts }: { accounts: AccountBestTime[] }) {
    return (
        <Panel
            eyebrow="Last 6 months · account time"
            title="Best time to post"
            icon={CalendarClock}
            description="Ranked by engagement rate on each account's own posts. The editor offers these when you schedule."
        >
            <ul className="divide-border/70 flex flex-col divide-y">
                {accounts.map((account) => {
                    const Icon = platformIcon(account.platform);

                    return (
                        <li
                            key={account.social_account_id}
                            className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <span className="flex min-w-0 items-center gap-2 text-sm">
                                <Icon
                                    className="text-muted-foreground size-4 shrink-0"
                                    aria-hidden
                                />
                                <span className="truncate font-medium">
                                    {account.handle}
                                </span>
                                <span className="text-muted-foreground truncate text-xs">
                                    {account.platform_label} ·{' '}
                                    {account.timezone}
                                </span>
                            </span>
                            {account.status === 'ready' &&
                            account.slots.length > 0 ? (
                                <span className="flex flex-wrap gap-1.5">
                                    {account.slots.map((slot) => (
                                        <Badge
                                            key={`${slot.weekday}-${slot.hour}`}
                                            variant="default"
                                            className="font-mono"
                                            title={`${slot.posts} posts · ${
                                                account.ranked_by === 'rate'
                                                    ? `${slot.score}% average engagement rate`
                                                    : `${slot.score} average interactions`
                                            }`}
                                        >
                                            {slot.label}
                                        </Badge>
                                    ))}
                                </span>
                            ) : (
                                <span className="text-muted-foreground text-xs">
                                    Learning — {account.sample} of{' '}
                                    {account.needed} measured posts
                                </span>
                            )}
                        </li>
                    );
                })}
            </ul>
        </Panel>
    );
}

export function TopPostsList({
    posts,
    links = true,
    showClient = true,
}: {
    posts: EngagementTopPost[];
    links?: boolean;
    showClient?: boolean;
}) {
    return (
        <ol className="divide-border/70 flex flex-col divide-y">
            {posts.map((post, index) => {
                const caption = post.caption ?? 'Untitled post';

                return (
                    <li
                        key={post.post_id}
                        className="flex items-start gap-3 px-5 py-4 sm:px-6"
                    >
                        <span className="text-muted-foreground w-5 shrink-0 pt-0.5 font-mono text-[11px] tabular-nums">
                            {String(index + 1).padStart(2, '0')}
                        </span>
                        {post.thumbnail_url ? (
                            <img
                                src={post.thumbnail_url}
                                alt=""
                                className="bg-muted size-12 shrink-0 rounded-lg object-cover"
                                loading="lazy"
                            />
                        ) : (
                            <span
                                aria-hidden
                                className="bg-muted size-12 shrink-0 rounded-lg"
                            />
                        )}
                        <div className="flex min-w-0 flex-1 flex-col gap-1">
                            {links ? (
                                <Link
                                    href={editPost(post.post_id)}
                                    className="hover:text-primary line-clamp-2 text-sm"
                                >
                                    {caption}
                                </Link>
                            ) : (
                                <span className="line-clamp-2 text-sm">
                                    {caption}
                                </span>
                            )}
                            <span className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                <span className="inline-flex items-center gap-1">
                                    {post.platforms.map((platform) => {
                                        const Icon = platformIcon(platform);

                                        return (
                                            <Icon
                                                key={platform}
                                                className="size-3.5"
                                                aria-label={platform}
                                            />
                                        );
                                    })}
                                </span>
                                {showClient && post.client_name && (
                                    <span>{post.client_name}</span>
                                )}
                                <span>{formatDate(post.published_at)}</span>
                                {post.post_url && (
                                    <a
                                        href={post.post_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="hover:text-primary inline-flex items-center gap-1"
                                    >
                                        View live
                                        <ExternalLink
                                            className="size-3"
                                            aria-hidden
                                        />
                                    </a>
                                )}
                            </span>
                        </div>
                        <div className="flex shrink-0 flex-col items-end gap-0.5">
                            <span className="font-display text-lg font-bold tabular-nums">
                                {reported(post.engagement)}
                            </span>
                            <span className="text-muted-foreground font-mono text-[11px] tabular-nums">
                                {post.engagement_rate === null
                                    ? `${reported(post.reach ?? post.impressions)} reach`
                                    : `${formatPercent(post.engagement_rate)} rate`}
                            </span>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
