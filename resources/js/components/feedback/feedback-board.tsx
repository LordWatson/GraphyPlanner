import { router } from '@inertiajs/react';
import {
    Check,
    CheckCircle2,
    CornerDownRight,
    MapPin,
    MessageSquare,
    Sparkles,
    Video as VideoIcon,
    X,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { CaptionAnnotator } from './caption-annotator';
import { PinOverlay } from './pin-overlay';
import {
    type FeedbackAsset,
    type FeedbackDraft,
    type FeedbackRoutes,
    type FeedbackThread,
    formatTimestamp,
} from './types';

function isImage(asset: FeedbackAsset): boolean {
    return (
        asset.type === 'image' ||
        (asset.mime_type?.startsWith('image/') ?? false)
    );
}
function isVideo(asset: FeedbackAsset): boolean {
    return (
        asset.type === 'video' ||
        (asset.mime_type?.startsWith('video/') ?? false)
    );
}

/**
 * Step M-6.4 — the visual-feedback board. Reviewers pin comments to a point on an image, a moment
 * in a video, or a range of the caption (optionally suggesting a replacement); everyone sees the
 * threads, replies, and resolves them. Staff can accept a suggested caption edit in one click.
 */
export function FeedbackBoard({
    assets,
    caption,
    threads,
    routes,
    canComment,
    mode,
    hideHeader = false,
}: {
    assets: FeedbackAsset[];
    caption: string;
    threads: FeedbackThread[];
    routes: FeedbackRoutes;
    canComment: boolean;
    mode: 'reviewer' | 'staff';
    /** Suppress the internal "Feedback" title row — used when a collapsible wrapper already
        provides the header (e.g. the post editor). Defaults false so the portal/review link keep it. */
    hideHeader?: boolean;
}) {
    const mediaAssets = assets.filter(
        (a) => a.url !== null && (isImage(a) || isVideo(a)),
    );
    const [activeAssetId, setActiveAssetId] = useState<number | null>(
        mediaAssets[0]?.id ?? null,
    );
    const [placing, setPlacing] = useState(false);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [draft, setDraft] = useState<FeedbackDraft | null>(null);
    const [body, setBody] = useState('');
    const [suggestedEdit, setSuggestedEdit] = useState('');
    const videoRef = useRef<HTMLVideoElement>(null);

    const activeAsset = mediaAssets.find((a) => a.id === activeAssetId) ?? null;
    const unresolved = threads.filter(
        (t) => t.is_feedback && !t.resolved,
    ).length;

    // Pins for the active image, numbered in thread order.
    const imagePins = useMemo(() => {
        if (!activeAsset || !isImage(activeAsset)) {
            return [];
        }
        return threads
            .filter(
                (t) =>
                    t.anchor_type === 'image' &&
                    t.anchor?.asset_id === activeAsset.id,
            )
            .map((thread, i) => ({ thread, index: i + 1 }));
    }, [threads, activeAsset]);

    const startDraft = (next: FeedbackDraft) => {
        setDraft(next);
        setPlacing(false);
        setSelectedId(null);
    };

    const submit = () => {
        if (!body.trim()) {
            return;
        }
        const data: Record<string, unknown> = { body };
        if (draft) {
            data.anchor_type = draft.anchorType;
            data.anchor = draft.anchor;
            if (draft.anchorType === 'caption' && suggestedEdit.trim()) {
                data.suggested_edit = suggestedEdit;
            }
        }
        router.post(routes.create, data as Parameters<typeof router.post>[1], {
            preserveScroll: true,
            onSuccess: () => {
                setBody('');
                setSuggestedEdit('');
                setDraft(null);
            },
        });
    };

    const reply = (parentId: number, text: string, done: () => void) => {
        if (!text.trim()) {
            return;
        }
        router.post(
            routes.create,
            { body: text, parent_id: parentId },
            { preserveScroll: true, onSuccess: done },
        );
    };

    const toggleResolve = (thread: FeedbackThread) => {
        router.post(
            routes.resolve(thread.id),
            { resolved: !thread.resolved },
            { preserveScroll: true },
        );
    };

    return (
        <div className="grid gap-4">
            {!hideHeader && (
                <div className="flex items-center justify-between">
                    <span className="eyebrow text-muted-foreground">
                        Feedback
                    </span>
                    {unresolved > 0 && (
                        <Badge variant="warning">{unresolved} to resolve</Badge>
                    )}
                </div>
            )}

            {activeAsset && (
                <div className="grid gap-2">
                    {mediaAssets.length > 1 && (
                        <div className="flex flex-wrap gap-2">
                            {mediaAssets.map((asset) => (
                                <button
                                    key={asset.id}
                                    type="button"
                                    onClick={() => setActiveAssetId(asset.id)}
                                    className={cn(
                                        'size-12 overflow-hidden rounded-lg border-2',
                                        asset.id === activeAssetId
                                            ? 'border-info'
                                            : 'border-transparent',
                                    )}
                                >
                                    <img
                                        src={
                                            asset.thumbnail_url ??
                                            asset.url ??
                                            ''
                                        }
                                        alt=""
                                        className="size-full object-cover"
                                    />
                                </button>
                            ))}
                        </div>
                    )}

                    {isImage(activeAsset) ? (
                        <>
                            <PinOverlay
                                url={activeAsset.url ?? ''}
                                pins={imagePins}
                                placing={placing}
                                selectedId={selectedId}
                                onSelect={(id) => setSelectedId(id)}
                                onPlace={(x, y) =>
                                    startDraft({
                                        anchorType: 'image',
                                        anchor: {
                                            asset_id: activeAsset.id,
                                            x,
                                            y,
                                        },
                                        label: 'Pinned to the image',
                                    })
                                }
                            />
                            {canComment && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={placing ? 'default' : 'outline'}
                                    onClick={() => setPlacing((p) => !p)}
                                    className="justify-self-start"
                                >
                                    <MapPin className="size-4" />
                                    {placing
                                        ? 'Click the image to drop a pin'
                                        : 'Add a pin'}
                                </Button>
                            )}
                        </>
                    ) : (
                        <>
                            {/* eslint-disable-next-line jsx-a11y/media-has-caption */}
                            <video
                                ref={videoRef}
                                src={activeAsset.url ?? ''}
                                controls
                                className="w-full rounded-xl border"
                            />
                            {canComment && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        startDraft({
                                            anchorType: 'video',
                                            anchor: {
                                                asset_id: activeAsset.id,
                                                t:
                                                    videoRef.current
                                                        ?.currentTime ?? 0,
                                            },
                                            label: `At ${formatTimestamp(videoRef.current?.currentTime ?? 0)}`,
                                        })
                                    }
                                    className="justify-self-start"
                                >
                                    <VideoIcon className="size-4" />
                                    Comment at{' '}
                                    {formatTimestamp(
                                        videoRef.current?.currentTime ?? 0,
                                    )}
                                </Button>
                            )}
                        </>
                    )}
                </div>
            )}

            {caption.trim() !== '' && (
                <CaptionAnnotator
                    caption={caption}
                    threads={threads}
                    canComment={canComment}
                    selectedId={selectedId}
                    onSelectThread={(id) => setSelectedId(id)}
                    onSelectRange={(start, end, quote) =>
                        startDraft({
                            anchorType: 'caption',
                            anchor: { channel: null, start, end, quote },
                            label: `On “${quote.length > 32 ? quote.slice(0, 32) + '…' : quote}”`,
                        })
                    }
                />
            )}

            {canComment && (
                <div className="border-border bg-card grid gap-2 rounded-xl border p-3">
                    {draft && (
                        <div className="bg-info/10 text-info flex items-center justify-between rounded-lg px-2.5 py-1.5 text-xs">
                            <span className="inline-flex items-center gap-1.5">
                                <MapPin className="size-3.5" />
                                {draft.label}
                            </span>
                            <button
                                type="button"
                                onClick={() => setDraft(null)}
                                aria-label="Remove anchor"
                            >
                                <X className="size-3.5" />
                            </button>
                        </div>
                    )}
                    <textarea
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        rows={2}
                        placeholder={
                            draft ? 'Add your note…' : 'Leave a comment…'
                        }
                        className="border-input bg-background placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/40 w-full rounded-lg border px-3 py-2 text-sm outline-none focus-visible:ring-[3px]"
                    />
                    {draft?.anchorType === 'caption' && (
                        <textarea
                            value={suggestedEdit}
                            onChange={(e) => setSuggestedEdit(e.target.value)}
                            rows={2}
                            placeholder="Optional: suggest a replacement for the whole caption"
                            className="border-input bg-background placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/40 w-full rounded-lg border px-3 py-2 text-sm outline-none focus-visible:ring-[3px]"
                        />
                    )}
                    <Button
                        type="button"
                        size="sm"
                        onClick={submit}
                        disabled={!body.trim()}
                        className="justify-self-start"
                    >
                        <MessageSquare className="size-4" />
                        Post comment
                    </Button>
                </div>
            )}

            <div className="grid gap-3">
                {threads.length === 0 && (
                    <p className="text-muted-foreground text-sm">
                        No feedback yet.
                    </p>
                )}
                {threads.map((thread, i) => (
                    <ThreadCard
                        key={thread.id}
                        thread={thread}
                        index={
                            thread.anchor_type === 'image'
                                ? (imagePins.find(
                                      (p) => p.thread.id === thread.id,
                                  )?.index ?? i + 1)
                                : i + 1
                        }
                        active={selectedId === thread.id}
                        canComment={canComment}
                        mode={mode}
                        routes={routes}
                        onSelect={() => setSelectedId(thread.id)}
                        onReply={reply}
                        onToggleResolve={() => toggleResolve(thread)}
                    />
                ))}
            </div>
        </div>
    );
}

