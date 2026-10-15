import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    CheckCircle2,
    ChevronDown,
    Circle,
    Facebook,
    History,
    Instagram,
    ListChecks,
    Linkedin,
    Lock,
    Maximize2,
    MessageSquareQuote,
    Music2,
    Pencil,
    Search,
    Share2,
    UploadCloud,
    User,
    XCircle,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { AssetPreviewCarousel } from '@/components/asset-preview-carousel';
import Heading from '@/components/heading';
import { HashtagInput, normalizeTag } from '@/components/hashtag-input';
import InputError from '@/components/input-error';
import {
    MentionTextarea,
    renderCommentBody,
    type MentionableUser,
} from '@/components/mention-textarea';
import { SocialPostPreview } from '@/components/social-post-preview';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import { store as storeAsset } from '@/routes/clients/assets';
import { show as showClient } from '@/routes/clients';
import {
    musicSearch as musicSearchRoute,
    update as updatePost,
    transition as transitionPost,
} from '@/routes/posts';
import postActivityLogs from '@/routes/posts/activity-logs';
import aiPosts from '@/routes/posts/ai';
import { store as storeComment } from '@/routes/posts/comments';
import { PageHeader } from '@/components/page';
import { StatusPill } from '@/components/status-pill';

type MusicTrackResult = { id: string; name: string; artist: string | null };

type AiCaptionSuggestionData = {
    caption: string;
    hashtags: string[];
    altText: string | null;
    firstComment: string | null;
};

type ChecklistItem = { key: string; label: string; passed: boolean };

type TargetData = {
    id: number;
    social_account_id: number;
    platform: string | null;
    handle: string | null;
    caption_limit: number | null;
    is_text_only_capable: boolean;
    scheduled_local_date: string | null;
    scheduled_local_time: string | null;
    scheduled_at_utc: string | null;
    status: string;
    status_label: string;
    external_post_id: string | null;
    error: string | null;
};

type ActivityLogData = {
    id: number;
    user_name: string | null;
    from_status: string | null;
    to_status: string;
    note: string | null;
    review_url: string | null;
    created_at: string | null;
};

type ApprovalData = {
    id: number;
    user_name: string | null;
    decision: string;
    comment: string | null;
    created_at: string | null;
};

type CommentData = {
    id: number;
    user_name: string | null;
    body: string;
    internal_only: boolean;
    mentioned_user_ids: number[];
    created_at: string | null;
};

type PostData = {
    id: number;
    client_id: number;
    campaign_id: number | null;
    campaign_name: string | null;
    status: string;
    status_label: string;
    approval_mode: string;
    master_caption: string | null;
    review_message: string | null;
    hashtags: string[];
    music: Record<string, unknown> | null;
    location: Record<string, unknown> | null;
    checklist: { items: ChecklistItem[]; passed: boolean };
    assets: {
        id: number;
        url: string | null;
        original_filename: string | null;
        type: string | null;
    }[];
    targets: TargetData[];
    activity_logs: ActivityLogData[];
    approvals: ApprovalData[];
    comments: CommentData[];
};

type TargetAccountData = {
    id: number;
    platform: string;
    handle: string;
    timezone: string;
};

type AvailableAssetData = {
    id: number;
    url: string | null;
    original_filename: string | null;
    type: string | null;
};

const platformIcon: Record<string, typeof Instagram> = {
    instagram: Instagram,
    tiktok: Music2,
    facebook: Facebook,
    linkedin: Linkedin,
};

/**
 * Reads Laravel's `XSRF-TOKEN` cookie so the plain `fetch()` call in `handleAssetUpload` below can
 * authenticate as a normal stateful request, mirroring the same helper on the client show page.
 */
function getXsrfTokenFromCookie(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function formatStatusLabel(status: string | null): string {
    if (!status) {
        return '—';
    }

    return status
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

function CommentEntry({ comment }: { comment: CommentData }) {
    const [open, setOpen] = useState(false);
    const body = comment.body ?? '';
    const plainBody = body.replace(/@\[([^\]]+)\]\(\d+\)/g, '@$1');
    const preview = plainBody.slice(0, 90);
    const isTruncated = plainBody.length > 90;

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className="border-border bg-card hover:border-primary/30 overflow-hidden rounded-lg border transition-colors"
        >
            <CollapsibleTrigger asChild>
                <button
                    type="button"
                    className="hover:bg-muted/30 flex w-full items-start gap-3 p-3.5 text-left text-sm"
                >
                    <span className="bg-muted mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full">
                        <User className="text-muted-foreground size-4" />
                    </span>
                    <div className="grid min-w-0 flex-1 gap-1.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">
                                {comment.user_name ?? 'Unknown'}
                            </span>
                            {comment.internal_only && (
                                <Badge variant="secondary">Internal</Badge>
                            )}
                            <span className="text-muted-foreground text-xs">
                                {comment.created_at}
                            </span>
                        </div>
                        {!open && (
                            <span className="text-muted-foreground min-w-0 truncate text-xs">
                                <MessageSquareQuote className="mr-1 inline size-3.5" />
                                {preview}
                                {isTruncated ? '…' : ''}
                            </span>
                        )}
                    </div>
                    <ChevronDown
                        className={cn(
                            'text-muted-foreground mt-1 size-4 shrink-0 transition-transform',
                            open && 'rotate-180',
                        )}
                    />
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent className="border-border/60 bg-muted/10 border-t py-3 pr-3.5 pl-[3.25rem] text-sm">
                <div className="flex items-start gap-2">
                    <MessageSquareQuote className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                    <p className="text-muted-foreground min-w-0 flex-1 overflow-hidden leading-relaxed break-words whitespace-pre-wrap">
                        {renderCommentBody(comment.body)}
                    </p>
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

function ActivityLogEntry({
    log,
    postId,
    canResend,
}: {
    log: ActivityLogData;
    postId: number;
    canResend: boolean;
}) {
    const [open, setOpen] = useState(false);
    const hasNote = Boolean(log.note && log.note.trim().length > 0);
    const hasReviewUrl = Boolean(log.review_url);
    const hasDetails = hasNote || hasReviewUrl;
    // Keep the collapsed preview short and non-breaking so a long note (or an embedded URL) never
    // overflows the row — the full text is only ever rendered once expanded below.
    const preview = hasNote ? (log.note as string).slice(0, 90) : null;
    const isTruncated = hasNote && (log.note as string).length > 90;

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className="border-border bg-card hover:border-primary/30 overflow-hidden rounded-lg border transition-colors"
        >
            <CollapsibleTrigger asChild>
                <button
                    type="button"
                    disabled={!hasDetails}
                    className={cn(
                        'flex w-full items-start gap-3 p-3.5 text-left text-sm',
                        hasDetails ? 'hover:bg-muted/30' : 'cursor-default',
                    )}
                >
                    <span className="bg-muted mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full">
                        <History className="text-muted-foreground size-4" />
                    </span>
                    <div className="grid min-w-0 flex-1 gap-1.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">
                                {log.user_name ?? 'System'}
                            </span>
                            <span className="flex items-center gap-1.5">
                                <StatusPill
                                    kind="post"
                                    value={log.from_status ?? ''}
                                    label={formatStatusLabel(log.from_status)}
                                />
                                <ArrowRight className="text-muted-foreground size-3.5 shrink-0" />
                                <StatusPill
                                    kind="post"
                                    value={log.to_status}
                                    label={formatStatusLabel(log.to_status)}
                                />
                            </span>
                            <span className="text-muted-foreground text-xs">
                                {log.created_at}
                            </span>
                        </div>
                        {hasNote && !open && (
                            <span className="text-muted-foreground min-w-0 truncate text-xs">
                                <MessageSquareQuote className="mr-1 inline size-3.5" />
                                {preview}
                                {isTruncated ? '…' : ''}
                            </span>
                        )}
                    </div>
                    {hasDetails && (
                        <ChevronDown
                            className={cn(
                                'text-muted-foreground mt-1 size-4 shrink-0 transition-transform',
                                open && 'rotate-180',
                            )}
                        />
                    )}
                </button>
            </CollapsibleTrigger>
            {hasDetails && (
                <CollapsibleContent className="border-border/60 bg-muted/10 border-t py-3 pr-3.5 pl-[3.25rem] text-sm">
                    <div className="grid gap-3">
                        {hasNote && (
                            <div className="flex items-start gap-2">
                                <MessageSquareQuote className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                                <p className="text-muted-foreground min-w-0 flex-1 overflow-hidden leading-relaxed break-words whitespace-pre-wrap">
                                    {log.note}
                                </p>
                            </div>
                        )}
                        {hasReviewUrl && (
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-muted-foreground min-w-0 flex-1 text-xs break-all">
                                    Review link:{' '}
                                    <a
                                        href={log.review_url as string}
                                        className="underline"
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        {log.review_url}
                                    </a>
                                </span>
                                {canResend && (
                                    <Form
                                        {...postActivityLogs.resendReviewEmail.form(
                                            [postId, log.id],
                                        )}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Resend email
                                            </Button>
                                        )}
                                    </Form>
                                )}
                            </div>
                        )}
                    </div>
                </CollapsibleContent>
            )}
        </Collapsible>
    );
}

