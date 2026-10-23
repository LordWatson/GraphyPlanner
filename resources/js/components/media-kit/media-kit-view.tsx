import { Globe, Heart, MapPin, Trophy, Users } from 'lucide-react';
import { EmptyState, Panel, StatStrip } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';

export type MediaKitData = {
    brand: {
        name: string;
        kind: string | null;
        bio: string | null;
        location: string | null;
        website: string | null;
    };
    audience: {
        total_followers: number | null;
        accounts: {
            platform: string;
            platform_label: string;
            handle: string;
            followers: number;
        }[];
    };
    engagement: {
        period_days: number;
        measured_posts: number;
        total_reach: number | null;
        total_interactions: number | null;
        engagement_rate: number | null;
    };
    top_posts: {
        caption: string | null;
        thumbnail_url: string | null;
        platforms: string[];
        post_url: string | null;
        engagement: number | null;
        reach: number | null;
    }[];
};

const nf = new Intl.NumberFormat();
const fmt = (value: number | null | undefined): string =>
    value === null || value === undefined ? '—' : nf.format(value);

/**
 * Step B-8.4 — the media kit body, shared by the owner's preview and the public share page so the
 * two can never drift. Renders only the already-public kit data (audience, engagement, top posts).
 */
export function MediaKitView({ kit }: { kit: MediaKitData }) {
    const { brand, audience, engagement, top_posts } = kit;

    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-col gap-2">
                {brand.kind && (
                    <span className="eyebrow text-muted-foreground">
                        {brand.kind}
                    </span>
                )}
                <h1 className="font-display text-[2.25rem] leading-none font-bold tracking-[-0.03em]">
                    {brand.name}
                </h1>
                {(brand.location || brand.website) && (
                    <div className="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                        {brand.location && (
                            <span className="inline-flex items-center gap-1.5">
                                <MapPin className="size-3.5" />
                                {brand.location}
                            </span>
                        )}
                        {brand.website && (
                            <span className="inline-flex items-center gap-1.5">
                                <Globe className="size-3.5" />
                                {brand.website}
                            </span>
                        )}
                    </div>
                )}
                {brand.bio && (
                    <p className="text-foreground/90 mt-2 max-w-2xl text-[15px] leading-relaxed text-pretty">
                        {brand.bio}
                    </p>
                )}
            </div>

            <StatStrip
                items={[
                    {
                        label: 'Total followers',
                        value: fmt(audience.total_followers),
                        caption: 'across platforms',
                    },
                    {
                        label: 'Engagement rate',
                        value:
                            engagement.engagement_rate === null
                                ? '—'
                                : `${engagement.engagement_rate}%`,
                        caption: 'average',
                    },
                    {
                        label: `Reach · ${engagement.period_days}d`,
                        value: fmt(engagement.total_reach),
                        caption: 'people reached',
                    },
                ]}
            />

            <div className="grid gap-5 lg:grid-cols-2">
                <Panel icon={Users} eyebrow="Audience" title="By platform">
                    {audience.accounts.length === 0 ? (
                        <EmptyState
                            compact
                            icon={Users}
                            title="No audience data yet."
                            description="Follower counts appear once accounts are connected."
                        />
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {audience.accounts.map((account) => {
                                const Icon = platformIcon(account.platform);

                                return (
                                    <li
                                        key={`${account.platform}-${account.handle}`}
                                        className="flex items-center gap-3"
                                    >
                                        <Icon className="text-muted-foreground size-5 shrink-0" />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">
                                                {account.platform_label}
                                            </p>
                                            <p className="text-muted-foreground truncate text-[12px]">
                                                @{account.handle}
                                            </p>
                                        </div>
                                        <span className="font-display font-bold tabular-nums">
                                            {fmt(account.followers)}
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Panel>

                <Panel icon={Heart} eyebrow="Engagement" title="Highlights">
                    <ul className="flex flex-col gap-4">
                        <li className="flex items-baseline justify-between gap-3">
                            <span className="text-muted-foreground text-sm">
                                Measured posts
                            </span>
                            <span className="font-display font-bold tabular-nums">
                                {fmt(engagement.measured_posts)}
                            </span>
                        </li>
                        <li className="flex items-baseline justify-between gap-3">
                            <span className="text-muted-foreground text-sm">
                                Total interactions
                            </span>
                            <span className="font-display font-bold tabular-nums">
                                {fmt(engagement.total_interactions)}
                            </span>
                        </li>
                        <li className="flex items-baseline justify-between gap-3">
                            <span className="text-muted-foreground text-sm">
                                Total reach
                            </span>
                            <span className="font-display font-bold tabular-nums">
                                {fmt(engagement.total_reach)}
                            </span>
                        </li>
                    </ul>
                </Panel>
            </div>

            <Panel icon={Trophy} eyebrow="What worked" title="Top posts">
                {top_posts.length === 0 ? (
                    <EmptyState
                        compact
                        icon={Trophy}
                        title="No results yet."
                        description="Top-performing posts appear here once they've picked up engagement."
                    />
                ) : (
                    <ol className="divide-border/70 flex flex-col divide-y">
                        {top_posts.map((post, index) => (
                            <li
                                key={index}
                                className="flex items-center gap-4 py-3 first:pt-0 last:pb-0"
                            >
                                {post.thumbnail_url ? (
                                    <img
                                        src={post.thumbnail_url}
                                        alt=""
                                        className="size-14 shrink-0 rounded-lg object-cover"
                                    />
                                ) : (
                                    <span className="bg-muted size-14 shrink-0 rounded-lg" />
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="line-clamp-2 text-sm">
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
        </div>
    );
}
