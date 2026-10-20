import { router } from '@inertiajs/react';
import { FolderOpen, ImagePlus, Minus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AssetPreview } from '@/components/asset-preview';
import { CampaignLayout } from '@/components/campaigns/campaign-layout';
import type { CampaignWorkspaceProps } from '@/components/campaigns/types';
import { confirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/page';
import type { Paginated } from '@/components/pagination';
import { Pagination } from '@/components/pagination';
import { AssetLibraryDialog } from '@/components/post-editor/asset-library-dialog';
import { UploadTile } from '@/components/post-editor/media-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { http } from '@/lib/http';
import { index as campaignsIndex } from '@/routes/campaigns';
import {
    destroy as removeFromKit,
    store as addToKit,
} from '@/routes/campaigns/kit';
import { store as storeAsset } from '@/routes/clients/assets';

type KitAsset = {
    id: number;
    url: string | null;
    original_filename: string | null;
    type: string | null;
    mime_type: string | null;
    source: string;
    is_ai_generated: boolean;
    created_at: string | null;
};

/**
 * Step C-4.1 — the campaign's asset kit: everything tagged to this campaign, added from the
 * client's library or uploaded straight in. Removing takes an asset out of the kit; it stays in
 * the library. Designers manage the kit too.
 */
export default function CampaignAssets(
    props: CampaignWorkspaceProps & { assets: Paginated<KitAsset> },
) {
    const { campaign, can, assets } = props;
    const [browsing, setBrowsing] = useState(false);
    const [uploading, setUploading] = useState(false);

    const upload = async (file: File) => {
        const body = new FormData();
        body.append('source', 'upload');
        body.append('file', file);
        body.append('campaign_id', String(campaign.id));
        setUploading(true);

        const result = await http.post(
            storeAsset.url(campaign.client.id),
            body,
        );
        setUploading(false);

        if (!result.ok) {
            toast.error(
                result.status === 422
                    ? result.message
                    : 'That file didn’t upload. Check its size and type.',
            );

            return;
        }

        toast.success('Added to the kit.');
        router.reload({ only: ['assets', 'counts'] });
    };

    const remove = async (asset: KitAsset) => {
        if (
            await confirmDialog({
                title: 'Take this out of the kit?',
                description:
                    'It stays in the client’s library and on any post that uses it.',
                confirmLabel: 'Remove from kit',
            })
        ) {
            router.delete(
                removeFromKit.url({ campaign: campaign.id, asset: asset.id }),
                { preserveScroll: true },
            );
        }
    };

    return (
        <CampaignLayout
            {...props}
            actions={
                can.produce && (
                    <Button variant="outline" onClick={() => setBrowsing(true)}>
                        <ImagePlus />
                        Add from library
                    </Button>
                )
            }
        >
            {assets.data.length === 0 && !can.produce ? (
                <EmptyState
                    icon={FolderOpen}
                    title="No assets yet."
                    description="The campaign’s photos, videos and files collect here."
                />
            ) : (
                <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    {can.produce && (
                        <li className="aspect-square">
                            <UploadTile
                                uploading={uploading}
                                onUpload={(file) => void upload(file)}
                            />
                        </li>
                    )}
                    {assets.data.map((asset) => (
                        <li
                            key={asset.id}
                            className="group bg-muted border-border relative aspect-square overflow-hidden rounded-xl border"
                        >
                            <a
                                href={asset.url ?? undefined}
                                target="_blank"
                                rel="noreferrer"
                                className="block size-full"
                            >
                                <AssetPreview asset={asset} />
                            </a>
                            {asset.is_ai_generated && (
                                <Badge
                                    variant="lilac"
                                    className="absolute bottom-1.5 left-1.5"
                                >
                                    AI
                                </Badge>
                            )}
                            <span className="bg-ink/70 text-cream absolute inset-x-0 bottom-0 truncate px-2 py-1 text-[11px] opacity-0 transition-opacity group-hover:opacity-100">
                                {asset.original_filename}
                            </span>
                            {can.produce && (
                                <Button
                                    size="icon"
                                    variant="outline"
                                    className="bg-card/90 absolute top-1.5 right-1.5 size-7 opacity-0 group-hover:opacity-100 focus-visible:opacity-100 [@media(hover:none)]:opacity-100"
                                    aria-label={`Remove ${asset.original_filename ?? 'asset'} from the kit`}
                                    onClick={() => remove(asset)}
                                >
                                    <Minus className="size-3.5" />
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <Pagination
                page={assets}
                label="assets"
                onPageChange={(page) =>
                    router.reload({ data: { page }, only: ['assets'] })
                }
            />

            {can.produce && (
                <AssetLibraryDialog
                    open={browsing}
                    onOpenChange={setBrowsing}
                    clientId={campaign.client.id}
                    campaignId={campaign.id}
                    defaultScope="all"
                    title="Add to the campaign kit"
                    description="Pick from the client’s library. They stay in the library; the kit just groups them."
                    confirmLabel="Add to kit"
                    onConfirm={(picked) => {
                        if (picked.length > 0) {
                            router.post(
                                addToKit.url(campaign.id),
                                { asset_ids: picked.map((asset) => asset.id) },
                                { preserveScroll: true },
                            );
                        }
                    }}
                />
            )}
        </CampaignLayout>
    );
}

CampaignAssets.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};
