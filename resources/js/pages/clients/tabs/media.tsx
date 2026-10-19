import { router } from '@inertiajs/react';
import { FileImage } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { confirmDialog } from '@/components/confirm-dialog';
import type { ClientPageProps } from '@/components/clients/client-layout';
import {
    ClientLayout,
    getXsrfTokenFromCookie,
    TabHeading,
    TabPanel,
} from '@/components/clients/client-layout';
import type { LibraryAsset } from '@/components/post-editor/asset-library-dialog';
import { AssetLibraryDialog } from '@/components/post-editor/asset-library-dialog';
import { AssetThumbnail } from '@/components/post-editor/asset-thumbnail';
import {
    BrowseLibraryTile,
    UploadTile,
} from '@/components/post-editor/media-picker';
import type { AssetData } from '@/components/post-editor/types';
import { index } from '@/routes/clients';
import { store as storeAsset } from '@/routes/clients/assets';
import { destroy as destroyAsset } from '@/routes/assets';

const jsonHeaders = () => ({
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-XSRF-TOKEN': getXsrfTokenFromCookie(),
});

/**
 * Step I-2.4 — Assets tab (`clients.media`). Uses the post editor's Upload / Browse library
 * tiles instead of listing every asset: the library is searched and paged server-side in
 * `AssetLibraryDialog` (`manage` mode), so this page loads no asset rows at all. Files uploaded
 * here show below the tiles until the page is left.
 */
export default function ClientMediaTab(page: ClientPageProps) {
    const { client, can, stats } = page;
    const [browsing, setBrowsing] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [uploaded, setUploaded] = useState<AssetData[]>([]);

    const refreshCount = () => router.reload({ only: ['stats'] });

    const handleUpload = async (file: File) => {
        setUploading(true);

        try {
            const formData = new FormData();
            formData.append('source', 'upload');
            formData.append('file', file);

            const response = await fetch(storeAsset.url(client.id), {
                method: 'POST',
                credentials: 'same-origin',
                headers: jsonHeaders(),
                body: formData,
            });

            // The web server rejects oversized bodies (HTTP 413) with an HTML page before
            // Laravel runs, so there is no JSON message to show.
            if (response.status === 413) {
                toast.error(
                    'This file is larger than the server accepts. Ask an admin to raise the upload limit.',
                );

                return;
            }

            const data = await response.json();

            if (!response.ok || !data.asset) {
                toast.error(
                    typeof data?.message === 'string'
                        ? data.message
                        : 'Unable to upload the asset.',
                );

                return;
            }

            setUploaded((current) => [data.asset as AssetData, ...current]);
            toast.success('Uploaded to the library.');
            refreshCount();
        } catch {
            toast.error('Unable to upload the asset.');
        } finally {
            setUploading(false);
        }
    };

    const handleDelete = async (asset: LibraryAsset): Promise<boolean> => {
        const confirmed = await confirmDialog({
            title: 'Remove this asset?',
            description: `${asset.original_filename ?? 'This asset'} will be removed from the library. Posts that already used it keep their history.`,
            confirmLabel: 'Remove',
            tone: 'destructive',
        });

        if (!confirmed) {
            return false;
        }

        try {
            const response = await fetch(destroyAsset.url(asset.id), {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: jsonHeaders(),
            });

            if (!response.ok) {
                toast.error('Unable to remove the asset.');

                return false;
            }
        } catch {
            toast.error('Unable to remove the asset.');

            return false;
        }

        setUploaded((current) => current.filter((a) => a.id !== asset.id));
        toast.success('Asset removed.');
        refreshCount();

        return true;
    };

    return (
        <ClientLayout {...page}>
            <TabPanel>
                <TabHeading
                    icon={FileImage}
                    title="Assets"
                    description={`${stats.assets.toLocaleString()} ${stats.assets === 1 ? 'asset' : 'assets'} in this client's library`}
                />

                <ul className="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                    {can.createAsset && (
                        <li>
                            <UploadTile
                                uploading={uploading}
                                onUpload={(file) => void handleUpload(file)}
                            />
                        </li>
                    )}
                    <li>
                        <BrowseLibraryTile onClick={() => setBrowsing(true)} />
                    </li>
                    {uploaded.map((asset) => (
                        <li
                            key={asset.id}
                            className="bg-muted border-border relative aspect-square overflow-hidden rounded-xl border"
                            title={asset.original_filename ?? undefined}
                        >
                            <a
                                href={asset.url ?? undefined}
                                target="_blank"
                                rel="noreferrer"
                                className="block size-full"
                            >
                                <AssetThumbnail asset={asset} />
                            </a>
                        </li>
                    ))}
                </ul>

                {uploaded.length > 0 && (
                    <p className="text-muted-foreground text-xs">
                        Just uploaded — browse the library to see everything.
                    </p>
                )}
            </TabPanel>

            <AssetLibraryDialog
                open={browsing}
                onOpenChange={setBrowsing}
                clientId={client.id}
                mode="manage"
                onDelete={handleDelete}
                description="Every asset uploaded for this client. Open one to view it full size."
            />
        </ClientLayout>
    );
}

ClientMediaTab.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
