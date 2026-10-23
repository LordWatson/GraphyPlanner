/** Step M-6.4 — shared shapes for the visual-feedback board (editor, portal, review link). */

export type FeedbackAnchorType = 'image' | 'video' | 'caption';

export type FeedbackAnchor = {
    asset_id?: number;
    x?: number;
    y?: number;
    t?: number;
    channel?: string | null;
    start?: number;
    end?: number;
    quote?: string;
};

export type FeedbackReply = {
    id: number;
    author: string | null;
    body: string;
    created_at: string | null;
};

export type FeedbackThread = {
    id: number;
    author: string | null;
    body: string;
    internal_only: boolean;
    anchor_type: FeedbackAnchorType | null;
    anchor: FeedbackAnchor | null;
    suggested_edit: string | null;
    suggestion_resolved: boolean;
    resolved: boolean;
    resolved_by: string | null;
    is_feedback: boolean;
    created_at: string | null;
    can: { resolve: boolean; accept_suggestion: boolean };
    replies: FeedbackReply[];
};

export type FeedbackAsset = {
    id: number;
    url: string | null;
    thumbnail_url?: string | null;
    type?: string | null;
    mime_type?: string | null;
};

/** How the board talks back to the server — URLs built by the host page for its own routes. */
export type FeedbackRoutes = {
    create: string;
    /** Toggle resolved: the board posts `{ resolved: boolean }`. */
    resolve: (commentId: number) => string;
    acceptSuggestion?: (commentId: number) => string;
    dismissSuggestion?: (commentId: number) => string;
};

export type FeedbackDraft = {
    anchorType: FeedbackAnchorType;
    anchor: FeedbackAnchor;
    label: string;
};

/** Format seconds as m:ss for a video timestamp chip. */
export function formatTimestamp(seconds: number): string {
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);
    return `${m}:${s.toString().padStart(2, '0')}`;
}