function anchorChip(thread: FeedbackThread, index: number) {
    if (thread.anchor_type === 'image') {
        return (
            <span className="inline-flex items-center gap-1">
                <MapPin className="size-3" /> Pin {index}
            </span>
        );
    }
    if (thread.anchor_type === 'video') {
        return (
            <span className="inline-flex items-center gap-1">
                <VideoIcon className="size-3" />{' '}
                {formatTimestamp(thread.anchor?.t ?? 0)}
            </span>
        );
    }
    if (thread.anchor_type === 'caption') {
        const q = thread.anchor?.quote ?? '';
        return (
            <span className="italic">
                “{q.length > 40 ? q.slice(0, 40) + '…' : q}”
            </span>
        );
    }
    return <span>Comment</span>;
}

function ThreadCard({
    thread,
    index,
    active,
    canComment,
    mode,
    routes,
    onSelect,
    onReply,
    onToggleResolve,
}: {
    thread: FeedbackThread;
    index: number;
    active: boolean;
    canComment: boolean;
    mode: 'reviewer' | 'staff';
    routes: FeedbackRoutes;
    onSelect: () => void;
    onReply: (parentId: number, body: string, done: () => void) => void;
    onToggleResolve: () => void;
}) {
    const [replyBody, setReplyBody] = useState('');

    return (
        <div
            onClick={onSelect}
            className={cn(
                'rounded-xl border p-3 transition-colors',
                thread.resolved
                    ? 'border-border bg-muted/30'
                    : active
                      ? 'border-info bg-info/5'
                      : 'border-border bg-card',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="flex flex-col gap-1">
                    <span className="text-muted-foreground flex items-center gap-2 text-xs">
                        {anchorChip(thread, index)}
                        {thread.internal_only && (
                            <Badge variant="warning">Internal</Badge>
                        )}
                        {thread.resolved && (
                            <span className="text-success inline-flex items-center gap-1">
                                <Check className="size-3" /> Resolved
                                {thread.resolved_by
                                    ? ` · ${thread.resolved_by}`
                                    : ''}
                            </span>
                        )}
                    </span>
                    <span className="text-sm font-medium">
                        {thread.author ?? 'Someone'}
                    </span>
                </div>
                {thread.can.resolve && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={(e) => {
                            e.stopPropagation();
                            onToggleResolve();
                        }}
                    >
                        {thread.resolved ? 'Reopen' : 'Resolve'}
                    </Button>
                )}
            </div>

            <p className="mt-1 text-sm whitespace-pre-wrap">{thread.body}</p>

            {thread.suggested_edit && (
                <div className="border-info/30 bg-info/5 mt-2 rounded-lg border p-2.5">
                    <span className="eyebrow text-info flex items-center gap-1">
                        <Sparkles className="size-3" /> Suggested caption
                    </span>
                    <p className="mt-1 text-sm whitespace-pre-wrap">
                        {thread.suggested_edit}
                    </p>
                    {mode === 'staff' &&
                        thread.can.accept_suggestion &&
                        routes.acceptSuggestion && (
                            <div className="mt-2 flex gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        router.post(
                                            routes.acceptSuggestion!(thread.id),
                                            {},
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    <CheckCircle2 className="size-4" /> Accept
                                </Button>
                                {routes.dismissSuggestion && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            router.post(
                                                routes.dismissSuggestion!(
                                                    thread.id,
                                                ),
                                                {},
                                                { preserveScroll: true },
                                            );
                                        }}
                                    >
                                        Dismiss
                                    </Button>
                                )}
                            </div>
                        )}
                    {thread.suggestion_resolved && (
                        <span className="text-muted-foreground mt-1 block text-xs">
                            Dealt with.
                        </span>
                    )}
                </div>
            )}

            {thread.replies.length > 0 && (
                <div className="mt-2 grid gap-1.5 border-l pl-3">
                    {thread.replies.map((r) => (
                        <div key={r.id} className="text-sm">
                            <span className="font-medium">
                                {r.author ?? 'Someone'}:{' '}
                            </span>
                            <span className="whitespace-pre-wrap">
                                {r.body}
                            </span>
                        </div>
                    ))}
                </div>
            )}

            {canComment && !thread.resolved && (
                <div className="mt-2 flex items-center gap-2">
                    <CornerDownRight className="text-muted-foreground size-4 shrink-0" />
                    <input
                        value={replyBody}
                        onChange={(e) => setReplyBody(e.target.value)}
                        onClick={(e) => e.stopPropagation()}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                onReply(thread.id, replyBody, () =>
                                    setReplyBody(''),
                                );
                            }
                        }}
                        placeholder="Reply…"
                        className="border-input bg-background placeholder:text-muted-foreground focus-visible:border-ring w-full rounded-lg border px-2.5 py-1.5 text-sm outline-none"
                    />
                </div>
            )}
        </div>
    );
}
