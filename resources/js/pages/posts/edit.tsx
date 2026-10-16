import type { FormComponentRef } from '@inertiajs/core';
import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Check, Lock, MapPin, Music2, Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { AssetPreviewCarousel } from '@/components/asset-preview-carousel';
import { confirmDialog } from '@/components/confirm-dialog';
import { HashtagInput, normalizeTag } from '@/components/hashtag-input';
import InputError from '@/components/input-error';
import type { MentionableUser } from '@/components/mention-textarea';
import { Page, PageHeader, Panel } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import {
    AiCaptionSuggestions,
    type AiCaptionSuggestion,
} from '@/components/post-editor/ai-caption-suggestions';
import { ChannelSchedule } from '@/components/post-editor/channel-schedule';
import { MediaPicker } from '@/components/post-editor/media-picker';
import { MusicField } from '@/components/post-editor/music-field';
import { PostDiscussion } from '@/components/post-editor/post-discussion';
import { PostWorkflow } from '@/components/post-editor/post-workflow';
import { PreviewPanel } from '@/components/post-editor/preview-panel';
import {
    liveChecklist,
    ReadinessChecklist,
} from '@/components/post-editor/readiness-checklist';
import type {
    AssetData,
    PostData,
    ScheduleRows,
    TargetAccountData,
    TransitionOption,
} from '@/components/post-editor/types';
import { formatLocalSchedule } from '@/components/post-editor/types';
import { StatusPill } from '@/components/status-pill';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChangesGuard } from '@/hooks/use-unsaved-changes-guard';
import { cn } from '@/lib/utils';
import { show as showClient } from '@/routes/clients';
import { store as storeAsset } from '@/routes/clients/assets';
import { update as updatePost } from '@/routes/posts';
import aiPosts from '@/routes/posts/ai';

/**
 * Reads Laravel's `XSRF-TOKEN` cookie so the plain `fetch()` asset upload can authenticate as a
 * normal stateful request, mirroring the same helper on the client show page.
 */
