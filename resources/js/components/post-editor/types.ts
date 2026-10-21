import type { CampaignChipData } from '@/components/campaigns/types';
import type { Crop, MediaFormat } from '@/lib/media-format';

/** Prop shapes sent by `PostController::edit` to the `posts/edit` page. */

/** A one-click correction the checklist offers (Step M-2.3 / M-2.4). */
export type ChecklistFix = {
    action: 'format' | 'media_format';
    label: string;
    value: string;
    social_account_id?: number;
    platform?: string;
};

export type ChecklistItem = {
    key: string;
    label: string;
    passed: boolean;
    /** Campaigns enhancement C-4.2: a warning, never a blocker. */
    soft?: boolean;
    /** Which channels fail and why, one per line. */
    detail?: string;
    fixes?: ChecklistFix[];
};

export type Checklist = { items: ChecklistItem[]; passed: boolean };

export type TargetData = {
    id: number;
    social_account_id: number;
    platform: string | null;
    handle: string | null;
    display_name?: string | null;
    caption_limit: number | null;
    is_text_only_capable: boolean;
    scheduled_local_date: string | null;
    scheduled_local_time: string | null;
    scheduled_at_utc: string | null;
    status: string;
    status_label: string;
    external_post_id: string | null;
    error: string | null;
    /** Step M-2.2: this channel's overrides (null = uses the master). */
    caption_override: string | null;
    hashtags_override: string[] | null;
    first_comment: string | null;
    link_url: string | null;
    /** Step M-2.3: the explicit format (null = Auto) and what it resolves to. */
    format: string | null;
    resolved_format: string | null;
    published_caption: string;
    is_customized: boolean;
};

export type ActivityLogData = {
    id: number;
    user_name: string | null;
    from_status: string | null;
    to_status: string;
    note: string | null;
    /** A review-portal link was emailed with this move (the link itself is never stored). */
    review_link_sent: boolean;
    created_at: string | null;
};

export type ApprovalData = {
    id: number;
    user_name: string | null;
    decision: string;
    comment: string | null;
    created_at: string | null;
};

export type CommentData = {
    id: number;
    user_name: string | null;
    body: string;
    internal_only: boolean;
    mentioned_user_ids: number[];
    created_at: string | null;
};

export type AssetData = {
    id: number;
    url: string | null;
    /** Step M-2.4: a small preview for lists and grids (`ProbeAssetJob`), when made. */
    thumbnail_url?: string | null;
    size?: number | null;
    duration_ms?: number | null;
    /** Alt text on this post (`post_assets.alt_text`). */
    alt_text?: string | null;
    original_filename: string | null;
    type: string | null;
    /** Made in the AI Image Studio (Phase 16): shown with an "AI" label tag. */
    is_ai_generated?: boolean;
    width?: number | null;
    height?: number | null;
    /** Focus point + zoom on this post (`post_assets.crop_*`). */
    crop?: Crop | null;
};

export type PostData = {
    id: number;
    client_id: number;
    campaign_id: number | null;
    campaign_name: string | null;
    campaign: CampaignChipData | null;
    status: string;
    status_label: string;
    approval_mode: string;
    master_caption: string | null;
    review_message: string | null;
    hashtags: string[];
    /** Step M-2.2: the master link and first comment every channel inherits. */
    link_url: string | null;
    first_comment: string | null;
    /** Step M-2.3: a video's cover image and frame offset. */
    cover_asset_id: number | null;
    thumb_offset_ms: number | null;
    music: Record<string, unknown> | null;
    location: Record<string, unknown> | null;
    /** Image format per channel, defaults filled in. */
    media_format: MediaFormat;
    checklist: Checklist;
    assets: AssetData[];
    targets: TargetData[];
    activity_logs: ActivityLogData[];
    approvals: ApprovalData[];
    comments: CommentData[];
};

export type TargetAccountData = {
    id: number;
    platform: string;
    platform_label: string;
    handle: string;
    display_name?: string | null;
    timezone: string;
    caption_limit: number;
    max_hashtags: number;
    is_text_only_capable: boolean;
    /** Step M-2.6: publishing switched on for this platform (rollout flag). */
    enabled?: boolean;
    connection_status?: string;
    /** Required registry settings this account hasn't filled in (e.g. a Pinterest board). */
    missing_settings?: string[];
    /** Step M-3.2: best slots from the account's own engagement (`BestTimeService`). */
    best_time?: BestTime | null;
};

export type BestTime = {
    status: 'ready' | 'learning';
    sample: number;
    needed: number;
    ranked_by: 'rate' | 'interactions' | null;
    slots: {
        weekday: number;
        hour: number;
        block_hours: number;
        label: string;
        posts: number;
        score: number;
    }[];
};

/**
 * Step M-2.2 / M-2.3 — one channel's own content in the editor. `null` = uses the master; an
 * empty string / list = deliberately none on this channel.
 */
export type ChannelState = {
    caption: string | null;
    hashtags: string[] | null;
    firstComment: string | null;
    link: string | null;
    format: string | null;
};

export const EMPTY_CHANNEL: ChannelState = {
    caption: null,
    hashtags: null,
    firstComment: null,
    link: null,
    format: null,
};

export function isCustomized(channel: ChannelState | undefined): boolean {
    return Boolean(
        channel &&
        (channel.caption !== null ||
            channel.hashtags !== null ||
            channel.firstComment !== null ||
            channel.link !== null),
    );
}

export type TransitionOption = { value: string; label: string };

/** Local date/time per selected social account id — never one shared value (spec §4.6). */
export type ScheduleRows = Record<number, { date: string; time: string }>;

/** "Fri 16 Oct · 14:30" — for display only; stored values stay local `Y-m-d` / `H:i`. */
export function formatLocalSchedule(
    date: string | null | undefined,
    time: string | null | undefined,
): string | null {
    if (!date) {
        return null;
    }

    const parsed = new Date(`${date}T00:00:00`);
    const day = Number.isNaN(parsed.getTime())
        ? date
        : parsed.toLocaleDateString(undefined, {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
          });

    return time ? `${day} · ${time.slice(0, 5)}` : day;
}

export function formatTimestamp(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const parsed = new Date(iso);

    if (Number.isNaN(parsed.getTime())) {
        return iso;
    }

    return parsed.toLocaleString(undefined, {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}
