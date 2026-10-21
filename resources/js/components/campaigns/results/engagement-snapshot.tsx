import { ExternalLink } from 'lucide-react';
import { reported, signed } from '@/components/analytics/engagement-section';
import { formatPercent } from '@/components/analytics/format';
import { platformIcon } from '@/components/platform-icon';
import { formatDate } from '@/lib/dates';

/** Step M-3.1 — `CampaignKpiService::engagementSnapshot()`, frozen when a wrap report is shared. */
export type EngagementSnapshot = {
    captured_at: string;
    measured_channels: number;
    totals: {
        reach: number | null;
        impressions: number | null;
        engagement: number | null;
    };
    engagement_rate: number | null;
    followers: { current: number | null; growth: number | null };
    top_posts: {
        post_id: number;
        caption: string | null;
        platforms: string[];
        published_at: string;
        post_url: string | null;
        engagement: number | null;
        reach: number | null;
        impressions: number | null;
        engagement_rate: number | null;
    }[];
};

/**
 * The engagement a shared wrap report froze: four figures and the top posts. Dashes for anything
 * a platform didn't report. Used by the portal's campaign page and the studio's wrap panel.
 */
export function EngagementSnapshotFigures({
    snapshot,
}: {
    snapshot: EngagementSnapshot;
}) {
    const figures: [string, string][] = [
        [
            'Reach',
            reported(snapshot.totals.reach ?? snapshot.totals.impressions),
        ],
        ['Interactions', reported(snapshot.totals.engagement)],
        ['Engagement rate', formatPercent(snapshot.engagement_rate)],
        ['Follower growth', signed(snapshot.followers.growth)],
    ];

    return (
        <div className="flex flex-col gap-4">
            <span className="eyebrow text-muted-foreground">
                Engagement · as of {formatDate(snapshot.captured_at)}
            </span>
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                {figures.map(([label, value]) => (
                    <div
                        key={label}
                        className="border-border flex flex-col gap-1 rounded-xl border p-3"
                    >
                        <dt className="eyebrow text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="font-display text-2xl font-bold tabular-nums">
                            {value}
                        </dd>
                    </div>
                ))}
            </dl>
            {snapshot.top_posts.length > 0 && (
                <ol className="divide-border/70 flex flex-col divide-y">
                    {snapshot.top_posts.map((post, index) => (
                        <li
                            key={post.post_id}
                            className="flex items-start gap-3 py-2.5 first:pt-0"
                        >
                            <span className="text-muted-foreground w-5 shrink-0 pt-0.5 font-mono text-[11px] tabular-nums">
                                {String(index + 1).padStart(2, '0')}
                            </span>
                            <div className="flex min-w-0 flex-1 flex-col gap-1">
                                <span className="line-clamp-2 text-sm">
                                    {post.caption ?? 'Untitled post'}
                                </span>
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
                            <span className="shrink-0 font-mono text-sm tabular-nums">
                                {reported(post.engagement)}
                            </span>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
