import type { FormComponentRef } from '@inertiajs/core';
import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    ChevronDown,
    Lock,
    MapPin,
    Music2,
    Sparkles,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { AssetPreviewCarousel } from '@/components/asset-preview-carousel';
import { confirmDialog } from '@/components/confirm-dialog';
import { normalizeTag } from '@/components/hashtag-input';
import { ImageStudio } from '@/components/image-studio/image-studio';
import type { ImageStudioData } from '@/components/image-studio/types';
import InputError from '@/components/input-error';
import type { MentionableUser } from '@/components/mention-textarea';
import { Page, PageHeader, Panel } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import {
    AiCaptionSuggestions,
    type AiCaptionSuggestion,
} from '@/components/post-editor/ai-caption-suggestions';
import { CampaignChip } from '@/components/campaigns/campaign-chip';
import { CampaignField } from '@/components/post-editor/campaign-field';
import type { CampaignOption } from '@/components/post-editor/campaign-field';
import { TargetFields } from '@/components/post-editor/target-fields';
import { ChannelSchedule } from '@/components/post-editor/channel-schedule';
import type {
    ComposerScope,
    MasterContent,
} from '@/components/post-editor/composer';
import { ChannelScopeBar, Composer } from '@/components/post-editor/composer';
import { MediaDetails } from '@/components/post-editor/media-details';
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
    ChannelState,
    ChecklistFix,
    PostData,
    ScheduleRows,
    TargetAccountData,
    TransitionOption,
} from '@/components/post-editor/types';
import {
    EMPTY_CHANNEL,
    formatLocalSchedule,
} from '@/components/post-editor/types';
import { StatusPill } from '@/components/status-pill';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useUnsavedChangesGuard } from '@/hooks/use-unsaved-changes-guard';
import type { Crop, MediaFormat, MediaFormatOptions } from '@/lib/media-format';
import {
    DEFAULT_CROP,
    normalizeCrop,
    publishedCaption,
} from '@/lib/media-format';
import { capabilityOf, usePlatforms } from '@/lib/platforms';
import { cn } from '@/lib/utils';
import { show as showCampaign } from '@/routes/campaigns';
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
    campaignId: string;
    caption: string;
    hashtags: string[];
    assetIds: number[];
    schedule: ScheduleRows;
    musicId: string;
    musicName: string;
    locationName: string;
    mediaFormat: MediaFormat;
    /** Framing of the attached media only, keyed by asset id. */
    crops: Record<number, Crop>;
    /** Step M-2.2: the master link and first comment. */
    link: string;
    firstComment: string;
    /** Step M-2.2 / M-2.3: each channel's own content and format, keyed by account id. */
    channels: Record<number, ChannelState>;
    /** Alt text per attached image. */
    altTexts: Record<number, string>;
    coverAssetId: number | null;
    thumbOffsetMs: number | null;
    /** Step M-2.9: whether the client must approve this post. */
    approvalMode: string;
};

