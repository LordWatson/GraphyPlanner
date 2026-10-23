import type {
    AssetData,
    ChannelState,
    PostData,
    ScheduleRows,
    TargetAccountData,
} from '@/components/post-editor/types';
import { EMPTY_CHANNEL } from '@/components/post-editor/types';
import type { Crop, MediaFormat } from '@/lib/media-format';
import { DEFAULT_CROP, normalizeCrop } from '@/lib/media-format';

/** Everything the editor form can change before it's saved. */
export type EditorState = {
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
    /** Step M-5.4: who owns the work, the internal reviewer, and the internal due date (YYYY-MM-DD). */
    assigneeId: string;
    reviewerId: string;
    dueDate: string;
};

export type EditorSection =
    | 'compose'
    | 'channels'
    | 'media'
    | 'settings'
    | 'preview';

/** Which part of the editor fixes each readiness check — the checklist's "jump to" target. */
export const checkSection: Record<string, EditorSection> = {
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

export const approvalLabels: Record<string, string> = {
    client_required: 'Client approves',
    internal_only: 'Internal only',
};

/**
 * The editor's starting point: what's on the server. A target whose account was disconnected
 * (soft-deleted) isn't selectable, so it's left out of the editable channels — otherwise it would
 * be re-sent, invisibly, and fail validation. Saving drops it.
 */
export function initialEditorState(
    post: PostData,
    targetAccounts: TargetAccountData[],
): EditorState {
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
        assigneeId: post.assignee_id ? String(post.assignee_id) : '',
        reviewerId: post.reviewer_id ? String(post.reviewer_id) : '',
        dueDate: post.due_at ? post.due_at.slice(0, 10) : '',
    };
}

/**
 * What saving would send: only attached media's framing and alt text, and only selected
 * channels' overrides — so removing an image or a channel drops its edits too. Dirty-checking
 * compares this against the initial state.
 */
export function normalizeEditorState(state: EditorState): EditorState {
    return {
        ...state,
        crops: Object.fromEntries(
            state.assetIds.map((id) => [id, state.crops[id] ?? DEFAULT_CROP]),
        ),
        channels: Object.fromEntries(
            Object.keys(state.schedule).map((id) => [
                Number(id),
                state.channels[Number(id)] ?? EMPTY_CHANNEL,
            ]),
        ),
        altTexts: Object.fromEntries(
            state.assetIds
                .filter((id) => (state.altTexts[id] ?? '') !== '')
                .map((id) => [id, state.altTexts[id]]),
        ),
    };
}

export function isEditorDirty(
    state: EditorState,
    initial: EditorState,
): boolean {
    return (
        JSON.stringify(normalizeEditorState(state)) !== JSON.stringify(initial)
    );
}

/** `image`/`video` per item that publishes: a post with a video sends just that video. */
export function mediaKindsOf(assets: AssetData[]): string[] {
    const isVideo = (asset: AssetData) =>
        (asset.type ?? '').startsWith('video');
    const isImage = (asset: AssetData) =>
        (asset.type ?? '').startsWith('image');

    if (assets.some(isVideo)) {
        return ['video'];
    }

    return assets.filter(isImage).map(() => 'image');
}
