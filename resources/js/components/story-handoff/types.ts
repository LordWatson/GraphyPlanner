/** Prop shapes sent by `ShowStoryHandoffAction::payload()` to the `story-handoff/show` page. */

export type HandoffState =
    | 'ready'
    | 'posted'
    | 'missed'
    | 'problem'
    | 'published_plain'
    | 'cancelled';

export type HandoffExtra = { key: string; label: string; value: string };

export type HandoffMedia = {
    kind: 'image' | 'video';
    /** The token-gated media route: the cropped image, or the video as uploaded. */
    url: string;
    mime_type: string;
    filename: string;
    size: number | null;
    /** Small enough to hold in memory for the share sheet. */
    shareable: boolean;
};

export type HandoffData = {
    state: HandoffState;
    /** Ready, and the sweep is telling the poster later than it should have. */
    late: boolean;
    /** Ready, but its time hasn't come yet (opened early from inside Alima). */
    early: boolean;
    due_at: string;
    /** The due time on the channel's own clock. */
    due: { date: string; time: string; timezone: string };
    client: { name: string | null };
    account: { platform: string; platform_label: string; handle: string };
    posted_by: 'team' | 'client';
    poster_name: string | null;
    extras: HandoffExtra[];
    /** The sticker link, when there is one: what goes on the clipboard with the share. */
    link: string | null;
    placement_note: string | null;
    media: HandoffMedia | null;
    completed: { at: string; by: string | null } | null;
    can_confirm_late: boolean;
};

export type NextStory = {
    url: string;
    handle: string | null;
    client_name: string | null;
};
