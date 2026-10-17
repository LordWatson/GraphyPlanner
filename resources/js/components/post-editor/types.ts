/** Prop shapes sent by `PostController::edit` to the `posts/edit` page. */

export type ChecklistItem = { key: string; label: string; passed: boolean };

export type Checklist = { items: ChecklistItem[]; passed: boolean };

export type TargetData = {
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

export type ActivityLogData = {
    id: number;
    user_name: string | null;
    from_status: string | null;
    to_status: string;
    note: string | null;
    review_url: string | null;
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
    original_filename: string | null;
    type: string | null;
    /** Made in the AI Image Studio (Phase 16): shown with an "AI" label tag. */
    is_ai_generated?: boolean;
};

export type PostData = {
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
    timezone: string;
    caption_limit: number;
    max_hashtags: number;
    is_text_only_capable: boolean;
};

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
