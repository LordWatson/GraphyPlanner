import { Head } from '@inertiajs/react';
import { ImagePlus, Images, Loader2, Search, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { confirmDialog } from '@/components/confirm-dialog';
import { EmptyState, Page, PageHeader, Panel } from '@/components/page';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { http } from '@/lib/http';
import { destroy as destroyAsset } from '@/routes/assets';
import {
    index as mediaIndex,
    library as mediaLibrary,
    store as mediaStore,
} from '@/routes/business/media';

type Option = { value: string; label: string };

type Asset = {
    id: number;
    url: string | null;
    thumbnail_url: string | null;
    original_filename: string | null;
    type: string | null;
    is_ai_generated: boolean;
    can: { delete: boolean };
};

type LibraryPage = {
    data: Asset[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};

const ALL = '__all__';

export default function BusinessMedia({
    assetTypes,
    can,
}: {
    assetTypes: Option[];
    can: { create: boolean };
}) {
    const [search, setSearch] = useState('');
    const [debounced, setDebounced] = useState('');
    const [type, setType] = useState(ALL);
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<LibraryPage | null>(null);
    const [loading, setLoading] = useState(true);
    const [uploading, setUploading] = useState(false);
    const fileInput = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            setDebounced(search.trim());
            setPage(1);
        }, 300);

        return () => window.clearTimeout(timer);
    }, [search]);

    const load = useCallback(
        (signal?: AbortSignal) => {
            setLoading(true);

            return http
                .get<LibraryPage>(
                    mediaLibrary({
                        query: {
                            search: debounced || undefined,
                            type: type === ALL ? undefined : type,
                            page,
                        },
                    }).url,
                    { signal },
                )
                .then((response) => {
                    if (response.ok) {
                        setResult(response.data);
                    }

                    if (!signal?.aborted) {
                        setLoading(false);
                    }
                });
        },
        [debounced, type, page],
    );

    useEffect(() => {
        const controller = new AbortController();
        void load(controller.signal);

        return () => controller.abort();
    }, [load]);

    const upload = async (files: FileList | null) => {
        if (!files || files.length === 0) {
            return;
        }

        setUploading(true);

        for (const file of Array.from(files)) {
            const form = new FormData();
            form.append('source', 'upload');
            form.append('file', file);

            const response = await http.post(mediaStore().url, form, {
                toastOnError: true,
            });

            if (!response.ok) {
                break;
            }
        }

        setUploading(false);

        if (fileInput.current) {
            fileInput.current.value = '';
        }

        toast.success('Media added.');
        setPage(1);
        void load();
    };

    const remove = async (asset: Asset) => {
        const confirmed = await confirmDialog({
            title: 'Remove this media?',
            description:
                'It will be removed from your library. Posts already using it keep their copy.',
            confirmLabel: 'Remove',
            tone: 'destructive',
        });

        if (!confirmed) {
            return;
        }

        const response = await http.delete(
            destroyAsset(asset.id).url,
            undefined,
            {
                toastOnError: true,
            },
        );

        if (response.ok) {
            toast.success('Media removed.');
            void load();
        }
    };

    const assets = result?.data ?? [];

    return (
        <Page>
            <Head title="Media" />

            <PageHeader
                eyebrow="Your library"
                title={
                    <>
                        Your <em>media</em>.
                    </>
                }
                description="Every photo and video, ready to drop into a post."
                actions={
                    can.create && (
                        <Button
                            variant="brand"
                            disabled={uploading}
                            onClick={() => fileInput.current?.click()}
                        >
                            {uploading ? (
                                <Loader2 className="animate-spin" />
                            ) : (
                                <ImagePlus />
                            )}
                            Upload
                        </Button>
                    )
                }
            />

            <input
                ref={fileInput}
                type="file"
                multiple
                accept="image/*,video/*"
                className="hidden"
                onChange={(event) => void upload(event.target.files)}
            />

            <Panel bodyClassName="p-0">
                <div className="border-border/70 flex flex-wrap items-center gap-2 border-b p-4 sm:p-5">
                    <div className="relative min-w-0 flex-1">
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search by filename…"
                            className="pl-9"
                            aria-label="Search media"
                        />
                    </div>
                    <Select
                        value={type}
                        onValueChange={(value) => {
                            setType(value);
                            setPage(1);
                        }}
                    >
                        <SelectTrigger className="w-[140px]">
                            <SelectValue placeholder="Type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All types</SelectItem>
                            {assetTypes.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="p-5 sm:p-6">
                    {loading && assets.length === 0 ? (
                        <div className="text-muted-foreground flex items-center justify-center gap-2 py-16 text-sm">
                            <Loader2 className="size-4 animate-spin" />
                            Loading your media…
                        </div>
                    ) : assets.length === 0 ? (
                        <EmptyState
                            icon={Images}
                            title="Your library is empty."
                            description="Upload a photo or video and it'll be ready for your next post."
                            action={
                                can.create && (
                                    <Button
                                        variant="brand"
                                        onClick={() =>
                                            fileInput.current?.click()
                                        }
                                    >
                                        <ImagePlus />
                                        Upload
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                            {assets.map((asset) => (
                                <MediaTile
                                    key={asset.id}
                                    asset={asset}
                                    onRemove={() => void remove(asset)}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </Panel>

            {result && (
                <Pagination
                    page={result.meta}
                    label="items"
                    onPageChange={setPage}
                />
            )}
        </Page>
    );
}

function MediaTile({
    asset,
    onRemove,
}: {
    asset: Asset;
    onRemove: () => void;
}) {
    const preview = asset.thumbnail_url ?? asset.url;

    return (
        <div className="group border-border bg-muted relative aspect-square overflow-hidden rounded-xl border">
            {preview ? (
                <img
                    src={preview}
                    alt={asset.original_filename ?? ''}
                    className="size-full object-cover"
                />
            ) : (
                <div className="text-muted-foreground flex size-full items-center justify-center">
                    <Images className="size-6" />
                </div>
            )}
            {asset.is_ai_generated && (
                <span className="bg-lime text-ink absolute top-2 left-2 rounded-[3px] px-1.5 py-0.5 font-mono text-[10px] font-medium uppercase">
                    AI
                </span>
            )}
            {asset.can.delete && (
                <button
                    type="button"
                    onClick={onRemove}
                    aria-label={`Remove ${asset.original_filename ?? 'media'}`}
                    className="bg-ink/70 text-cream absolute top-2 right-2 flex size-7 items-center justify-center rounded-full opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                >
                    <Trash2 className="size-3.5" />
                </button>
            )}
        </div>
    );
}

BusinessMedia.layout = {
    breadcrumbs: [{ title: 'Media', href: mediaIndex() }],
};
