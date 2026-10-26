import { Head } from '@inertiajs/react';
import { ImagePlus, LinkIcon, Loader2, LockKeyhole, ShieldCheck } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/page';
import { PublicShell, PublicTitle } from '@/components/public-shell';
import { Badge } from '@/components/ui/badge';
import { formatDate } from '@/lib/dates';
import { http } from '@/lib/http';
import { cn } from '@/lib/utils';
import { store as storeUpload } from '@/routes/mcp-upload';

type PostCard = {
    id: number;
    caption_excerpt: string | null;
    platforms: string[];
};

type UploadedAsset = {
    id: number;
    filename: string | null;
    thumbnail_url: string | null;
};

const acceptedFileTypes = 'image/*,video/*,.pdf,.doc,.docx,.ppt,.pptx,.zip';

/**
 * MCP media — the page a user opens from a `request_media_upload` link to drop images for their AI
 * assistant. No login: possession of the link is the capability. One link can target several posts;
 * each gets its own multi-file dropzone, and files route to the right post. An expired link shows a
 * calm dead-end.
 */
export default function McpUploadShow({
    token,
    client,
    posts,
    expires_at,
}: {
    token: string | null;
    client: { name: string } | null;
    posts: PostCard[];
    expires_at: string | null;
}) {
    if (token === null || client === null) {
        return (
            <PublicShell>
                <Head title="Link expired" />
                <EmptyState
                    icon={LinkIcon}
                    title="This link has expired."
                    description="Upload links only work for a short while. Ask your assistant to create a new one."
                />
            </PublicShell>
        );
    }

    return (
        <PublicShell width="lg" context={client.name}>
            <Head title={`Upload media for ${client.name}`} />
            <div className="grid gap-8">
                <PublicTitle
                    kicker="Upload media"
                    title={
                        <>
                            Add media for <em>{client.name}.</em>
                        </>
                    }
                >
                    {posts.length > 0
                        ? 'Drop one or more files onto each post below — they attach straight to it.'
                        : 'Drop one or more files below and they land in this client’s media library.'}
                </PublicTitle>

                {posts.length > 0 ? (
                    <div className="grid gap-4">
                        {posts.map((post, index) => (
                            <UploadSlot
                                key={post.id}
                                token={token}
                                postId={post.id}
                                title={`Post ${index + 1}`}
                                caption={post.caption_excerpt}
                                platforms={post.platforms}
                            />
                        ))}
                    </div>
                ) : (
                    <UploadSlot token={token} title="Add to library" />
                )}

                <ul className="text-muted-foreground grid gap-2 text-xs">
                    <li className="flex items-start gap-2">
                        <LockKeyhole className="mt-0.5 size-3.5 shrink-0" />
                        This link only adds media to {client.name} and shows
                        nothing else.
                    </li>
                    <li className="flex items-start gap-2">
                        <ShieldCheck className="mt-0.5 size-3.5 shrink-0" />
                        {expires_at
                            ? `It stops working on ${formatDate(expires_at)}.`
                            : 'It stops working after a short while.'}
                    </li>
                </ul>
            </div>
        </PublicShell>
    );
}

/** One post's (or the library's) multi-file dropzone. Uploads each file, routing it to `postId`. */
function UploadSlot({
    token,
    postId,
    title,
    caption,
    platforms = [],
}: {
    token: string;
    postId?: number;
    title: string;
    caption?: string | null;
    platforms?: string[];
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const [uploading, setUploading] = useState(0);
    const [uploaded, setUploaded] = useState<UploadedAsset[]>([]);

    const uploadFile = async (file: File) => {
        setUploading((n) => n + 1);

        const formData = new FormData();
        formData.append('file', file);
        if (postId !== undefined) {
            formData.append('post_id', String(postId));
        }

        const result = await http.post<{ asset?: UploadedAsset }>(
            storeUpload.url(token),
            formData,
        );
        setUploading((n) => n - 1);

        if (!result.ok || !result.data.asset) {
            toast.error(
                result.ok
                    ? `Couldn’t upload ${file.name}.`
                    : result.message,
            );

            return;
        }

        setUploaded((current) => [...current, result.data.asset as UploadedAsset]);
    };

    const handleFiles = (files: FileList | null) => {
        if (!files) {
            return;
        }

        for (const file of Array.from(files)) {
            void uploadFile(file);
        }
    };

    return (
        <div className="border-border bg-card grid gap-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{title}</span>
                {platforms.map((platform) => (
                    <Badge key={platform} variant="secondary">
                        {platform}
                    </Badge>
                ))}
            </div>
            {caption && (
                <p className="text-muted-foreground line-clamp-2 text-sm">
                    {caption}
                </p>
            )}

            <button
                type="button"
                onClick={() => inputRef.current?.click()}
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDragging(false);
                    handleFiles(e.dataTransfer.files);
                }}
                className={cn(
                    'border-border text-muted-foreground hover:border-foreground/30 hover:text-foreground flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed px-4 py-6 text-center text-xs transition-colors',
                    dragging && 'border-primary text-primary bg-accent',
                )}
            >
                {uploading > 0 ? (
                    <Loader2 className="size-5 animate-spin" />
                ) : (
                    <ImagePlus className="size-5" />
                )}
                <span className="text-foreground font-medium">
                    {uploading > 0 ? `Uploading ${uploading}…` : 'Upload or drop files'}
                </span>
                <span>You can add several at once.</span>
            </button>

            {uploaded.length > 0 && (
                <ul className="grid grid-cols-4 gap-2 sm:grid-cols-6">
                    {uploaded.map((asset) => (
                        <li
                            key={asset.id}
                            className="bg-muted border-border aspect-square overflow-hidden rounded-lg border"
                            title={asset.filename ?? undefined}
                        >
                            {asset.thumbnail_url ? (
                                <img
                                    src={asset.thumbnail_url}
                                    alt={asset.filename ?? ''}
                                    className="size-full object-cover"
                                />
                            ) : (
                                <div className="text-muted-foreground flex size-full items-center justify-center text-[10px]">
                                    Uploaded
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <input
                ref={inputRef}
                type="file"
                multiple
                className="hidden"
                accept={acceptedFileTypes}
                onChange={(e) => {
                    handleFiles(e.target.files);
                    e.target.value = '';
                }}
            />
        </div>
    );
}
