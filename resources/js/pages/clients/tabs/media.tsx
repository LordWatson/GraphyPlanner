import { Form, router } from '@inertiajs/react';
import { FileImage, Figma, Link2, Trash2, UploadCloud } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/clients';
import { store as storeAsset } from '@/routes/clients/assets';
import { destroy as destroyAsset } from '@/routes/assets';
import type { ClientPageProps } from '@/components/clients/client-layout';
import {
    ClientLayout,
    TabHeading,
    TabPanel,
} from '@/components/clients/client-layout';
import type { Paginated } from '@/components/pagination';
import { Pagination } from '@/components/pagination';

type AssetData = {
    id: number;
    campaign_id: number | null;
    source: 'upload' | 'figma' | 'url';
    type: string | null;
    url: string | null;
    original_filename: string | null;
    mime_type: string | null;
    size: number | null;
    rights: string | null;
    variant_group_id: string | null;
    can: { delete: boolean };
};

const assetSourceOptions = [
    { value: 'upload', label: 'Upload file' },
    { value: 'figma', label: 'Figma link' },
    { value: 'url', label: 'External URL' },
];

const assetSourceIcon: Record<string, typeof UploadCloud> = {
    upload: UploadCloud,
    figma: Figma,
    url: Link2,
};

/**
 * Step I-2.4 — Assets tab (`clients.media`), paginated newest first.
 */
export default function ClientMediaTab({
    assets: assetPage,
    ...page
}: ClientPageProps & {
    assets: Paginated<AssetData>;
}) {
    const { client, can } = page;
    const assets = assetPage.data;
    const [assetSource, setAssetSource] = useState<'upload' | 'figma' | 'url'>(
        'upload',
    );

    return (
        <ClientLayout {...page}>
            <TabPanel>
                <TabHeading
                    icon={FileImage}
                    title="Assets"
                    description="Uploads and Figma/URL links for this client"
                />

                <>
                    <>
                        {can.createAsset && (
                            <Form
                                {...storeAsset.form(client.id)}
                                resetOnSuccess
                                className="border-border bg-card/50 grid grid-cols-2 gap-3 rounded-2xl border border-dashed p-4 shadow-sm sm:grid-cols-4"
                                encType="multipart/form-data"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-1">
                                            <Label htmlFor="source">
                                                Source
                                            </Label>
                                            <Select
                                                name="source"
                                                defaultValue="upload"
                                                onValueChange={(value) =>
                                                    setAssetSource(
                                                        value as
                                                            | 'upload'
                                                            | 'figma'
                                                            | 'url',
                                                    )
                                                }
                                            >
                                                <SelectTrigger
                                                    id="source"
                                                    className="w-full"
                                                >
                                                    <SelectValue placeholder="Select a source" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {assetSourceOptions.map(
                                                        (source) => (
                                                            <SelectItem
                                                                key={
                                                                    source.value
                                                                }
                                                                value={
                                                                    source.value
                                                                }
                                                            >
                                                                {source.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={errors.source}
                                            />
                                        </div>
                                        {assetSource === 'upload' ? (
                                            <div className="grid gap-1">
                                                <Label htmlFor="file">
                                                    File
                                                </Label>
                                                <Input
                                                    id="file"
                                                    name="file"
                                                    type="file"
                                                    accept="image/*,video/*,.pdf,.doc,.docx,.ppt,.pptx,.zip"
                                                />
                                                <InputError
                                                    message={errors.file}
                                                />
                                            </div>
                                        ) : (
                                            <div className="grid gap-1">
                                                <Label htmlFor="url">
                                                    {assetSource === 'figma'
                                                        ? 'Figma URL'
                                                        : 'URL'}
                                                </Label>
                                                <Input
                                                    id="url"
                                                    name="url"
                                                    type="url"
                                                    placeholder="https://"
                                                />
                                                <InputError
                                                    message={errors.url}
                                                />
                                            </div>
                                        )}
                                        <div className="grid gap-1">
                                            <Label htmlFor="rights">
                                                Rights
                                            </Label>
                                            <Input id="rights" name="rights" />
                                            <InputError
                                                message={errors.rights}
                                            />
                                        </div>
                                        <div className="col-span-full">
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                Add asset
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}

                        {assets.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No assets yet.
                            </p>
                        ) : (
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                {assets.map((asset) => {
                                    const SourceIcon =
                                        assetSourceIcon[asset.source] ??
                                        FileImage;
                                    return (
                                        <div
                                            key={asset.id}
                                            className="border-border bg-card hover:border-primary/30 hover:bg-muted/40 flex items-center justify-between gap-3 rounded-xl border p-3 shadow-sm transition-colors"
                                        >
                                            <div className="flex items-center gap-3">
                                                <span className="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-full">
                                                    <SourceIcon className="size-4" />
                                                </span>
                                                <div className="flex flex-col gap-1">
                                                    <div className="flex items-center gap-2">
                                                        <a
                                                            href={
                                                                asset.url ?? '#'
                                                            }
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="text-sm font-medium underline"
                                                        >
                                                            {asset.original_filename ??
                                                                asset.url}
                                                        </a>
                                                        <Badge variant="outline">
                                                            {asset.source}
                                                        </Badge>
                                                    </div>
                                                    {asset.rights && (
                                                        <span className="text-muted-foreground text-xs">
                                                            {asset.rights}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            {asset.can.delete && (
                                                <Form
                                                    {...destroyAsset.form(
                                                        asset.id,
                                                    )}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            <Trash2 />
                                                            Remove
                                                        </Button>
                                                    )}
                                                </Form>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </>
                </>
            </TabPanel>
            <Pagination
                page={assetPage}
                label="assets"
                onPageChange={(page) =>
                    router.reload({ data: { page }, only: ['assets'] })
                }
            />
        </ClientLayout>
    );
}

ClientMediaTab.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