export default function PostEdit({
    post,
    client,
    targetAccounts,
    availableAssets,
    allowedTransitions,
    can,
    hashtagSuggestions,
    mentionableUsers,
    ai,
}: {
    post: PostData;
    client: { id: number; name: string };
    targetAccounts: TargetAccountData[];
    availableAssets: AvailableAssetData[];
    allowedTransitions: { value: string; label: string }[];
    can: {
        update: boolean;
        comment: boolean;
        resend_review_email: boolean;
        create_asset: boolean;
    };
    hashtagSuggestions: string[];
    mentionableUsers: MentionableUser[];
    ai?: { enabled: boolean; canUse: boolean };
}) {
    const [caption, setCaption] = useState(post.master_caption ?? '');
    // const [reviewMessage, setReviewMessage] = useState(post.review_message ?? ''); // Message to client — commented out for now, may be re-added later.
    const [hashtags, setHashtags] = useState<string[]>(post.hashtags ?? []);
    const [selectedAssetIds, setSelectedAssetIds] = useState<number[]>(
        post.assets.map((asset) => asset.id),
    );
    // Assets uploaded inline from this editor (via the "Upload asset" widget below) are merged
    // into the picker list on the fly, without a full page reload, so the rest of the in-progress
    // form (caption, hashtags, schedule rows, etc.) isn't lost.
    const [assets, setAssets] = useState<AvailableAssetData[]>(availableAssets);
    const [isUploadingAsset, setIsUploadingAsset] = useState(false);
    const assetFileInputRef = useRef<HTMLInputElement>(null);
    const [targetRows, setTargetRows] = useState<
        Record<number, { date: string; time: string }>
    >(
        Object.fromEntries(
            post.targets.map((target) => [
                target.social_account_id,
                {
                    date: target.scheduled_local_date ?? '',
                    time: target.scheduled_local_time ?? '',
                },
            ]),
        ),
    );
    const [musicId, setMusicId] = useState((post.music?.id as string) ?? '');
    const [musicName, setMusicName] = useState(
        (post.music?.name as string) ?? '',
    );
    const [musicQuery, setMusicQuery] = useState('');
    const [musicResults, setMusicResults] = useState<MusicTrackResult[]>([]);
    const [musicSearching, setMusicSearching] = useState(false);
    const [musicSearchError, setMusicSearchError] = useState<string | null>(
        null,
    );
    // Step 1.9.5 — a distinguishable error_code (e.g. "meta_not_connected") lets this render an
    // actionable "Connect Instagram" link instead of bare error text.
    const [musicSearchErrorCode, setMusicSearchErrorCode] = useState<
        string | null
    >(null);
    const [musicPlatform, setMusicPlatform] = useState<string | null>(null);
    const [locationName, setLocationName] = useState(
        (post.location?.name as string) ?? '',
    );

    // Step 2.4 — "Suggest with AI" caption panel. Never overwrites the caption/hashtags fields
    // without an explicit "Apply" click, and the result still has to go through the normal Save
    // action below before anything is actually persisted.
    const showAiPanel = Boolean(ai?.enabled && ai?.canUse);
    const [aiLoading, setAiLoading] = useState(false);
    const [aiError, setAiError] = useState<string | null>(null);
    const [aiSuggestions, setAiSuggestions] = useState<
        AiCaptionSuggestionData[]
    >([]);
    // Step 2.6 — per-suggestion platform policy warnings (e.g. link/hashtag rules), surfaced
    // next to each card so a human can decide instead of the AI silently rewriting anything.
    const [aiPolicyWarnings, setAiPolicyWarnings] = useState<string[][]>([]);
    // Once a suggestion has been applied, the card list collapses to a small summary so it
    // doesn't keep taking up space in the editor — the user can still expand it back if they
    // want to compare/apply a different suggestion.
    const [appliedSuggestionIndex, setAppliedSuggestionIndex] = useState<
        number | null
    >(null);
    const [suggestionsExpanded, setSuggestionsExpanded] = useState(true);

    const fetchAiSuggestions = async () => {
        setAiError(null);
        setAiLoading(true);

        try {
            const response = await fetch(aiPosts.captions.url(post.id), {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(
                    typeof data?.message === 'string'
                        ? data.message
                        : 'Unable to fetch AI suggestions.',
                );
            }

            setAiSuggestions(
                Array.isArray(data?.suggestions) ? data.suggestions : [],
            );
            setAiPolicyWarnings(
                Array.isArray(data?.policyWarnings) ? data.policyWarnings : [],
            );
            setAppliedSuggestionIndex(null);
            setSuggestionsExpanded(true);
        } catch (e) {
            setAiError(
                e instanceof Error
                    ? e.message
                    : 'Unable to fetch AI suggestions.',
            );
        } finally {
            setAiLoading(false);
        }
    };

    const applyAiSuggestion = (
        suggestion: AiCaptionSuggestionData,
        index: number,
    ) => {
        if (
            caption.trim() &&
            !window.confirm(
                'Replace the current caption with this AI suggestion?',
            )
        ) {
            return;
        }

        setCaption(suggestion.caption);
        // AI suggestions come back as "#demo"; the hashtag field stores bare tags.
        setHashtags([
            ...new Set(
                (suggestion.hashtags ?? []).map(normalizeTag).filter(Boolean),
            ),
        ]);
        setAppliedSuggestionIndex(index);
        setSuggestionsExpanded(false);
    };

    const toggleTargetAccount = (accountId: number) => {
        setTargetRows((current) => {
            if (accountId in current) {
                const next = { ...current };
                delete next[accountId];

                return next;
            }

            return { ...current, [accountId]: { date: '', time: '' } };
        });
    };

    const toggleAsset = (assetId: number) => {
        setSelectedAssetIds((current) =>
            current.includes(assetId)
                ? current.filter((id) => id !== assetId)
                : [...current, assetId],
        );
    };

    // Lets the user upload a brand-new asset without leaving the post editor (previously they had
    // to flick over to the client's Assets tab first). Posts straight to the same asset-store
    // endpoint the client page uses, but as a JSON request so the response can be merged into the
    // in-memory asset list instead of redirecting away and losing any unsaved editor changes.
    const handleAssetUpload = async (file: File) => {
        setIsUploadingAsset(true);

        try {
            const formData = new FormData();
            formData.append('source', 'upload');
            formData.append('file', file);
            if (post.campaign_id) {
                formData.append('campaign_id', String(post.campaign_id));
            }

            const response = await fetch(storeAsset.url(client.id), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': getXsrfTokenFromCookie(),
                },
                body: formData,
            });

            const data = await response.json();

            if (!response.ok || !data.asset) {
                const message =
                    typeof data?.message === 'string'
                        ? data.message
                        : 'Unable to upload the asset.';
                toast.error(message);
                return;
            }

            const asset: AvailableAssetData = data.asset;
            setAssets((current) => [asset, ...current]);
            setSelectedAssetIds((current) => [...current, asset.id]);
            toast.success('Asset uploaded and added to this post.');
        } catch {
            toast.error('Unable to upload the asset.');
        } finally {
            setIsUploadingAsset(false);
            if (assetFileInputRef.current) {
                assetFileInputRef.current.value = '';
            }
        }
    };

    const selectedAssets = assets.filter((asset) =>
        selectedAssetIds.includes(asset.id),
    );

    const targetEntries = Object.entries(targetRows);
    const [activeTab, setActiveTab] = useState('editor');

    // Step 1.8.4 — the music search field only makes sense (and only has a real vendor lookup,
    // per SearchMusicAction/UploadPostMusicProvider) for Instagram/TikTok targets, so it's shown
    // only when at least one currently selected target account is on one of those platforms.
    const musicPlatforms = Array.from(
        new Set(
            targetEntries
                .map(
                    ([accountIdStr]) =>
                        targetAccounts.find(
                            (a) => a.id === Number(accountIdStr),
                        )?.platform,
                )
                .filter(
                    (platform): platform is string =>
                        platform === 'instagram' || platform === 'tiktok',
                ),
        ),
    );
    const activeMusicPlatform =
        musicPlatform && musicPlatforms.includes(musicPlatform)
            ? musicPlatform
            : (musicPlatforms[0] ?? null);

    const handleMusicSearch = async () => {
        if (!activeMusicPlatform || musicQuery.trim() === '') {
            return;
        }

        setMusicSearching(true);
        setMusicSearchError(null);
        setMusicSearchErrorCode(null);

        try {
            const response = await fetch(
                musicSearchRoute.url(post.id, {
                    query: { query: musicQuery, platform: activeMusicPlatform },
                }),
                { headers: { Accept: 'application/json' } },
            );
            const data = await response.json();

            if (!response.ok) {
                setMusicSearchError('Unable to search music right now.');
                setMusicResults([]);
                return;
            }

            if (data.error) {
                setMusicSearchError(data.error);
                setMusicSearchErrorCode(data.error_code ?? null);
                setMusicResults([]);
                return;
            }

            setMusicResults(data.tracks ?? []);
        } catch {
            setMusicSearchError('Unable to search music right now.');
            setMusicResults([]);
        } finally {
            setMusicSearching(false);
        }
    };

    const selectMusicTrack = (track: MusicTrackResult) => {
        setMusicId(track.id);
        setMusicName(track.name);
        setMusicResults([]);
        setMusicQuery('');
    };

    const clearMusicTrack = () => {
        setMusicId('');
        setMusicName('');
    };

    // Live preview targets: the accounts currently selected in the form (or, read-only, the
    // post's saved targets), each paired with its platform/handle so `SocialPostPreview` can
    // render the right mockup. Kept separate from `targetEntries` (which drives the schedule
    // rows) since the preview only needs platform/handle, not date/time.
    const previewTargets = can.update
        ? targetEntries
              .map(([accountIdStr]) =>
                  targetAccounts.find((a) => a.id === Number(accountIdStr)),
              )
              .filter((a): a is TargetAccountData => Boolean(a))
              .map((a) => ({
                  id: a.id,
                  platform: a.platform,
                  handle: a.handle,
              }))
        : post.targets.map((t) => ({
              id: t.social_account_id,
              platform: t.platform ?? '',
              handle: t.handle ?? '',
          }));

    const [previewTargetId, setPreviewTargetId] = useState<
        number | string | null
    >(previewTargets[0]?.id ?? null);
    const activePreviewTarget =
        previewTargets.find((t) => t.id === previewTargetId) ??
        previewTargets[0] ??
        null;
    const previewAssets = can.update ? selectedAssets : post.assets;
    const [isPreviewFullscreen, setIsPreviewFullscreen] = useState(false);

    return (
        <>
            <Head title={`Edit post — ${client.name}`} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-6 md:px-8 md:py-9">
                <div className="flex flex-col gap-4">
                    <Link
                        href={showClient(client.id)}
                        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm"
                    >
                        <ArrowLeft className="size-4" />
                        Back to {client.name}
                    </Link>
                </div>

                <PageHeader
                    eyebrow={
                        post.campaign_name
                            ? `Post editor — ${post.campaign_name}`
                            : 'Post editor'
                    }
                    title={client.name}
                    meta={
                        <StatusPill
                            kind="post"
                            value={post.status}
                            label={post.status_label}
                        />
                    }
                />

                <Tabs
                    value={activeTab}
                    onValueChange={setActiveTab}
                    className="gap-4"
                >
                    <TabsList className="bg-muted/60 h-auto w-full flex-wrap justify-start gap-1 rounded-2xl p-1">
                        <TabsTrigger value="editor">
                            <Pencil />
                            Editor
                        </TabsTrigger>
                        <TabsTrigger value="checklist">
                            <ListChecks />
                            Checklist &amp; status
                        </TabsTrigger>
                        <TabsTrigger value="comments">
                            <MessageSquareQuote />
                            Comments
                            {post.comments.length > 0 && (
                                <Badge variant="secondary" className="ml-1">
                                    {post.comments.length}
                                </Badge>
                            )}
                        </TabsTrigger>
                        <TabsTrigger value="activity">
                            <History />
                            Activity log
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent
                        value="editor"
                        className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start"
                    >
                        <div className="grid gap-4">
                            {!can.update && (
                                <div className="border-border bg-card grid gap-4 rounded-lg border p-4">
                                    {(post.status === 'scheduled' ||
                                        post.status === 'published') && (
                                        <div className="border-border bg-muted/30 text-muted-foreground flex items-center gap-2 rounded-md border p-3 text-sm">
                                            <Lock className="size-4 shrink-0" />
                                            This post is{' '}
                                            {post.status_label.toLowerCase()}{' '}
                                            and is locked for editing. Move it
                                            to a different status to make
                                            changes.
                                        </div>
                                    )}
                                    <div className="grid gap-1">
                                        <Label>Master caption</Label>
                                        <p className="border-border bg-muted/20 rounded-md border p-3 text-sm whitespace-pre-wrap">
                                            {post.master_caption || (
                                                <span className="text-muted-foreground">
                                                    No caption yet.
                                                </span>
                                            )}
                                        </p>
                                    </div>

                                    <div className="grid gap-1">
                                        <Label>Hashtags</Label>
                                        {post.hashtags.length > 0 ? (
                                            <div className="flex flex-wrap gap-1.5">
                                                {post.hashtags.map((tag) => (
                                                    <Badge
                                                        key={tag}
                                                        variant="secondary"
                                                    >
                                                        {tag}
                                                    </Badge>
                                                ))}
                                            </div>
                                        ) : (
                                            <p className="text-muted-foreground text-sm">
                                                No hashtags.
                                            </p>
                                        )}
                                    </div>

                                    <div className="grid gap-2">
                                        <Label>Target accounts</Label>
                                        {post.targets.length > 0 ? (
                                            <div className="grid gap-2">
                                                {post.targets.map((target) => {
                                                    const AccountIcon =
                                                        platformIcon[
                                                            target.platform ??
                                                                ''
                                                        ] ?? Share2;

                                                    return (
                                                        <div
                                                            key={target.id}
                                                            className="border-border bg-muted/20 flex flex-wrap items-center gap-2 rounded-md border p-2.5 text-sm"
                                                        >
                                                            <AccountIcon className="text-primary size-4" />
                                                            <span className="font-medium">
                                                                {target.handle ??
                                                                    '—'}
                                                            </span>
                                                            <span className="text-muted-foreground text-xs">
                                                                {target.scheduled_local_date ??
                                                                    '—'}{' '}
                                                                {target.scheduled_local_time ??
                                                                    ''}
                                                            </span>
                                                            {target.status !==
                                                                'pending' && (
                                                                <Badge
                                                                    variant={
                                                                        target.status ===
                                                                        'published'
                                                                            ? 'success'
                                                                            : 'destructive'
                                                                    }
                                                                >
                                                                    {
                                                                        target.status_label
                                                                    }
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        ) : (
                                            <p className="text-muted-foreground text-sm">
                                                No target accounts selected.
                                            </p>
                                        )}
                                    </div>

                                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        <div className="grid gap-1">
                                            <Label>Music</Label>
                                            <p className="text-muted-foreground text-sm">
                                                {(post.music?.name as string) ||
                                                    'Not set'}
                                            </p>
                                        </div>
                                        <div className="grid gap-1">
                                            <Label>Location</Label>
                                            <p className="text-muted-foreground text-sm">
                                                {(post.location
                                                    ?.name as string) ||
                                                    'Not set'}
                                            </p>
                                        </div>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label>Media</Label>
                                        {post.assets.length > 0 ? (
                                            <AssetPreviewCarousel
                                                assets={post.assets}
                                            />
                                        ) : (
                                            <p className="text-muted-foreground text-sm">
                                                No media attached.
                                            </p>
                                        )}
                                    </div>
                                </div>
                            )}
                            {can.update && (
                                <Form
                                    {...updatePost.form(post.id)}
                                    className="border-border bg-card grid gap-4 rounded-lg border p-4"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <div className="grid gap-1">
                                                <div className="flex items-center justify-between">
                                                    <Label htmlFor="master_caption">
                                                        Master caption
                                                    </Label>
                                                    {showAiPanel && (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="secondary"
                                                            onClick={
                                                                fetchAiSuggestions
                                                            }
                                                            disabled={aiLoading}
                                                        >
                                                            {aiLoading
                                                                ? 'Suggesting…'
                                                                : 'Suggest with AI'}
                                                        </Button>
                                                    )}
                                                </div>
                                                <textarea
                                                    id="master_caption"
                                                    name="master_caption"
                                                    rows={4}
                                                    value={caption}
                                                    onChange={(e) =>
                                                        setCaption(
                                                            e.target.value,
                                                        )
                                                    }
                                                    className="border-input dark:bg-input/30 focus-visible:border-ring focus-visible:ring-ring/50 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                                                />
                                                <InputError
                                                    message={
                                                        errors.master_caption
                                                    }
                                                />
                                                {showAiPanel && aiError && (
                                                    <p className="text-destructive text-xs">
                                                        {aiError}
                                                    </p>
                                                )}
                                                {showAiPanel &&
                                                    aiSuggestions.length >
                                                        0 && (
                                                        <div className="border-border bg-muted/20 mt-2 grid gap-2 rounded-md border p-3">
                                                            <div className="flex items-center justify-between">
                                                                <span className="text-muted-foreground text-xs font-medium">
                                                                    AI
                                                                    suggestions
                                                                    {appliedSuggestionIndex !==
                                                                        null &&
                                                                        ' · applied ✓'}
                                                                </span>
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    className="h-6 px-2 text-xs"
                                                                    onClick={() =>
                                                                        setSuggestionsExpanded(
                                                                            (
                                                                                current,
                                                                            ) =>
                                                                                !current,
                                                                        )
                                                                    }
                                                                >
                                                                    {suggestionsExpanded
                                                                        ? 'Collapse'
                                                                        : 'Show suggestions'}
                                                                </Button>
                                                            </div>
                                                            {!suggestionsExpanded &&
                                                                appliedSuggestionIndex !==
                                                                    null && (
                                                                    <p className="text-muted-foreground truncate text-xs">
                                                                        Using:{' '}
                                                                        {
                                                                            aiSuggestions[
                                                                                appliedSuggestionIndex
                                                                            ]
                                                                                ?.caption
                                                                        }
                                                                    </p>
                                                                )}
                                                            {suggestionsExpanded &&
                                                                aiSuggestions.map(
                                                                    (
                                                                        suggestion,
                                                                        i,
                                                                    ) => (
                                                                        <div
                                                                            key={
                                                                                i
                                                                            }
                                                                            className={`bg-background grid gap-2 rounded-md border p-3 ${
                                                                                appliedSuggestionIndex ===
                                                                                i
                                                                                    ? 'border-primary'
                                                                                    : 'border-border'
                                                                            }`}
                                                                        >
                                                                            <p className="text-sm whitespace-pre-wrap">
                                                                                {
                                                                                    suggestion.caption
                                                                                }
                                                                            </p>
                                                                            {suggestion
                                                                                .hashtags
                                                                                .length >
                                                                                0 && (
                                                                                <div className="flex flex-wrap gap-1">
                                                                                    {suggestion.hashtags.map(
                                                                                        (
                                                                                            tag,
                                                                                            ti,
                                                                                        ) => (
                                                                                            <Badge
                                                                                                key={
                                                                                                    ti
                                                                                                }
                                                                                                variant="outline"
                                                                                            >
                                                                                                #
                                                                                                {
                                                                                                    tag
                                                                                                }
                                                                                            </Badge>
                                                                                        ),
                                                                                    )}
                                                                                </div>
                                                                            )}
                                                                            {suggestion.altText && (
                                                                                <p className="text-muted-foreground text-xs">
                                                                                    Alt
                                                                                    text:{' '}
                                                                                    {
                                                                                        suggestion.altText
                                                                                    }
                                                                                </p>
                                                                            )}
                                                                            {suggestion.firstComment && (
                                                                                <p className="text-muted-foreground text-xs">
                                                                                    First
                                                                                    comment:{' '}
                                                                                    {
                                                                                        suggestion.firstComment
                                                                                    }
                                                                                </p>
                                                                            )}
                                                                            {(
                                                                                aiPolicyWarnings[
                                                                                    i
                                                                                ] ??
                                                                                []
                                                                            )
                                                                                .length >
                                                                                0 && (
                                                                                <ul className="grid gap-0.5">
                                                                                    {aiPolicyWarnings[
                                                                                        i
                                                                                    ].map(
                                                                                        (
                                                                                            warning,
                                                                                            wi,
                                                                                        ) => (
                                                                                            <li
                                                                                                key={
                                                                                                    wi
                                                                                                }
                                                                                                className="text-xs text-amber-600 dark:text-amber-500"
                                                                                            >
                                                                                                ⚠{' '}
                                                                                                {
                                                                                                    warning
                                                                                                }
                                                                                            </li>
                                                                                        ),
                                                                                    )}
                                                                                </ul>
                                                                            )}
                                                                            <div>
                                                                                <Button
                                                                                    type="button"
                                                                                    size="sm"
                                                                                    onClick={() =>
                                                                                        applyAiSuggestion(
                                                                                            suggestion,
                                                                                            i,
                                                                                        )
                                                                                    }
                                                                                >
                                                                                    {appliedSuggestionIndex ===
                                                                                    i
                                                                                        ? 'Applied ✓'
                                                                                        : 'Apply'}
                                                                                </Button>
                                                                            </div>
                                                                        </div>
                                                                    ),
                                                                )}
                                                        </div>
                                                    )}
                                            </div>

                                            {/* Message to client — commented out for now, may be re-added later.
                                <div className="grid gap-1">
                                    <Label htmlFor="review_message">Message to client</Label>
                                    <textarea
                                        id="review_message"
                                        name="review_message"
                                        rows={3}
                                        value={reviewMessage}
                                        onChange={(e) => setReviewMessage(e.target.value)}
                                        placeholder="Optional note shown to the client alongside the review request email..."
                                        className="border-input dark:bg-input/30 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                                    />
                                    <InputError message={errors.review_message} />
                                </div>
                                */}

                                            <div className="grid gap-1">
                                                <Label htmlFor="hashtags_text">
                                                    Hashtags
                                                </Label>
                                                <HashtagInput
                                                    id="hashtags_text"
                                                    value={hashtags}
                                                    onChange={setHashtags}
                                                    suggestions={
                                                        hashtagSuggestions
                                                    }
                                                    placeholder="Start typing a hashtag…"
                                                />
                                                {hashtags.map((tag, i) => (
                                                    <input
                                                        key={i}
                                                        type="hidden"
                                                        name={`hashtags[${i}]`}
                                                        value={tag}
                                                    />
                                                ))}
                                            </div>

                                            <div className="grid gap-2">
                                                <Label>Target accounts</Label>
                                                <p className="text-muted-foreground text-xs">
                                                    Accounts filtered to{' '}
                                                    {client.name}. Each target
                                                    keeps its own local schedule
                                                    — two targets in different
                                                    timezones never collapse to
                                                    one shared time.
                                                </p>
                                                <div className="flex flex-wrap gap-2">
                                                    {targetAccounts.map(
                                                        (account) => {
                                                            const AccountIcon =
                                                                platformIcon[
                                                                    account
                                                                        .platform
                                                                ] ?? Share2;
                                                            const selected =
                                                                account.id in
                                                                targetRows;

                                                            return (
                                                                <button
                                                                    key={
                                                                        account.id
                                                                    }
                                                                    type="button"
                                                                    onClick={() =>
                                                                        toggleTargetAccount(
                                                                            account.id,
                                                                        )
                                                                    }
                                                                    className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors ${
                                                                        selected
                                                                            ? 'border-primary bg-primary/10 text-primary'
                                                                            : 'border-border bg-muted/50 text-foreground'
                                                                    }`}
                                                                >
                                                                    <AccountIcon className="size-3.5" />
                                                                    {
                                                                        account.handle
                                                                    }{' '}
                                                                    ·{' '}
                                                                    {
                                                                        account.timezone
                                                                    }
                                                                </button>
                                                            );
                                                        },
                                                    )}
                                                </div>

                                                {targetEntries.length === 0 && (
                                                    <p className="text-muted-foreground text-sm">
                                                        No target accounts
                                                        selected.
                                                    </p>
                                                )}

                                                <div className="grid gap-2">
                                                    {targetEntries.map(
                                                        (
                                                            [accountIdStr, row],
                                                            i,
                                                        ) => {
                                                            const accountId =
                                                                Number(
                                                                    accountIdStr,
                                                                );
                                                            const account =
                                                                targetAccounts.find(
                                                                    (a) =>
                                                                        a.id ===
                                                                        accountId,
                                                                );
                                                            const AccountIcon =
                                                                platformIcon[
                                                                    account?.platform ??
                                                                        ''
                                                                ] ?? Share2;
                                                            const limit =
                                                                post.targets.find(
                                                                    (t) =>
                                                                        t.social_account_id ===
                                                                        accountId,
                                                                )?.caption_limit;

                                                            return (
                                                                <div
                                                                    key={
                                                                        accountId
                                                                    }
                                                                    className="border-border bg-muted/20 grid grid-cols-1 gap-2 rounded-md border p-3 sm:grid-cols-4 sm:items-end"
                                                                >
                                                                    <div className="flex items-center gap-2 text-sm sm:col-span-1">
                                                                        <AccountIcon className="text-primary size-4" />
                                                                        <div className="flex flex-col">
                                                                            <span>
                                                                                {account?.handle ??
                                                                                    accountId}
                                                                            </span>
                                                                            <span className="text-muted-foreground text-xs tabular-nums">
                                                                                {
                                                                                    caption.length
                                                                                }
                                                                                {limit
                                                                                    ? ` / ${limit}`
                                                                                    : ''}{' '}
                                                                                chars
                                                                            </span>
                                                                        </div>
                                                                    </div>
                                                                    <div className="grid gap-1">
                                                                        <Label
                                                                            htmlFor={`target-date-${accountId}`}
                                                                        >
                                                                            Local
                                                                            date
                                                                        </Label>
                                                                        <Input
                                                                            id={`target-date-${accountId}`}
                                                                            type="date"
                                                                            value={
                                                                                row.date
                                                                            }
                                                                            onChange={(
                                                                                e,
                                                                            ) =>
                                                                                setTargetRows(
                                                                                    (
                                                                                        current,
                                                                                    ) => ({
                                                                                        ...current,
                                                                                        [accountId]:
                                                                                            {
                                                                                                ...current[
                                                                                                    accountId
                                                                                                ],
                                                                                                date: e
                                                                                                    .target
                                                                                                    .value,
                                                                                            },
                                                                                    }),
                                                                                )
                                                                            }
                                                                        />
                                                                    </div>
                                                                    <div className="grid gap-1">
                                                                        <Label
                                                                            htmlFor={`target-time-${accountId}`}
                                                                        >
                                                                            Local
                                                                            time
                                                                        </Label>
                                                                        <Input
                                                                            id={`target-time-${accountId}`}
                                                                            type="time"
                                                                            value={
                                                                                row.time
                                                                            }
                                                                            onChange={(
                                                                                e,
                                                                            ) =>
                                                                                setTargetRows(
                                                                                    (
                                                                                        current,
                                                                                    ) => ({
                                                                                        ...current,
                                                                                        [accountId]:
                                                                                            {
                                                                                                ...current[
                                                                                                    accountId
                                                                                                ],
                                                                                                time: e
                                                                                                    .target
                                                                                                    .value,
                                                                                            },
                                                                                    }),
                                                                                )
                                                                            }
                                                                        />
                                                                    </div>
                                                                    <input
                                                                        type="hidden"
                                                                        name={`targets[${i}][social_account_id]`}
                                                                        value={
                                                                            accountId
                                                                        }
                                                                    />
                                                                    <input
                                                                        type="hidden"
                                                                        name={`targets[${i}][scheduled_local_date]`}
                                                                        value={
                                                                            row.date
                                                                        }
                                                                    />
                                                                    <input
                                                                        type="hidden"
                                                                        name={`targets[${i}][scheduled_local_time]`}
                                                                        value={
                                                                            row.time
                                                                        }
                                                                    />
                                                                </div>
                                                            );
                                                        },
                                                    )}
                                                </div>
                                                <InputError
                                                    message={
                                                        errors[
                                                            'targets.0.social_account_id'
                                                        ]
                                                    }
                                                />
                                            </div>

                                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                                <div className="grid gap-1">
                                                    <Label htmlFor="music_search">
                                                        Music
                                                    </Label>
                                                    {musicPlatforms.length >
                                                    0 ? (
                                                        <div className="grid gap-2">
                                                            {musicPlatforms.length >
                                                                1 && (
                                                                <div className="flex gap-1.5">
                                                                    {musicPlatforms.map(
                                                                        (
                                                                            platform,
                                                                        ) => (
                                                                            <button
                                                                                key={
                                                                                    platform
                                                                                }
                                                                                type="button"
                                                                                onClick={() =>
                                                                                    setMusicPlatform(
                                                                                        platform,
                                                                                    )
                                                                                }
                                                                                className={cn(
                                                                                    'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors',
                                                                                    activeMusicPlatform ===
                                                                                        platform
                                                                                        ? 'border-primary bg-primary/10 text-primary'
                                                                                        : 'border-border bg-muted/50 text-foreground',
                                                                                )}
                                                                            >
                                                                                {platform ===
                                                                                'instagram'
                                                                                    ? 'Instagram'
                                                                                    : 'TikTok'}
                                                                            </button>
                                                                        ),
                                                                    )}
                                                                </div>
                                                            )}
                                                            <div className="flex gap-2">
                                                                <Input
                                                                    id="music_search"
                                                                    value={
                                                                        musicQuery
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setMusicQuery(
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    onKeyDown={(
                                                                        e,
                                                                    ) => {
                                                                        if (
                                                                            e.key ===
                                                                            'Enter'
                                                                        ) {
                                                                            e.preventDefault();
                                                                            void handleMusicSearch();
                                                                        }
                                                                    }}
                                                                    placeholder="Search a track / sound…"
                                                                />
                                                                <Button
                                                                    type="button"
                                                                    variant="outline"
                                                                    onClick={
                                                                        handleMusicSearch
                                                                    }
                                                                    disabled={
                                                                        musicSearching ||
                                                                        musicQuery.trim() ===
                                                                            ''
                                                                    }
                                                                >
                                                                    <Search className="size-4" />
                                                                    Search
                                                                </Button>
                                                            </div>
                                                            {musicSearchError && (
                                                                <p className="text-destructive text-sm">
                                                                    {
                                                                        musicSearchError
                                                                    }
                                                                    {musicSearchErrorCode ===
                                                                        'meta_not_connected' &&
                                                                        (() => {
                                                                            const instagramAccountId =
                                                                                targetEntries
                                                                                    .map(
                                                                                        ([
                                                                                            accountIdStr,
                                                                                        ]) =>
                                                                                            targetAccounts.find(
                                                                                                (
                                                                                                    a,
                                                                                                ) =>
                                                                                                    a.id ===
                                                                                                    Number(
                                                                                                        accountIdStr,
                                                                                                    ),
                                                                                            ),
                                                                                    )
                                                                                    .find(
                                                                                        (
                                                                                            a,
                                                                                        ) =>
                                                                                            a?.platform ===
                                                                                            'instagram',
                                                                                    )?.id;

                                                                            return instagramAccountId ? (
                                                                                <>
                                                                                    {' '}
                                                                                    <a
                                                                                        href={`/clients/${post.client_id}/social-accounts/${instagramAccountId}/facebook/connect`}
                                                                                        className="underline"
                                                                                    >
                                                                                        Connect
                                                                                        Instagram
                                                                                        for
                                                                                        music
                                                                                        search
                                                                                    </a>
                                                                                </>
                                                                            ) : null;
                                                                        })()}
                                                                </p>
                                                            )}
                                                            {musicResults.length >
                                                                0 && (
                                                                <ul className="border-border grid gap-1 rounded-md border p-1">
                                                                    {musicResults.map(
                                                                        (
                                                                            track,
                                                                        ) => (
                                                                            <li
                                                                                key={
                                                                                    track.id
                                                                                }
                                                                            >
                                                                                <button
                                                                                    type="button"
                                                                                    onClick={() =>
                                                                                        selectMusicTrack(
                                                                                            track,
                                                                                        )
                                                                                    }
                                                                                    className="hover:bg-muted/50 flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm"
                                                                                >
                                                                                    <Music2 className="text-muted-foreground size-3.5 shrink-0" />
                                                                                    <span className="min-w-0 flex-1 truncate">
                                                                                        {
                                                                                            track.name
                                                                                        }
                                                                                        {track.artist
                                                                                            ? ` — ${track.artist}`
                                                                                            : ''}
                                                                                    </span>
                                                                                </button>
                                                                            </li>
                                                                        ),
                                                                    )}
                                                                </ul>
                                                            )}
                                                            {musicName ? (
                                                                <div className="border-border bg-muted/20 flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm">
                                                                    <span className="flex min-w-0 items-center gap-1.5 truncate">
                                                                        <Music2 className="text-primary size-3.5 shrink-0" />
                                                                        {
                                                                            musicName
                                                                        }
                                                                    </span>
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        onClick={
                                                                            clearMusicTrack
                                                                        }
                                                                    >
                                                                        Clear
                                                                    </Button>
                                                                </div>
                                                            ) : (
                                                                <p className="text-muted-foreground text-xs">
                                                                    No track
                                                                    selected.
                                                                </p>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <p className="text-muted-foreground text-sm">
                                                            Select an Instagram
                                                            or TikTok target
                                                            account to search
                                                            for music.
                                                        </p>
                                                    )}
                                                    <input
                                                        type="hidden"
                                                        name="music[id]"
                                                        value={musicId}
                                                    />
                                                    <input
                                                        type="hidden"
                                                        name="music[name]"
                                                        value={musicName}
                                                    />
                                                </div>
                                                <div className="grid gap-1">
                                                    <Label htmlFor="location_name">
                                                        Location
                                                    </Label>
                                                    <Input
                                                        id="location_name"
                                                        name="location[name]"
                                                        value={locationName}
                                                        onChange={(e) =>
                                                            setLocationName(
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="Location name"
                                                    />
                                                </div>
                                            </div>

                                            <div className="grid gap-2">
                                                <Label>Media</Label>
                                                {can.create_asset && (
                                                    <div className="flex items-center gap-2">
                                                        <input
                                                            ref={
                                                                assetFileInputRef
                                                            }
                                                            type="file"
                                                            id="new_asset_file"
                                                            className="hidden"
                                                            accept="image/*,video/*,.pdf,.doc,.docx,.ppt,.pptx,.zip"
                                                            onChange={(e) => {
                                                                const file =
                                                                    e.target
                                                                        .files?.[0];
                                                                if (file) {
                                                                    void handleAssetUpload(
                                                                        file,
                                                                    );
                                                                }
                                                            }}
                                                        />
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            disabled={
                                                                isUploadingAsset
                                                            }
                                                            onClick={() =>
                                                                assetFileInputRef.current?.click()
                                                            }
                                                        >
                                                            <UploadCloud className="size-4" />
                                                            {isUploadingAsset
                                                                ? 'Uploading…'
                                                                : 'Upload asset'}
                                                        </Button>
                                                    </div>
                                                )}
                                                {assets.length === 0 ? (
                                                    <p className="text-muted-foreground text-sm">
                                                        No assets on this client
                                                        yet — upload one above.
                                                    </p>
                                                ) : (
                                                    <>
                                                        <div className="flex flex-wrap gap-2">
                                                            {assets.map(
                                                                (asset) => {
                                                                    const selected =
                                                                        selectedAssetIds.includes(
                                                                            asset.id,
                                                                        );

                                                                    return (
                                                                        <button
                                                                            key={
                                                                                asset.id
                                                                            }
                                                                            type="button"
                                                                            onClick={() =>
                                                                                toggleAsset(
                                                                                    asset.id,
                                                                                )
                                                                            }
                                                                            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors ${
                                                                                selected
                                                                                    ? 'border-primary bg-primary/10 text-primary'
                                                                                    : 'border-border bg-muted/50 text-foreground'
                                                                            }`}
                                                                        >
                                                                            {asset.original_filename ??
                                                                                `Asset #${asset.id}`}
                                                                        </button>
                                                                    );
                                                                },
                                                            )}
                                                        </div>
                                                        {selectedAssets.length >
                                                        0 ? (
                                                            <AssetPreviewCarousel
                                                                assets={
                                                                    selectedAssets
                                                                }
                                                            />
                                                        ) : (
                                                            <p className="text-muted-foreground text-sm">
                                                                No media
                                                                selected.
                                                            </p>
                                                        )}
                                                    </>
                                                )}
                                                {selectedAssetIds.map(
                                                    (id, i) => (
                                                        <input
                                                            key={id}
                                                            type="hidden"
                                                            name={`asset_ids[${i}]`}
                                                            value={id}
                                                        />
                                                    ),
                                                )}
                                            </div>

                                            <div>
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    Save changes
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            )}
                        </div>

                        <div className="grid gap-3 lg:sticky lg:top-4">
                            <div className="border-border bg-card rounded-lg border p-4">
                                <div className="flex items-start justify-between gap-2">
                                    <Heading
                                        title="Live preview"
                                        description="A quick look at how the post will render — not pixel-perfect"
                                    />
                                    {activePreviewTarget && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            className="shrink-0"
                                            onClick={() =>
                                                setIsPreviewFullscreen(true)
                                            }
                                            aria-label="View live preview in fullscreen"
                                        >
                                            <Maximize2 className="size-4" />
                                        </Button>
                                    )}
                                </div>
                                {previewTargets.length > 1 && (
                                    <div className="mt-3 flex flex-wrap gap-1.5">
                                        {previewTargets.map((t) => {
                                            const Icon =
                                                platformIcon[t.platform] ??
                                                Share2;
                                            const selected =
                                                t.id ===
                                                activePreviewTarget?.id;

                                            return (
                                                <button
                                                    key={t.id}
                                                    type="button"
                                                    onClick={() =>
                                                        setPreviewTargetId(t.id)
                                                    }
                                                    className={cn(
                                                        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors',
                                                        selected
                                                            ? 'border-primary bg-primary/10 text-primary'
                                                            : 'border-border bg-muted/50 text-foreground',
                                                    )}
                                                >
                                                    <Icon className="size-3.5" />
                                                    {t.handle}
                                                </button>
                                            );
                                        })}
                                    </div>
                                )}
                                <div className="mt-4 flex justify-center">
                                    {activePreviewTarget ? (
                                        <SocialPostPreview
                                            target={activePreviewTarget}
                                            caption={
                                                can.update
                                                    ? caption
                                                    : (post.master_caption ??
                                                      '')
                                            }
                                            hashtags={
                                                can.update
                                                    ? hashtags
                                                    : post.hashtags
                                            }
                                            assets={previewAssets}
                                            musicName={
                                                can.update
                                                    ? musicName
                                                    : ((post.music
                                                          ?.name as string) ??
                                                      '')
                                            }
                                            locationName={
                                                can.update
                                                    ? locationName
                                                    : ((post.location
                                                          ?.name as string) ??
                                                      '')
                                            }
                                        />
                                    ) : (
                                        <p className="text-muted-foreground text-sm">
                                            Select a target account to preview
                                            the post.
                                        </p>
                                    )}
                                </div>
                            </div>
                        </div>

                        <Dialog
                            open={isPreviewFullscreen}
                            onOpenChange={setIsPreviewFullscreen}
                        >
                            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                                <DialogHeader>
                                    <DialogTitle>
                                        Live preview
                                        {activePreviewTarget
                                            ? ` — ${activePreviewTarget.handle}`
                                            : ''}
                                    </DialogTitle>
                                </DialogHeader>
                                {previewTargets.length > 1 && (
                                    <div className="flex flex-wrap gap-1.5">
                                        {previewTargets.map((t) => {
                                            const Icon =
                                                platformIcon[t.platform] ??
                                                Share2;
                                            const selected =
                                                t.id ===
                                                activePreviewTarget?.id;

                                            return (
                                                <button
                                                    key={t.id}
                                                    type="button"
                                                    onClick={() =>
                                                        setPreviewTargetId(t.id)
                                                    }
                                                    className={cn(
                                                        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors',
                                                        selected
                                                            ? 'border-primary bg-primary/10 text-primary'
                                                            : 'border-border bg-muted/50 text-foreground',
                                                    )}
                                                >
                                                    <Icon className="size-3.5" />
                                                    {t.handle}
                                                </button>
                                            );
                                        })}
                                    </div>
                                )}
                                <div className="flex justify-center py-2">
                                    {activePreviewTarget ? (
                                        <SocialPostPreview
                                            target={activePreviewTarget}
                                            caption={
                                                can.update
                                                    ? caption
                                                    : (post.master_caption ??
                                                      '')
                                            }
                                            hashtags={
                                                can.update
                                                    ? hashtags
                                                    : post.hashtags
                                            }
                                            assets={previewAssets}
                                            musicName={
                                                can.update
                                                    ? musicName
                                                    : ((post.music
                                                          ?.name as string) ??
                                                      '')
                                            }
                                            locationName={
                                                can.update
                                                    ? locationName
                                                    : ((post.location
                                                          ?.name as string) ??
                                                      '')
                                            }
                                        />
                                    ) : (
                                        <p className="text-muted-foreground text-sm">
                                            Select a target account to preview
                                            the post.
                                        </p>
                                    )}
                                </div>
                            </DialogContent>
                        </Dialog>
                    </TabsContent>

                    <TabsContent value="checklist" className="grid gap-4">
                        <div className="border-border bg-card grid gap-3 rounded-lg border p-4">
                            <Heading
                                title="Pre-schedule checklist"
                                description="Re-validated server-side before any move to Scheduled"
                            />
                            <div className="grid gap-2">
                                {post.checklist.items.map((item) => (
                                    <div
                                        key={item.key}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        {item.passed ? (
                                            <CheckCircle2 className="size-4 text-green-600 dark:text-green-500" />
                                        ) : (
                                            <XCircle className="text-destructive size-4" />
                                        )}
                                        <span
                                            className={
                                                item.passed
                                                    ? ''
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            {item.label}
                                        </span>
                                    </div>
                                ))}
                            </div>
                            <Badge
                                variant={
                                    post.checklist.passed
                                        ? 'success'
                                        : 'destructive'
                                }
                                className="w-fit"
                            >
                                {post.checklist.passed
                                    ? 'All checks passed'
                                    : 'Checklist incomplete'}
                            </Badge>
                        </div>

                        {allowedTransitions.length > 0 && (
                            <div className="border-border bg-card grid gap-3 rounded-lg border p-4">
                                <Heading
                                    title="Move status"
                                    description="Only legal, authorized transitions are shown"
                                />
                                <div className="flex flex-wrap gap-2">
                                    {allowedTransitions.map(
                                        (transitionOption) => (
                                            <Form
                                                key={transitionOption.value}
                                                {...transitionPost.form(
                                                    post.id,
                                                )}
                                            >
                                                {({ processing, errors }) => (
                                                    <div className="grid gap-1">
                                                        <input
                                                            type="hidden"
                                                            name="to"
                                                            value={
                                                                transitionOption.value
                                                            }
                                                        />
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant={
                                                                transitionOption.value ===
                                                                    'scheduled' &&
                                                                !post.checklist
                                                                    .passed
                                                                    ? 'outline'
                                                                    : 'default'
                                                            }
                                                            disabled={
                                                                processing ||
                                                                (transitionOption.value ===
                                                                    'scheduled' &&
                                                                    !post
                                                                        .checklist
                                                                        .passed)
                                                            }
                                                        >
                                                            {transitionOption.value ===
                                                            'changes_requested' ? (
                                                                <Circle className="size-3.5" />
                                                            ) : (
                                                                <CheckCircle2 className="size-3.5" />
                                                            )}
                                                            Move to{' '}
                                                            {
                                                                transitionOption.label
                                                            }
                                                        </Button>
                                                        <InputError
                                                            message={errors.to}
                                                        />
                                                    </div>
                                                )}
                                            </Form>
                                        ),
                                    )}
                                </div>
                            </div>
                        )}
                    </TabsContent>

                    <TabsContent value="comments" className="grid gap-4">
                        <div className="border-border bg-card grid gap-3 rounded-lg border p-4">
                            <Heading
                                title="Comments"
                                description="Internal notes are never visible to client reviewers — click a comment to see full details"
                            />
                            {can.comment && (
                                <Form
                                    {...storeComment.form(post.id)}
                                    resetOnSuccess
                                    className="border-border grid gap-2 rounded-md border border-dashed p-3"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <MentionTextarea
                                                name="body"
                                                rows={2}
                                                placeholder="Add a comment… type @ to mention someone"
                                                users={mentionableUsers}
                                            />
                                            <InputError message={errors.body} />
                                            <div className="flex items-center justify-between gap-2">
                                                <label className="text-muted-foreground flex items-center gap-1.5 text-xs">
                                                    <input
                                                        type="checkbox"
                                                        name="internal_only"
                                                        value="1"
                                                    />
                                                    Internal only
                                                </label>
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    Post comment
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            )}
                            <div className="grid gap-3">
                                {post.comments.length === 0 ? (
                                    <p className="text-muted-foreground flex items-center gap-1.5 text-sm">
                                        <MessageSquareQuote className="size-4" />
                                        No comments yet.
                                    </p>
                                ) : (
                                    post.comments.map((c) => (
                                        <CommentEntry key={c.id} comment={c} />
                                    ))
                                )}
                            </div>
                        </div>
                    </TabsContent>

                    <TabsContent value="activity" className="grid gap-4">
                        <div className="border-border bg-card grid gap-3 rounded-lg border p-4">
                            <Heading
                                title="Activity log"
                                description="Every status transition, oldest last — click an entry to see full details"
                            />
                            <div className="grid gap-3">
                                {post.activity_logs.length === 0 ? (
                                    <p className="text-muted-foreground flex items-center gap-1.5 text-sm">
                                        <History className="size-4" />
                                        No activity yet.
                                    </p>
                                ) : (
                                    post.activity_logs.map((log) => (
                                        <ActivityLogEntry
                                            key={log.id}
                                            log={log}
                                            postId={post.id}
                                            canResend={can.resend_review_email}
                                        />
                                    ))
                                )}
                            </div>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}