function initialEditorState(
    post: PostData,
    targetAccounts: TargetAccountData[],
): EditorState {
    // A target whose account was disconnected (soft-deleted) isn't selectable, so it's left out
    // of the editable channels — otherwise it would be re-sent, invisibly, and fail validation.
    // Saving drops it.
    const targets = post.targets.filter((target) =>
        targetAccounts.some((a) => a.id === target.social_account_id),
    );

    return {
        campaignId: post.campaign_id ? String(post.campaign_id) : '',
        caption: post.master_caption ?? '',
        hashtags: post.hashtags ?? [],
        assetIds: post.assets.map((asset) => asset.id),
        schedule: Object.fromEntries(
            targets.map((target) => [
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
        mediaFormat: { ...post.media_format },
        crops: Object.fromEntries(
            post.assets.map((asset) => [asset.id, normalizeCrop(asset.crop)]),
        ),
        link: post.link_url ?? '',
        firstComment: post.first_comment ?? '',
        channels: Object.fromEntries(
            targets.map((target) => [
                target.social_account_id,
                {
                    caption: target.caption_override,
                    hashtags: target.hashtags_override,
                    firstComment: target.first_comment,
                    link: target.link_url,
                    format: target.format,
                } satisfies ChannelState,
            ]),
        ),
        altTexts: Object.fromEntries(
            post.assets
                .filter((asset) => asset.alt_text)
                .map((asset) => [asset.id, asset.alt_text ?? '']),
        ),
        coverAssetId: post.cover_asset_id,
        thumbOffsetMs: post.thumb_offset_ms,
        approvalMode: post.approval_mode,
    };
}

/** `image`/`video` per item that publishes: a post with a video sends just that video. */
function mediaKindsOf(assets: AssetData[]): string[] {
    const isVideo = (asset: AssetData) =>
        (asset.type ?? '').startsWith('video');
    const isImage = (asset: AssetData) =>
        (asset.type ?? '').startsWith('image');

    if (assets.some(isVideo)) {
        return ['video'];
    }

    return assets.filter(isImage).map(() => 'image');
}

type EditorSection = 'compose' | 'channels' | 'media' | 'settings' | 'preview';

/** Which part of the editor fixes each readiness check — the checklist's "jump to" target. */
const checkSection: Record<string, EditorSection> = {
    caption: 'compose',
    caption_length: 'compose',
    hashtags: 'compose',
    links: 'compose',
    first_comment: 'compose',
    format: 'compose',
    targets: 'channels',
    schedule: 'channels',
    account_settings: 'channels',
    media: 'media',
    media_fit: 'media',
    media_framing: 'preview',
    campaign_hashtags: 'settings',
};

const approvalLabels: Record<string, string> = {
    client_required: 'Client approves',
    internal_only: 'Internal only',
};

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

/** The folded "Post settings": each value as a ruled spec cell, one click from editing. */
function SettingsSummary({
    items,
    onOpen,
}: {
    items: { label: string; value: string; muted: boolean }[];
    onOpen: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onOpen}
            className="hover:bg-muted/50 focus-visible:ring-ring/50 -m-2 grid w-[calc(100%+1rem)] grid-cols-2 gap-x-6 gap-y-4 rounded-xl p-2 text-start transition-colors outline-none focus-visible:ring-[3px] sm:grid-cols-4"
        >
            {items.map((item) => (
                <span key={item.label} className="grid min-w-0 gap-1">
                    <span className="eyebrow text-muted-foreground">
                        {item.label}
                    </span>
                    <span
                        dir="auto"
                        className={cn(
                            'truncate text-sm',
                            item.muted
                                ? 'text-muted-foreground'
                                : 'font-medium',
                        )}
                    >
                        {item.value}
                    </span>
                </span>
            ))}
        </button>
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
                        <p
                            dir="auto"
                            className="text-[15px] leading-relaxed whitespace-pre-wrap"
                        >
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
                                    <Badge
                                        key={tag}
                                        variant="secondary"
                                        dir="auto"
                                    >
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
    campaignOptions,
    mentionableUsers,
    ai,
    imageStudio,
    mediaFormatOptions,
}: {
    post: PostData;
    client: { id: number; name: string };
    targetAccounts: TargetAccountData[];
    allowedTransitions: TransitionOption[];
    can: {
        update: boolean;
        comment: boolean;
        resend_review_email: boolean;
        retry_failed_targets: boolean;
        delete: boolean;
        override_approval: boolean;
        create_asset: boolean;
    };
    hashtagSuggestions: string[];
    campaignOptions: CampaignOption[];
    mentionableUsers: MentionableUser[];
    ai?: { enabled: boolean; canUse: boolean };
    imageStudio?: ImageStudioData | null;
    mediaFormatOptions: MediaFormatOptions;
}) {
    // The page remounts with fresh props after every successful save/transition, so this snapshot
    // is always "what's on the server" — the baseline for unsaved-changes tracking.
    const [initial] = useState(() => initialEditorState(post, targetAccounts));
    const [campaignId, setCampaignId] = useState(initial.campaignId);
    const [caption, setCaption] = useState(initial.caption);
    const [hashtags, setHashtags] = useState(initial.hashtags);
    const [selectedAssetIds, setSelectedAssetIds] = useState(initial.assetIds);
    const [scheduleRows, setScheduleRows] = useState(initial.schedule);
    const [musicId, setMusicId] = useState(initial.musicId);
    const [musicName, setMusicName] = useState(initial.musicName);
    const [locationName, setLocationName] = useState(initial.locationName);
    const [mediaFormat, setMediaFormat] = useState(initial.mediaFormat);
    const [crops, setCrops] = useState(initial.crops);
    const [link, setLink] = useState(initial.link);
    const [firstComment, setFirstComment] = useState(initial.firstComment);
    const [channels, setChannels] = useState(initial.channels);
    const [altTexts, setAltTexts] = useState(initial.altTexts);
    const [coverAssetId, setCoverAssetId] = useState(initial.coverAssetId);
    const [thumbOffsetMs, setThumbOffsetMs] = useState(initial.thumbOffsetMs);
    const [approvalMode, setApprovalMode] = useState(initial.approvalMode);
    const registry = usePlatforms();
    // Only attached media's framing is saved (and compared), so a removed image's edits drop out.
    const selectedCrops: Record<number, Crop> = Object.fromEntries(
        selectedAssetIds.map((id) => [id, crops[id] ?? DEFAULT_CROP]),
    );
    // Likewise only selected channels' overrides and attached images' alt text.
    const selectedChannels: Record<number, ChannelState> = Object.fromEntries(
        Object.keys(scheduleRows).map((id) => [
            Number(id),
            channels[Number(id)] ?? EMPTY_CHANNEL,
        ]),
    );
    const selectedAltTexts: Record<number, string> = Object.fromEntries(
        selectedAssetIds
            .filter((id) => (altTexts[id] ?? '') !== '')
            .map((id) => [id, altTexts[id]]),
    );

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
            campaignId,
            caption,
            hashtags,
            assetIds: selectedAssetIds,
            schedule: scheduleRows,
            musicId,
            musicName,
            locationName,
            mediaFormat,
            crops: selectedCrops,
            link,
            firstComment,
            channels: selectedChannels,
            altTexts: selectedAltTexts,
            coverAssetId,
            thumbOffsetMs,
            approvalMode,
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
        setCampaignId(initial.campaignId);
        setCaption(initial.caption);
        setHashtags(initial.hashtags);
        setSelectedAssetIds(initial.assetIds);
        setScheduleRows(initial.schedule);
        setMusicId(initial.musicId);
        setMusicName(initial.musicName);
        setLocationName(initial.locationName);
        setMediaFormat(initial.mediaFormat);
        setCrops(initial.crops);
        setLink(initial.link);
        setFirstComment(initial.firstComment);
        setChannels(initial.channels);
        setAltTexts(initial.altTexts);
        setCoverAssetId(initial.coverAssetId);
        setThumbOffsetMs(initial.thumbOffsetMs);
        setApprovalMode(initial.approvalMode);
    };

    // Step M-2.3 / M-2.4: the checklist's one-click fixes change unsaved state only.
    const applyFix = (fix: ChecklistFix) => {
        if (fix.action === 'format' && fix.social_account_id) {
            const accountId = fix.social_account_id;
            setChannels((current) => ({
                ...current,
                [accountId]: {
                    ...(current[accountId] ?? EMPTY_CHANNEL),
                    format: fix.value,
                },
            }));
        }

        if (fix.action === 'media_format' && fix.platform) {
            const platform = fix.platform;
            setMediaFormat((current) => ({
                ...current,
                [platform]: fix.value,
            }));
        }

        toast.success('Fixed — save to keep it.');
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
            // Step I-1.7: POST — this calls a paid vendor.
            const response = await fetch(aiPosts.captions.url(post.id), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': getXsrfTokenFromCookie(),
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

    const mediaKinds = mediaKindsOf(selectedAssets);

    const checklistItems = can.update
        ? liveChecklist(post.checklist.items, {
              caption,
              selectedAccounts,
              schedule: Object.values(scheduleRows),
              mediaCount: selectedAssetIds.length,
              mediaKinds,
              hashtags,
              campaignHashtags:
                  campaignOptions.find(
                      (option) => String(option.id) === campaignId,
                  )?.hashtags ?? [],
              channels: selectedChannels,
              link,
              firstComment,
              registry,
          })
        : post.checklist.items;

    // Each channel's preview carries its own caption, hashtags, link, first comment and format.
    const previewTargets = can.update
        ? selectedAccounts.map((a) => {
              const channel = selectedChannels[a.id] ?? EMPTY_CHANNEL;

              return {
                  id: a.id,
                  platform: a.platform,
                  handle: a.handle,
                  display_name: a.display_name,
                  caption: channel.caption,
                  hashtags: channel.hashtags,
                  link: channel.link ?? (link || null),
                  firstComment: channel.firstComment ?? (firstComment || null),
                  format: channel.format,
              };
          })
        : post.targets.map((t) => ({
              id: t.social_account_id,
              platform: t.platform ?? '',
              handle: t.handle ?? '',
              display_name: t.display_name,
              format: t.format,
              link: t.link_url ?? post.link_url,
              firstComment: t.first_comment ?? post.first_comment,
              publishedCaption: t.published_caption,
          }));

    // Per-channel usage for the schedule rows: the caption as it publishes there.
    const channelUsage = Object.fromEntries(
        selectedAccounts.map((account) => {
            const channel = selectedChannels[account.id] ?? EMPTY_CHANNEL;
            const tags = channel.hashtags ?? hashtags;

            return [
                account.id,
                {
                    chars: publishedCaption(
                        channel.caption ?? caption,
                        tags,
                        capabilityOf(registry, account.platform),
                        channel.link ?? link,
                    ).length,
                    tags: tags.length,
                },
            ];
        }),
    );
    const altTextPlatforms = [
        ...new Set(
            selectedAccounts
                .map((account) => capabilityOf(registry, account.platform))
                .filter((capability) => capability.alt_text)
                .map((capability) => capability.label),
        ),
    ];

    // One channel context for the whole editor: the composer edits it and the preview shows it.
    const [scope, setScope] = useState<ComposerScope>('all');
    const [previewId, setPreviewId] = useState<number | null>(null);
    const activeScope: ComposerScope =
        scope !== 'all' && scope in scheduleRows ? scope : 'all';
    const changeScope = (next: ComposerScope) => {
        setScope(next);

        if (next !== 'all') {
            setPreviewId(next);
        }
    };

    const master: MasterContent = { caption, hashtags, link, firstComment };
    const updateMaster = (patch: Partial<MasterContent>) => {
        if (patch.caption !== undefined) {
            setCaption(patch.caption);
        }

        if (patch.hashtags !== undefined) {
            setHashtags(patch.hashtags);
        }

        if (patch.link !== undefined) {
            setLink(patch.link);
        }

        if (patch.firstComment !== undefined) {
            setFirstComment(patch.firstComment);
        }
    };

    const [settingsOpen, setSettingsOpen] = useState(false);
    const [flashed, setFlashed] = useState<EditorSection | null>(null);
    const captionRef = useRef<HTMLTextAreaElement>(null);
    const sectionRefs = useRef<Partial<Record<EditorSection, HTMLElement>>>({});
    const sectionRef = (section: EditorSection) => (el: HTMLElement | null) => {
        if (el) {
            sectionRefs.current[section] = el;
        }
    };

    // Scrolls to the part of the editor that fixes something, and briefly rings it.
    const jumpTo = (section: EditorSection) => {
        if (section === 'settings') {
            setSettingsOpen(true);
        }

        requestAnimationFrame(() => {
            const reduce = window.matchMedia(
                '(prefers-reduced-motion: reduce)',
            ).matches;
            sectionRefs.current[section]?.scrollIntoView({
                behavior: reduce ? 'auto' : 'smooth',
                block: 'start',
            });

            if (section === 'compose') {
                captionRef.current?.focus({ preventScroll: true });
            }

            setFlashed(section);
            window.setTimeout(() => setFlashed(null), 1400);
        });
    };
    const flash = (section: EditorSection) =>
        cn(
            'transition-shadow duration-500',
            flashed === section && 'ring-ring/50 ring-[3px]',
        );

    const selectedCampaign = campaignOptions.find(
        (option) => String(option.id) === campaignId,
    );

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
                        </>
                    }
                />

                <PostWorkflow
                    post={post}
                    clientName={client.name}
                    allowedTransitions={allowedTransitions}
                    isDirty={isDirty}
                    canResendReviewEmail={can.resend_review_email}
                    canRetryFailedTargets={can.retry_failed_targets}
                    canDelete={can.delete}
                    canOverrideApproval={can.override_approval}
                />

                <div className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
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
                                        <div
                                            ref={sectionRef('compose')}
                                            className="scroll-mt-24"
                                        >
                                            <Panel
                                                eyebrow="01"
                                                title="Write"
                                                className={flash('compose')}
                                                actions={
                                                    showAi &&
                                                    activeScope === 'all' && (
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
                                                <ChannelScopeBar
                                                    scope={activeScope}
                                                    onScopeChange={changeScope}
                                                    accounts={selectedAccounts}
                                                    channels={selectedChannels}
                                                    master={master}
                                                    onAddChannels={() =>
                                                        jumpTo('channels')
                                                    }
                                                />
                                                <Composer
                                                    scope={activeScope}
                                                    accounts={selectedAccounts}
                                                    master={master}
                                                    onMasterChange={
                                                        updateMaster
                                                    }
                                                    channels={selectedChannels}
                                                    onChannelChange={(
                                                        accountId,
                                                        next,
                                                    ) =>
                                                        setChannels(
                                                            (current) => ({
                                                                ...current,
                                                                [accountId]:
                                                                    next,
                                                            }),
                                                        )
                                                    }
                                                    mediaKinds={mediaKinds}
                                                    hashtagSuggestions={
                                                        hashtagSuggestions
                                                    }
                                                    errors={errors}
                                                    captionRef={captionRef}
                                                    aiPanel={
                                                        <>
                                                            {aiError && (
                                                                <p className="text-destructive text-xs">
                                                                    {aiError}
                                                                </p>
                                                            )}
                                                            <AiCaptionSuggestions
                                                                suggestions={
                                                                    aiSuggestions
                                                                }
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
                                                                        (
                                                                            current,
                                                                        ) =>
                                                                            !current,
                                                                    )
                                                                }
                                                                onApply={
                                                                    applyAiSuggestion
                                                                }
                                                            />
                                                        </>
                                                    }
                                                />

                                                {/* The composer's fields are unnamed: the form sends these. */}
                                                <input
                                                    type="hidden"
                                                    name="master_caption"
                                                    value={caption}
                                                />
                                                {hashtags.map((tag, i) => (
                                                    <input
                                                        key={i}
                                                        type="hidden"
                                                        name={`hashtags[${i}]`}
                                                        value={tag}
                                                    />
                                                ))}
                                                <input
                                                    type="hidden"
                                                    name="link_url"
                                                    value={link}
                                                />
                                                <input
                                                    type="hidden"
                                                    name="first_comment"
                                                    value={firstComment}
                                                />
                                                {/* Image format + framing are set from the preview rail. */}
                                                {Object.entries(
                                                    mediaFormat,
                                                ).map(([platform, ratio]) => (
                                                    <input
                                                        key={platform}
                                                        type="hidden"
                                                        name={`media_format[${platform}]`}
                                                        value={ratio ?? ''}
                                                    />
                                                ))}
                                                {Object.entries(
                                                    selectedCrops,
                                                ).map(([id, crop]) =>
                                                    (
                                                        [
                                                            'x',
                                                            'y',
                                                            'zoom',
                                                        ] as const
                                                    ).map((field) => (
                                                        <input
                                                            key={`${id}-${field}`}
                                                            type="hidden"
                                                            name={`asset_crops[${id}][${field}]`}
                                                            value={String(
                                                                crop[field],
                                                            )}
                                                        />
                                                    )),
                                                )}
                                            </Panel>
                                        </div>

                                        <div
                                            ref={sectionRef('channels')}
                                            className="scroll-mt-24"
                                        >
                                            <Panel
                                                eyebrow="02"
                                                title="Where & when"
                                                description="Tick the channels, then give each its own local time."
                                                className={flash('channels')}
                                            >
                                                <ChannelSchedule
                                                    accounts={targetAccounts}
                                                    rows={scheduleRows}
                                                    onToggle={toggleChannel}
                                                    onChange={updateSchedule}
                                                    onApplyToAll={
                                                        applyScheduleToAll
                                                    }
                                                    usage={channelUsage}
                                                    error={
                                                        errors[
                                                            'targets.0.social_account_id'
                                                        ]
                                                    }
                                                />
                                                <TargetFields
                                                    accountIds={Object.keys(
                                                        scheduleRows,
                                                    ).map(Number)}
                                                    schedule={scheduleRows}
                                                    channels={selectedChannels}
                                                />
                                            </Panel>
                                        </div>

                                        <div
                                            ref={sectionRef('media')}
                                            className="scroll-mt-24"
                                        >
                                            <Panel
                                                eyebrow="03"
                                                title="Media"
                                                description="Upload something new or pick from the client's library — the number is the order it appears in."
                                                className={flash('media')}
                                                actions={
                                                    <span className="text-muted-foreground font-mono text-xs">
                                                        {
                                                            selectedAssetIds.length
                                                        }{' '}
                                                        attached
                                                    </span>
                                                }
                                            >
                                                <MediaPicker
                                                    clientId={client.id}
                                                    campaignId={
                                                        campaignId
                                                            ? Number(campaignId)
                                                            : undefined
                                                    }
                                                    selected={selectedAssets}
                                                    onChange={setAttachedAssets}
                                                    canUpload={can.create_asset}
                                                    uploading={isUploadingAsset}
                                                    onUpload={(file) =>
                                                        void handleAssetUpload(
                                                            file,
                                                        )
                                                    }
                                                />
                                                <MediaDetails
                                                    assets={selectedAssets}
                                                    altTexts={altTexts}
                                                    onAltTextChange={(
                                                        id,
                                                        text,
                                                    ) =>
                                                        setAltTexts(
                                                            (current) => ({
                                                                ...current,
                                                                [id]: text,
                                                            }),
                                                        )
                                                    }
                                                    coverAssetId={coverAssetId}
                                                    onCoverChange={
                                                        setCoverAssetId
                                                    }
                                                    thumbOffsetMs={
                                                        thumbOffsetMs
                                                    }
                                                    onThumbOffsetChange={
                                                        setThumbOffsetMs
                                                    }
                                                    altTextPlatforms={
                                                        altTextPlatforms
                                                    }
                                                />
                                                {selectedAssetIds.map((id) => (
                                                    <input
                                                        key={id}
                                                        type="hidden"
                                                        name={`asset_alt_texts[${id}]`}
                                                        value={
                                                            altTexts[id] ?? ''
                                                        }
                                                    />
                                                ))}
                                                <input
                                                    type="hidden"
                                                    name="cover_asset_id"
                                                    value={coverAssetId ?? ''}
                                                />
                                                <input
                                                    type="hidden"
                                                    name="thumb_offset_ms"
                                                    value={thumbOffsetMs ?? ''}
                                                />
                                            </Panel>
                                        </div>

                                        <Collapsible
                                            open={settingsOpen}
                                            onOpenChange={setSettingsOpen}
                                            ref={sectionRef('settings')}
                                            className="scroll-mt-24"
                                        >
                                            <Panel
                                                eyebrow="04"
                                                title="Post settings"
                                                className={flash('settings')}
                                                actions={
                                                    <CollapsibleTrigger asChild>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                        >
                                                            {settingsOpen
                                                                ? 'Done'
                                                                : 'Edit'}
                                                            <ChevronDown
                                                                className={cn(
                                                                    'transition-transform',
                                                                    settingsOpen &&
                                                                        'rotate-180',
                                                                )}
                                                            />
                                                        </Button>
                                                    </CollapsibleTrigger>
                                                }
                                            >
                                                {!settingsOpen && (
                                                    <SettingsSummary
                                                        onOpen={() =>
                                                            setSettingsOpen(
                                                                true,
                                                            )
                                                        }
                                                        items={[
                                                            {
                                                                label: 'Campaign',
                                                                value:
                                                                    selectedCampaign?.name ??
                                                                    'No campaign',
                                                                muted: !selectedCampaign,
                                                            },
                                                            {
                                                                label: 'Approval',
                                                                value:
                                                                    approvalLabels[
                                                                        approvalMode
                                                                    ] ??
                                                                    approvalMode,
                                                                muted: false,
                                                            },
                                                            {
                                                                label: 'Sound',
                                                                value:
                                                                    musicName ||
                                                                    'No sound',
                                                                muted: !musicName,
                                                            },
                                                            {
                                                                label: 'Location',
                                                                value:
                                                                    locationName ||
                                                                    'No location',
                                                                muted: !locationName,
                                                            },
                                                        ]}
                                                    />
                                                )}
                                                {/* Kept mounted while folded so every field still submits. */}
                                                <CollapsibleContent
                                                    forceMount
                                                    className="data-[state=closed]:hidden"
                                                >
                                                    <div className="grid gap-5 sm:grid-cols-2">
                                                        <div className="grid content-start gap-2">
                                                            <CampaignField
                                                                postId={post.id}
                                                                value={
                                                                    campaignId
                                                                }
                                                                options={
                                                                    campaignOptions
                                                                }
                                                                caption={
                                                                    caption
                                                                }
                                                                hashtags={
                                                                    hashtags
                                                                }
                                                                error={
                                                                    errors.campaign_id
                                                                }
                                                                onChange={
                                                                    setCampaignId
                                                                }
                                                                onAddHashtags={(
                                                                    tags,
                                                                ) =>
                                                                    setHashtags(
                                                                        (
                                                                            current,
                                                                        ) => [
                                                                            ...current,
                                                                            ...tags,
                                                                        ],
                                                                    )
                                                                }
                                                                onAddLink={(
                                                                    campaignLink,
                                                                ) =>
                                                                    setCaption(
                                                                        (
                                                                            current,
                                                                        ) =>
                                                                            current.trim() ===
                                                                            ''
                                                                                ? campaignLink
                                                                                : `${current.trimEnd()}\n\n${campaignLink}`,
                                                                    )
                                                                }
                                                            />
                                                            <input
                                                                type="hidden"
                                                                name="campaign_id"
                                                                value={
                                                                    campaignId
                                                                }
                                                            />
                                                        </div>
                                                        <div className="grid content-start gap-2">
                                                            <Label htmlFor="approval_mode">
                                                                Approval
                                                            </Label>
                                                            <Select
                                                                value={
                                                                    approvalMode
                                                                }
                                                                onValueChange={
                                                                    setApprovalMode
                                                                }
                                                            >
                                                                <SelectTrigger id="approval_mode">
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem value="client_required">
                                                                        Client
                                                                        approval
                                                                        required
                                                                    </SelectItem>
                                                                    <SelectItem value="internal_only">
                                                                        Internal
                                                                        approval
                                                                        only
                                                                    </SelectItem>
                                                                </SelectContent>
                                                            </Select>
                                                            <input
                                                                type="hidden"
                                                                name="approval_mode"
                                                                value={
                                                                    approvalMode
                                                                }
                                                            />
                                                            <InputError
                                                                message={
                                                                    errors.approval_mode
                                                                }
                                                            />
                                                        </div>
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
                                                                clientId={
                                                                    post.client_id
                                                                }
                                                                platforms={
                                                                    musicPlatforms
                                                                }
                                                                instagramAccountId={
                                                                    selectedAccounts.find(
                                                                        (a) =>
                                                                            a.platform ===
                                                                            'instagram',
                                                                    )?.id ??
                                                                    null
                                                                }
                                                                musicId={
                                                                    musicId
                                                                }
                                                                musicName={
                                                                    musicName
                                                                }
                                                                onChange={(
                                                                    track,
                                                                ) => {
                                                                    setMusicId(
                                                                        track.id,
                                                                    );
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
                                                                dir="auto"
                                                                value={
                                                                    locationName
                                                                }
                                                                onChange={(e) =>
                                                                    setLocationName(
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder="e.g. Shoreditch, London"
                                                            />
                                                        </div>
                                                    </div>
                                                </CollapsibleContent>
                                            </Panel>
                                        </Collapsible>

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
                            onFix={can.update ? applyFix : undefined}
                            onJump={
                                can.update
                                    ? (key) =>
                                          jumpTo(checkSection[key] ?? 'compose')
                                    : undefined
                            }
                            note={
                                isDirty
                                    ? 'Reflects your unsaved edits — save before scheduling.'
                                    : undefined
                            }
                        />
                        <div
                            ref={sectionRef('preview')}
                            className={cn(
                                'scroll-mt-24 rounded-2xl',
                                flash('preview'),
                            )}
                        >
                            <PreviewPanel
                                targets={previewTargets}
                                activeId={can.update ? previewId : undefined}
                                onActiveChange={(id) => {
                                    setPreviewId(id);

                                    // While customizing a channel, the preview and composer move together.
                                    if (activeScope !== 'all') {
                                        setScope(id);
                                    }
                                }}
                                caption={
                                    can.update
                                        ? caption
                                        : (post.master_caption ?? '')
                                }
                                hashtags={can.update ? hashtags : post.hashtags}
                                assets={
                                    can.update ? selectedAssets : post.assets
                                }
                                musicName={
                                    can.update
                                        ? musicName
                                        : ((post.music?.name as string) ?? '')
                                }
                                locationName={
                                    can.update
                                        ? locationName
                                        : ((post.location?.name as string) ??
                                          '')
                                }
                                mediaFormat={
                                    can.update ? mediaFormat : post.media_format
                                }
                                crops={
                                    can.update ? selectedCrops : initial.crops
                                }
                                formatOptions={mediaFormatOptions}
                                editable={can.update}
                                onMediaFormatChange={setMediaFormat}
                                onCropsChange={(next) =>
                                    setCrops((current) => ({
                                        ...current,
                                        ...next,
                                    }))
                                }
                            />
                        </div>
                    </aside>
                </div>

                {imageStudio && (
                    <div
                        id="image-studio"
                        className="border-foreground scroll-mt-20 border-t pt-8"
                    >
                        <ImageStudio data={imageStudio} />
                    </div>
                )}
            </Page>
        </>
    );
}
