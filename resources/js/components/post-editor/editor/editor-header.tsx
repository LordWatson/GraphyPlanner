import { Link } from '@inertiajs/react';
import { ArrowLeft, Grid3x3 } from 'lucide-react';
import { CampaignChip } from '@/components/campaigns/campaign-chip';
import { PageHeader } from '@/components/page';
import type { PostData } from '@/components/post-editor/types';
import { StatusPill } from '@/components/status-pill';
import { ordinal } from '@/lib/grid-layout';
import { show as showCampaign } from '@/routes/campaigns';
import { show as showClient } from '@/routes/clients';
import { edit as editGrid } from '@/routes/grids';

export type EditorGridLink = {
    id: number;
    name: string;
    status: string;
    tile_id: number;
    tile_position: number;
    sequence: number | null;
    tile_count: number;
};

/** Back to the client, then the post's header: status, campaign and (Step 18.9) its grid tile. */
export function EditorHeader({
    post,
    client,
    grid,
}: {
    post: PostData;
    client: { id: number; name: string };
    grid: EditorGridLink | null;
}) {
    return (
        <>
            <div className="-mb-4">
                <Link
                    href={showClient(client.id)}
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm transition-colors"
                >
                    <ArrowLeft className="size-4" />
                    {client.name}
                </Link>
            </div>

            <PageHeader
                eyebrow={`Post ${post.id}`}
                title={
                    <>
                        {client.name} <em>post</em>
                    </>
                }
                meta={
                    <>
                        <StatusPill
                            kind="post"
                            value={post.status}
                            label={post.status_label}
                        />
                        {post.campaign && (
                            <Link href={showCampaign(post.campaign.id)}>
                                <CampaignChip campaign={post.campaign} />
                            </Link>
                        )}
                        {grid && (
                            <Link
                                href={editGrid(grid.id, {
                                    query: { tile: grid.tile_id },
                                })}
                                className="border-border hover:border-primary/40 hover:text-primary inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors"
                            >
                                <Grid3x3 className="size-3.5" />
                                From grid: {grid.name} · Tile{' '}
                                {grid.tile_position}
                                {grid.sequence !== null &&
                                    ` · Goes out ${ordinal(grid.sequence)}`}
                            </Link>
                        )}
                    </>
                }
            />
        </>
    );
}
