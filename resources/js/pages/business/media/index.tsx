import { Head, Link } from '@inertiajs/react';
import {
    Check,
    Copy,
    ImagePlus,
    Images,
    Loader2,
    Search,
    Sparkles,
    Trash2,
    Wand2,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { confirmDialog } from '@/components/confirm-dialog';
import { EmptyState, Page, PageHeader, Panel } from '@/components/page';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
    captions as mediaCaptions,
    index as mediaIndex,
    library as mediaLibrary,
    store as mediaStore,
} from '@/routes/business/media';
import { index as imageStudio } from '@/routes/business/studio';

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

type CaptionSuggestion = {
    caption: string;
    hashtags: string[];
    altText: string | null;
    firstComment: string | null;
};

export default function BusinessMedia({
    assetTypes,
    aiEnabled,
    can,
}: {
    assetTypes: Option[];
    aiEnabled: boolean;
    can: { create: boolean; suggestCaptions: boolean };
}) {
    const [captionsFor, setCaptionsFor] = useState<Asset | null>(null);
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
                    <>
                        {aiEnabled && (
                            <Button variant="outline" asChild>
                                <Link href={imageStudio()}>
                                    <Wand2 />
                                    Create an image
                                </Link>
                            </Button>
                        )}
                        {can.create && (
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
                        )}
                    </>
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
                                    canCaption={
                                        aiEnabled &&
                                        can.suggestCaptions &&
                                        asset.type === 'image'
                                    }
                                    onCaption={() => setCaptionsFor(asset)}
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

            <CaptionsDialog
                asset={captionsFor}
                onClose={() => setCaptionsFor(null)}
            />
        </Page>
    );
}

function MediaTile({
    asset,
    canCaption,
    onCaption,
    onRemove,
}: {
    asset: Asset;
    canCaption: boolean;
    onCaption: () => void;
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
            <div className="absolute top-2 right-2 flex gap-1.5 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100">
                {canCaption && (
                    <button
                        type="button"
                        onClick={onCaption}
                        aria-label={`Caption ${asset.original_filename ?? 'this photo'} with AI`}
                        className="bg-ink/70 text-cream hover:bg-ink flex size-7 items-center justify-center rounded-full transition-colors focus-visible:opacity-100"
                    >
                        <Sparkles className="size-3.5" />
                    </button>
                )}
                {asset.can.delete && (
                    <button
                        type="button"
                        onClick={onRemove}
                        aria-label={`Remove ${asset.original_filename ?? 'media'}`}
                        className="bg-ink/70 text-cream hover:bg-ink flex size-7 items-center justify-center rounded-full transition-colors focus-visible:opacity-100"
                    >
                        <Trash2 className="size-3.5" />
                    </button>
                )}
            </div>
        </div>
    );
}

function CaptionsDialog({
    asset,
    onClose,
}: {
    asset: Asset | null;
    onClose: () => void;
}) {
    const [loading, setLoading] = useState(false);
    const [suggestions, setSuggestions] = useState<CaptionSuggestion[]>([]);

    useEffect(() => {
        if (asset === null) {
            return;
        }

        setSuggestions([]);
        setLoading(true);
        const controller = new AbortController();

        void http
            .post<{ suggestions: CaptionSuggestion[] }>(
                mediaCaptions(asset.id).url,
                {},
                {
                    signal: controller.signal,
                    toastOnError: true,
                    rateLimitMessage: 'Give it a moment before asking again.',
                },
            )
            .then((response) => {
                if (!controller.signal.aborted) {
                    setLoading(false);

                    if (response.ok) {
                        setSuggestions(response.data.suggestions ?? []);
                    }
                }
            });

        return () => controller.abort();
    }, [asset]);

    return (
        <Dialog
            open={asset !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Caption from photo</DialogTitle>
                    <DialogDescription>
                        Three options in your brand voice. Copy one, or use it
                        as a starting point.
                    </DialogDescription>
                </DialogHeader>

                {loading ? (
                    <div className="text-muted-foreground flex items-center justify-center gap-2 py-10 text-sm">
                        <Loader2 className="size-4 animate-spin" />
                        Reading your photo…
                    </div>
                ) : suggestions.length === 0 ? (
                    <EmptyState
                        compact
                        icon={Sparkles}
                        title="Nothing came back."
                        description="We couldn't write a caption for this one — try another photo, or write your own."
                    />
                ) : (
                    <ul className="flex flex-col gap-3">
                        {suggestions.map((suggestion, index) => (
                            <CaptionCard key={index} suggestion={suggestion} />
                        ))}
                    </ul>
                )}
            </DialogContent>
        </Dialog>
    );
}

function CaptionCard({ suggestion }: { suggestion: CaptionSuggestion }) {
    const [copied, setCopied] = useState(false);

    const text = [
        suggestion.caption,
        suggestion.hashtags.map((tag) => `#${tag}`).join(' '),
    ]
        .filter(Boolean)
        .join('\n\n');

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            toast.success('Caption copied.');
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            toast.error('Could not copy — select and copy by hand.');
        }
    };

    return (
        <li className="border-border/70 bg-background/40 flex flex-col gap-2 rounded-xl border p-4">
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm whitespace-pre-wrap">
                    {suggestion.caption}
                </p>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => void copy()}
                    aria-label="Copy caption"
                >
                    {copied ? (
                        <Check className="size-4" />
                    ) : (
                        <Copy className="size-4" />
                    )}
                </Button>
            </div>
            {suggestion.hashtags.length > 0 && (
                <p className="text-muted-foreground text-xs">
                    {suggestion.hashtags.map((tag) => `#${tag}`).join(' ')}
                </p>
            )}
            {suggestion.altText && (
                <p className="text-muted-foreground text-xs italic">
                    Alt text: {suggestion.altText}
                </p>
            )}
        </li>
    );
}

BusinessMedia.layout = {
    breadcrumbs: [{ title: 'Media', href: mediaIndex() }],
};
