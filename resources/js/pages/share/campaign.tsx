import { Head } from '@inertiajs/react';
import { ImageOff } from 'lucide-react';
import { AssetPreview } from '@/components/asset-preview';
import type { AssetPreviewData } from '@/components/asset-preview';
import { CampaignChip } from '@/components/campaigns/campaign-chip';
import { EmptyState } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { PublicShell, PublicTitle } from '@/components/public-shell';
import { StatusPill } from '@/components/status-pill';
import { formatDate, formatDateRange, formatSchedule } from '@/lib/dates';

type SharedPost = {
    id: number;
    status: string;
    status_label: string;
    master_caption: string | null;
    targets: { platform: string | null; handle: string | null }[];
    scheduled_local_date: string | null;
    scheduled_local_time: string | null;
    assets: AssetPreviewData[];
};

/**
 * Campaigns enhancement C-5.4 — the public, read-only campaign preview: what's approved and
 * going out, as a grid. Nothing to decide, nothing to comment on, no internal detail.
 */
export default function SharedCampaign({
    campaign,
    client,
    agency,
    expires_at,
    posts,
}: {
    campaign: {
        id: number;
        name: string;
        code: string | null;
        colour: string | null;
        start_date: string | null;
        end_date: string | null;
        goal: string | null;
        timeline: { sentence: string };
    };
    client: { name: string | null };
    agency: { name: string | null };
    expires_at: string;
    posts: SharedPost[];
}) {
    return (
        <>
            <Head title={campaign.name}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <PublicShell width="xl" context={client.name ?? undefined}>
                <PublicTitle
                    kicker={
                        agency.name
                            ? `Shared by ${agency.name}`
                            : 'Campaign preview'
                    }
                    title={campaign.name}
                >
                    <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        <CampaignChip campaign={campaign} compact />
                        <span className="font-mono">
                            {formatDateRange(
                                campaign.start_date,
                                campaign.end_date,
                            )}
                        </span>
                        <span>{campaign.timeline.sentence}</span>
                    </div>
                </PublicTitle>

                {campaign.goal && (
                    <p className="text-muted-foreground max-w-2xl text-pretty">
                        {campaign.goal}
                    </p>
                )}

                {posts.length === 0 ? (
                    <EmptyState
                        title="Nothing approved yet."
                        description="Posts appear here once they’re approved."
                    />
                ) : (
                    <ul className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {posts.map((post) => (
                            <li
                                key={post.id}
                                className="border-border/70 bg-card flex flex-col overflow-hidden rounded-2xl border"
                            >
                                <div className="bg-muted/30">
                                    {post.assets[0] ? (
                                        <AssetPreview asset={post.assets[0]} />
                                    ) : (
                                        <div className="text-muted-foreground flex aspect-square items-center justify-center">
                                            <ImageOff className="size-6" />
                                            <span className="sr-only">
                                                No media
                                            </span>
                                        </div>
                                    )}
                                </div>
                                <div className="flex flex-1 flex-col gap-3 p-4">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="flex items-center gap-1.5">
                                            {post.targets.map(
                                                (target, index) => {
                                                    const Icon = platformIcon(
                                                        target.platform,
                                                    );

                                                    return (
                                                        <Icon
                                                            key={index}
                                                            className="text-muted-foreground size-4"
                                                            aria-label={
                                                                target.platform ??
                                                                'Channel'
                                                            }
                                                        />
                                                    );
                                                },
                                            )}
                                        </span>
                                        <StatusPill
                                            kind="post"
                                            value={post.status}
                                            label={post.status_label}
                                        />
                                    </div>
                                    {post.master_caption && (
                                        <p className="line-clamp-4 text-sm whitespace-pre-line">
                                            {post.master_caption}
                                        </p>
                                    )}
                                    <span className="text-muted-foreground mt-auto font-mono text-xs">
                                        {formatSchedule(
                                            post.scheduled_local_date,
                                            post.scheduled_local_time,
                                        )}
                                    </span>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                <p className="text-muted-foreground text-xs">
                    This preview link works until {formatDate(expires_at)}.
                </p>
            </PublicShell>
        </>
    );
}
