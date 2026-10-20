import type { MentionableUser } from '@/components/mention-textarea';
import type { ActivityLogData } from '@/components/post-editor/types';
import type { GridSplitGroup, GridTileFrameResult } from '@/lib/grid-layout';
import type { Crop } from '@/lib/media-format';

/**
 * Phase 18 — the grid planner's page props, as `GridPlanPresenter` sends them. The client-facing
 * shape (`ClientGridPlan`) is the staff one minus everything internal; it's what the review link
 * and the portal receive.
 */

export type GridAsset = {
    id: number;
    url: string | null;
    thumbnail_url: string | null;
    type: string | null;
    width: number | null;
    height: number | null;
    original_filename: string | null;
    alt_text: string | null;
    crop: Crop;
};

export type GridTileKind = 'image' | 'carousel' | 'reel' | 'placeholder';

export type ClientGridTile = {
    id: number;
    position: number;
    kind: GridTileKind;
    kind_label: string;
    format: string;
    slot: number | null;
    row: number | null;
    column: number | null;
    /** 1 = goes out first. */
    sequence: number | null;
    split_group_id: string | null;
    placeholder_colour: string | null;
    planned_local_date: string | null;
    planned_local_time: string | null;
    /** UTC, ISO-8601, when known. */
    publish_at: string | null;
    assets: GridAsset[];
    cover: GridAsset | null;
    frame: GridTileFrameResult | null;
    /** The caption idea, or the linked post's caption once it has one. */
    caption: string | null;
    live: boolean;
};

export type GridTileData = ClientGridTile & {
    team_note: string | null;
    caption_idea: string | null;
    post: {
        id: number;
        status: string;
        status_label: string;
        target_status: string | null;
    } | null;
    /** Step 18.11: what changed since the client approved the grid. */
    drift: string[];
};

export type GridOutsideTile = {
    post_id: number;
    slot: number;
    row: number;
    column: number;
    publish_at: string | null;
    thumbnail_url: string | null;
    asset: GridAsset | null;
};

export type GridProgress = {
    tiles: number;
    posts: number;
    approved: number;
    scheduled: number;
    live: number;
    failed: number;
    stage: 'in_production' | 'live' | null;
};

export type GridChecklistItem = {
    key: string;
    label: string;
    passed: boolean;
    severity: 'hard' | 'soft';
    soft: boolean;
    detail: string | null;
    tile_ids: number[];
};

export type GridChecklist = { items: GridChecklistItem[]; passed: boolean };

export type ClientGridPlan = {
    id: number;
    name: string;
    status: string;
    status_label: string;
    version: number;
    pinned_count: number;
    include_live_feed: boolean;
    review_message: string | null;
    account: {
        id: number;
        handle: string;
        display_name: string | null;
        timezone: string;
        connection_status?: string;
    };
    approved_at: string | null;
    tiles: ClientGridTile[];
    outside: GridOutsideTile[];
    split_groups: GridSplitGroup[];
    progress: GridProgress;
};

export type GridPlanData = Omit<ClientGridPlan, 'tiles'> & {
    tiles: GridTileData[];
    campaign: {
        id: number;
        name: string;
        code: string | null;
        colour: string | null;
    } | null;
    campaign_id: number | null;
    client_id: number;
    approved_by_name: string | null;
    checklist: GridChecklist;
    layout_drifted: boolean;
    sequence_warnings: string[];
    order_risk: {
        tile_id: number;
        position: number;
        reason: string;
        later_post_ids: number[];
    } | null;
};

export type ProfileFeedItem = {
    id: string;
    media_type: string;
    thumbnail_url: string | null;
    permalink: string | null;
    published_at: string | null;
};

export type ProfileFeed = {
    username: string;
    profile_picture_url: string | null;
    media_count: number | null;
    source: 'meta' | 'app' | 'none';
    items: ProfileFeedItem[];
};

export type GridCommentData = {
    id: number;
    user_name: string | null;
    body: string;
    internal_only: boolean;
    grid_tile_id: number | null;
    tile_position: number | null;
    mentioned_user_ids: number[];
    created_at: string | null;
};

export type StudioImage = {
    variant_id: number;
    asset_id: number;
    thumbnail_url: string | null;
    width: number | null;
    height: number | null;
};

export type GridSummary = {
    id: number;
    name: string;
    status: string;
    status_label: string;
    handle: string;
    campaign?: { id: number; name: string; colour: string | null } | null;
    tile_count: number;
    progress: GridProgress;
    updated_at: string | null;
    preview: {
        id: number;
        kind: GridTileKind;
        placeholder_colour: string | null;
        thumbnail_url: string | null;
        frame: GridTileFrameResult | null;
    }[];
};

export type GridReport = {
    title: string;
    skipped: {
        reason: string;
        position?: number | null;
        tile_id?: number;
        id?: number;
        status?: string;
    }[];
};

export type GridEditorProps = {
    grid: GridPlanData;
    client: { id: number; name: string };
    allowedTransitions: { value: string; label: string; forward: boolean }[];
    can: {
        update: boolean;
        comment: boolean;
        delete: boolean;
        produce: boolean;
        override_approval: boolean;
        create_asset: boolean;
        use_ai: boolean;
    };
    campaignOptions: { value: string; label: string }[];
    placeholderColours: { value: string; label: string }[];
    mentionableUsers: MentionableUser[];
    minGapMinutes: number;
    feed?: ProfileFeed;
    comments?: GridCommentData[];
    activity?: ActivityLogData[];
    studioImages?: StudioImage[];
};