function getXsrfTokenFromCookie(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

type EditorState = {
    caption: string;
    hashtags: string[];
    assetIds: number[];
    schedule: ScheduleRows;
    musicId: string;
    musicName: string;
    locationName: string;
};

function initialEditorState(post: PostData): EditorState {
    return {
        caption: post.master_caption ?? '',
        hashtags: post.hashtags ?? [],
        assetIds: post.assets.map((asset) => asset.id),
        schedule: Object.fromEntries(
            post.targets.map((target) => [
                target.social_account_id,
                {
                    date: target.scheduled_local_date ?? '',
                    time: target.scheduled_local_time ?? '',
                },
            ]),
        ),
        musicId: (post.music?.id as string) ?? '',
        musicName: (post.music?.name as string) ?? '',
        locationName: (post.location?.name as string) ?? '',
    };
}

const captionClass =
    'border-input bg-card focus-visible:border-ring focus-visible:ring-ring/40 dark:bg-input/30 placeholder:text-muted-foreground flex min-h-36 w-full resize-y rounded-lg border px-3.5 py-3 text-[15px] leading-relaxed outline-none focus-visible:ring-[3px]';

function ReadOnlyField({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <span className="eyebrow text-muted-foreground">{label}</span>
            {children}
        </div>
    );
}

/** What viewers without edit rights (or anyone, while the post is locked) see in place of the form. */
function ReadOnlyContent({ post }: { post: PostData }) {
    const locked = post.status === 'scheduled' || post.status === 'published';

    return (
        <div className="grid gap-5">
            {locked && (
                <div className="border-border bg-muted/50 flex items-start gap-3 rounded-2xl border p-4 text-sm">
                    <Lock className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                    <p>
                        <span className="font-medium">
                            {post.status_label} posts are locked.
                        </span>{' '}
                        <span className="text-muted-foreground">
                            Move it back to draft from the workflow above to
                            make changes.
                        </span>
                    </p>
                </div>
            )}
            <Panel eyebrow="01" title="Content">
                <div className="grid gap-5">
                    <ReadOnlyField label="Caption">
                        <p className="text-[15px] leading-relaxed whitespace-pre-wrap">
                            {post.master_caption || (
                                <span className="text-muted-foreground">
                                    No caption yet.
                                </span>
                            )}
                        </p>
                    </ReadOnlyField>
                    {post.hashtags.length > 0 && (
                        <ReadOnlyField label="Hashtags">
                            <div className="flex flex-wrap gap-1.5">
                                {post.hashtags.map((tag) => (
                                    <Badge key={tag} variant="secondary">
                                        #{tag}
                                    </Badge>
                                ))}
                            </div>
                        </ReadOnlyField>
                    )}
                </div>
            </Panel>
            <Panel eyebrow="02" title="Channels & schedule">
                {post.targets.length > 0 ? (
                    <ul className="border-border divide-border/70 divide-y rounded-xl border">
                        {post.targets.map((target) => {
                            const Icon = platformIcon(target.platform);

                            return (
                                <li
                                    key={target.id}
                                    className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm"
                                >
                                    <Icon className="text-foreground/70 size-4" />
                                    <span className="font-medium">
                                        {target.handle ?? '—'}
                                    </span>
                                    <span className="text-muted-foreground font-mono text-xs">
                                        {formatLocalSchedule(
                                            target.scheduled_local_date,
                                            target.scheduled_local_time,
                                        ) ?? 'No time set'}
                                    </span>
                                    {target.status !== 'pending' && (
                                        <StatusPill
                                            kind="postTarget"
                                            value={target.status}
                                            label={target.status_label}
                                            className="ml-auto"
                                        />
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                ) : (
                    <p className="text-muted-foreground text-sm">
                        No channels selected.
                    </p>
                )}
            </Panel>
            <Panel eyebrow="03" title="Media">
                {post.assets.length > 0 ? (
                    <AssetPreviewCarousel assets={post.assets} />
                ) : (
                    <p className="text-muted-foreground text-sm">
                        No media attached.
                    </p>
                )}
            </Panel>
            {Boolean(post.music?.name || post.location?.name) && (
                <Panel eyebrow="04" title="Details">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReadOnlyField label="Sound">
                            <p className="text-sm">
                                {(post.music?.name as string) || '—'}
                            </p>
                        </ReadOnlyField>
                        <ReadOnlyField label="Location">
                            <p className="text-sm">
                                {(post.location?.name as string) || '—'}
                            </p>
                        </ReadOnlyField>
                    </div>
                </Panel>
            )}
        </div>
    );
}

export default function PostEdit({
    post,
    client,
    targetAccounts,
    allowedTransitions,
    can,
    hashtagSuggestions,
    mentionableUsers,
    ai,
}: {
    post: PostData;
    client: { id: number; name: string };
    targetAccounts: TargetAccountData[];
    allowedTransitions: TransitionOption[];
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
    // The page remounts with fresh props after every successful save/transition, so this snapshot
    // is always "what's on the server" — the baseline for unsaved-changes tracking.
    const [initial] = useState(() => initialEditorState(post));
    const [caption, setCaption] = useState(initial.caption);
    const [hashtags, setHashtags] = useState(initial.hashtags);
    const [selectedAssetIds, setSelectedAssetIds] = useState(initial.assetIds);
    const [scheduleRows, setScheduleRows] = useState(initial.schedule);
    const [musicId, setMusicId] = useState(initial.musicId);
    const [musicName, setMusicName] = useState(initial.musicName);
    const [locationName, setLocationName] = useState(initial.locationName);

    // Asset details for every id the editor knows about (saved media, library picks, inline
    // uploads). The library itself is paged server-side, so this only ever holds what's in use.
    const [knownAssets, setKnownAssets] = useState<Record<number, AssetData>>(
        () => Object.fromEntries(post.assets.map((asset) => [asset.id, asset])),
    );
    const [isUploadingAsset, setIsUploadingAsset] = useState(false);

    const formRef = useRef<FormComponentRef>(null);

    const isDirty =
        can.update &&
        JSON.stringify({
            caption,
            hashtags,
            assetIds: selectedAssetIds,
            schedule: scheduleRows,
            musicId,
            musicName,
            locationName,
        } satisfies EditorState) !== JSON.stringify(initial);

    useUnsavedChangesGuard(isDirty);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (
                (event.metaKey || event.ctrlKey) &&
                event.key.toLowerCase() === 's'
            ) {
                event.preventDefault();

                if (isDirty) {
                    formRef.current?.submit();
                }
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [isDirty]);

    const discardChanges = () => {
        setCaption(initial.caption);
        setHashtags(initial.hashtags);
        setSelectedAssetIds(initial.assetIds);
        setScheduleRows(initial.schedule);
        setMusicId(initial.musicId);
        setMusicName(initial.musicName);
        setLocationName(initial.locationName);
    };

    // Step 2.4 — "Suggest with AI". Never overwrites the caption without an explicit click, and
    // the result still has to be saved like any other edit.
    const showAi = Boolean(ai?.enabled && ai?.canUse);
    const [aiLoading, setAiLoading] = useState(false);
    const [aiError, setAiError] = useState<string | null>(null);
    const [aiSuggestions, setAiSuggestions] = useState<AiCaptionSuggestion[]>(
        [],
    );
    // Step 2.6 — per-suggestion platform policy warnings, surfaced for a human to decide on.
    const [aiPolicyWarnings, setAiPolicyWarnings] = useState<string[][]>([]);
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

    const applyAiSuggestion = async (
        suggestion: AiCaptionSuggestion,
        index: number,
    ) => {
        if (
            caption.trim() &&
            !(await confirmDialog({
                title: 'Replace your caption?',
                description:
                    'Your current caption and hashtags will be swapped for this AI suggestion. Nothing is saved until you save the post.',
                confirmLabel: 'Replace caption',
            }))
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

    const toggleChannel = (accountId: number) => {
        setScheduleRows((current) => {
            if (accountId in current) {
                const next = { ...current };
                delete next[accountId];

                return next;
            }

            return { ...current, [accountId]: { date: '', time: '' } };
        });
    };

    const updateSchedule = (
        accountId: number,
        field: 'date' | 'time',
        value: string,
    ) => {
        setScheduleRows((current) => ({
            ...current,
            [accountId]: { ...current[accountId], [field]: value },
        }));
    };

    // An explicit, user-triggered copy — each target still keeps (and is stored with) its own
    // local date/time, so this isn't the implicit "same instant" shortcut spec §4.6 forbids.
    const applyScheduleToAll = (sourceAccountId: number) => {
        setScheduleRows((current) => {
            const source = current[sourceAccountId];

            return Object.fromEntries(
                Object.keys(current).map((id) => [id, { ...source }]),
            );
        });
    };

    const setAttachedAssets = (attached: AssetData[]) => {
        setKnownAssets((current) => ({
            ...current,
            ...Object.fromEntries(attached.map((asset) => [asset.id, asset])),
        }));
        setSelectedAssetIds(attached.map((asset) => asset.id));
    };

    // Posts straight to the client asset-store endpoint as JSON so the new asset can be merged in
    // place instead of redirecting away and losing unsaved editor changes.
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
                toast.error(
                    typeof data?.message === 'string'
                        ? data.message
                        : 'Unable to upload the asset.',
                );

                return;
            }

            const asset: AssetData = data.asset;
            setKnownAssets((current) => ({ ...current, [asset.id]: asset }));
            setSelectedAssetIds((current) => [...current, asset.id]);
            toast.success('Uploaded and added to this post.');
        } catch {
            toast.error('Unable to upload the asset.');
        } finally {
            setIsUploadingAsset(false);
        }
    };

    const selectedAccounts = Object.keys(scheduleRows)
        .map((id) => targetAccounts.find((a) => a.id === Number(id)))
        .filter((a): a is TargetAccountData => Boolean(a));
    const selectedAssets = selectedAssetIds
        .map((id) => knownAssets[id])
        .filter((asset): asset is AssetData => Boolean(asset));

    // Step 1.8.4 — music search only exists for Instagram/TikTok targets.
    const musicPlatforms = Array.from(
        new Set(
            selectedAccounts
                .map((a) => a.platform)
                .filter((p) => p === 'instagram' || p === 'tiktok'),
        ),
    );

    const checklistItems = can.update
        ? liveChecklist(post.checklist.items, {
              caption,
              selectedAccounts,
              schedule: Object.values(scheduleRows),
              mediaCount: selectedAssetIds.length,
          })
        : post.checklist.items;

    const previewTargets = can.update
        ? selectedAccounts.map((a) => ({
              id: a.id,
              platform: a.platform,
              handle: a.handle,
          }))
        : post.targets.map((t) => ({
              id: t.social_account_id,
              platform: t.platform ?? '',
              handle: t.handle ?? '',
          }));

    return (
        <>
            <Head title={`Edit post — ${client.name}`} />
            <Page>
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
                    eyebrow={
                        post.campaign_name
                            ? `Post ${post.id} — ${post.campaign_name}`
                            : `Post ${post.id}`
                    }
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
                            {post.targets.map((target) => {
                                const Icon = platformIcon(target.platform);
                                const when = formatLocalSchedule(
                                    target.scheduled_local_date,
                                    target.scheduled_local_time,
                                );

                                return (
                                    <Badge key={target.id} variant="outline">
                                        <Icon className="size-3" />
                                        {target.handle}
                                        {when && (
                                            <span className="text-muted-foreground font-mono">
                                                {when}
                                            </span>
                                        )}
                                    </Badge>
                                );
                            })}
                        </>
                    }
                />

                <PostWorkflow
                    post={post}
                    clientName={client.name}
                    allowedTransitions={allowedTransitions}
                    isDirty={isDirty}
                    canResendReviewEmail={can.resend_review_email}
                />

                <div className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start">
                    <div className="grid min-w-0 gap-5">
                        {can.update ? (
                            <Form
                                ref={formRef}
                                {...updatePost.form(post.id)}
                                options={{
                                    preserveScroll: true,
                                    preserveState: 'errors',
                                }}
                                className="grid gap-5"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <Panel
                                            eyebrow="01"
                                            title="Content"
                                            actions={
                                                showAi && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={
                                                            fetchAiSuggestions
                                                        }
                                                        disabled={aiLoading}
                                                    >
                                                        <Sparkles />
                                                        {aiLoading
                                                            ? 'Thinking…'
                                                            : 'Suggest with AI'}
                                                    </Button>
                                                )
                                            }
                                            bodyClassName="grid gap-5"
                                        >
                                            <div className="grid gap-2">
                                                <div className="flex items-baseline justify-between">
                                                    <Label htmlFor="master_caption">
                                                        Caption
                                                    </Label>
                                                    <span className="text-muted-foreground font-mono text-[11px] tabular-nums">
                                                        {caption.length.toLocaleString()}{' '}
                                                        chars
                                                    </span>
                                                </div>
                                                <textarea
                                                    id="master_caption"
                                                    name="master_caption"
                                                    rows={6}
                                                    value={caption}
                                                    onChange={(e) =>
                                                        setCaption(
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Write the caption…"
                                                    className={captionClass}
                                                />
                                                <InputError
                                                    message={
                                                        errors.master_caption
                                                    }
                                                />
                                                {aiError && (
                                                    <p className="text-destructive text-xs">
                                                        {aiError}
                                                    </p>
                                                )}
                                                <AiCaptionSuggestions
                                                    suggestions={aiSuggestions}
                                                    policyWarnings={
                                                        aiPolicyWarnings
                                                    }
                                                    appliedIndex={
                                                        appliedSuggestionIndex
                                                    }
                                                    expanded={
                                                        suggestionsExpanded
                                                    }
                                                    onToggleExpanded={() =>
                                                        setSuggestionsExpanded(
                                                            (current) =>
                                                                !current,
                                                        )
                                                    }
                                                    onApply={applyAiSuggestion}
                                                />
                                            </div>

                                            <div className="grid gap-2">
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
                                        </Panel>

                                        <Panel
                                            eyebrow="02"
                                            title="Channels & schedule"
                                            description="Each channel publishes at its own local time."
                                        >
                                            <ChannelSchedule
                                                accounts={targetAccounts}
                                                rows={scheduleRows}
                                                onToggle={toggleChannel}
                                                onChange={updateSchedule}
                                                onApplyToAll={
                                                    applyScheduleToAll
                                                }
                                                captionLength={caption.length}
                                                hashtagCount={hashtags.length}
                                                error={
                                                    errors[
                                                        'targets.0.social_account_id'
                                                    ]
                                                }
                                            />
                                        </Panel>

                                        <Panel
                                            eyebrow="03"
                                            title="Media"
                                            description="Upload something new or pick from the client's library — the number is the order it appears in."
                                            actions={
                                                <span className="text-muted-foreground font-mono text-xs">
                                                    {selectedAssetIds.length}{' '}
                                                    attached
                                                </span>
                                            }
                                        >
                                            <MediaPicker
                                                clientId={client.id}
                                                selected={selectedAssets}
                                                onChange={setAttachedAssets}
                                                canUpload={can.create_asset}
                                                uploading={isUploadingAsset}
                                                onUpload={(file) =>
                                                    void handleAssetUpload(file)
                                                }
                                            />
                                        </Panel>

                                        <Panel
                                            eyebrow="04"
                                            title="Details"
                                            bodyClassName="grid gap-5 sm:grid-cols-2"
                                        >
                                            <div className="grid content-start gap-2">
                                                <Label
                                                    htmlFor="music_search"
                                                    className="flex items-center gap-1.5"
                                                >
                                                    <Music2 className="text-muted-foreground size-3.5" />
                                                    Sound
                                                </Label>
                                                <MusicField
                                                    postId={post.id}
                                                    clientId={post.client_id}
                                                    platforms={musicPlatforms}
                                                    instagramAccountId={
                                                        selectedAccounts.find(
                                                            (a) =>
                                                                a.platform ===
                                                                'instagram',
                                                        )?.id ?? null
                                                    }
                                                    musicId={musicId}
                                                    musicName={musicName}
                                                    onChange={(track) => {
                                                        setMusicId(track.id);
                                                        setMusicName(
                                                            track.name,
                                                        );
                                                    }}
                                                />
                                            </div>
                                            <div className="grid content-start gap-2">
                                                <Label
                                                    htmlFor="location_name"
                                                    className="flex items-center gap-1.5"
                                                >
                                                    <MapPin className="text-muted-foreground size-3.5" />
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
                                                    placeholder="e.g. Shoreditch, London"
                                                />
                                            </div>
                                        </Panel>

                                        <div
                                            className={cn(
                                                'bg-card/95 shadow-paper sticky bottom-4 z-10 flex items-center justify-between gap-3 rounded-full border py-2 pr-2 pl-5 backdrop-blur transition-colors',
                                                isDirty
                                                    ? 'border-foreground/20'
                                                    : 'border-border',
                                            )}
                                        >
                                            <span className="flex items-center gap-2 text-sm">
                                                {isDirty ? (
                                                    <>
                                                        <span className="bg-warning size-2 rounded-full" />
                                                        Unsaved changes
                                                        <kbd className="text-muted-foreground hidden font-mono text-[11px] sm:inline">
                                                            Ctrl/⌘ S
                                                        </kbd>
                                                    </>
                                                ) : (
                                                    <span className="text-muted-foreground flex items-center gap-1.5">
                                                        <Check className="text-success size-4" />
                                                        All changes saved
                                                    </span>
                                                )}
                                            </span>
                                            <span className="flex items-center gap-1">
                                                {isDirty && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        onClick={discardChanges}
                                                        disabled={processing}
                                                    >
                                                        Discard
                                                    </Button>
                                                )}
                                                <Button
                                                    type="submit"
                                                    variant="secondary"
                                                    disabled={
                                                        processing || !isDirty
                                                    }
                                                >
                                                    {processing
                                                        ? 'Saving…'
                                                        : 'Save changes'}
                                                </Button>
                                            </span>
                                        </div>
                                    </>
                                )}
                            </Form>
                        ) : (
                            <ReadOnlyContent post={post} />
                        )}

                        <PostDiscussion
                            postId={post.id}
                            comments={post.comments}
                            activityLogs={post.activity_logs}
                            canComment={can.comment}
                            canResendReviewEmail={can.resend_review_email}
                            mentionableUsers={mentionableUsers}
                        />
                    </div>

                    <aside className="grid gap-5 lg:sticky lg:top-20">
                        <ReadinessChecklist
                            items={checklistItems}
                            note={
                                isDirty
                                    ? 'Reflects your unsaved edits — save before scheduling.'
                                    : undefined
                            }
                        />
                        <PreviewPanel
                            targets={previewTargets}
                            caption={
                                can.update
                                    ? caption
                                    : (post.master_caption ?? '')
                            }
                            hashtags={can.update ? hashtags : post.hashtags}
                            assets={can.update ? selectedAssets : post.assets}
                            musicName={
                                can.update
                                    ? musicName
                                    : ((post.music?.name as string) ?? '')
                            }
                            locationName={
                                can.update
                                    ? locationName
                                    : ((post.location?.name as string) ?? '')
                            }
                        />
                    </aside>
                </div>
            </Page>
        </>
    );
}
