/**
 * The one shared status → tone mapping for every pill in the app (brand guidelines §3). Pages
 * never define their own `statusVariant` records — they render `<StatusPill kind=… value=… />`.
 *
 * Labels mirror the backend enums' `label()` so a pill reads the same whether the controller sent
 * a label or only the raw value.
 */
export type StatusTone =
    | 'neutral'
    | 'info'
    | 'warning'
    | 'success'
    | 'danger'
    | 'muted'
    | 'brand';

export type StatusKind =
    | 'post'
    | 'gridPlan'
    | 'postTarget'
    | 'client'
    | 'health'
    | 'campaign'
    | 'invoice'
    | 'connection'
    | 'role'
    | 'approval'
    | 'invitation'
    | 'imageGeneration'
    | 'imageVariant'
    | 'organization'
    | 'scheduler'
    | 'brief'
    | 'deliverable'
    | 'timeline'
    | 'clientReport'
    | 'inbox'
    | 'sentiment';

type StatusDefinition = { tone: StatusTone; label: string };

export const statusMap: Record<StatusKind, Record<string, StatusDefinition>> = {
    post: {
        idea: { tone: 'muted', label: 'Idea' },
        draft: { tone: 'neutral', label: 'Draft' },
        internal_review: { tone: 'info', label: 'Internal review' },
        waiting_client: { tone: 'info', label: 'Waiting on client' },
        changes_requested: { tone: 'warning', label: 'Changes requested' },
        approved: { tone: 'success', label: 'Approved' },
        scheduled: { tone: 'brand', label: 'Scheduled' },
        publishing: { tone: 'warning', label: 'Publishing' },
        published: { tone: 'success', label: 'Published' },
        partially_published: { tone: 'warning', label: 'Partially published' },
        failed: { tone: 'danger', label: 'Failed' },
        archived: { tone: 'muted', label: 'Archived' },
    },
    // Phase 18: a grid plan's approval stage — the post tones for the same-named statuses, so a
    // grid and a post "Waiting on client" read identically. `in_production`/`live` are derived.
    gridPlan: {
        draft: { tone: 'neutral', label: 'Draft' },
        internal_review: { tone: 'info', label: 'Internal review' },
        waiting_client: { tone: 'info', label: 'Waiting on client' },
        changes_requested: { tone: 'warning', label: 'Changes requested' },
        approved: { tone: 'success', label: 'Approved' },
        in_production: { tone: 'brand', label: 'In production' },
        live: { tone: 'success', label: 'Live' },
        archived: { tone: 'muted', label: 'Archived' },
    },
    postTarget: {
        pending: { tone: 'neutral', label: 'Pending' },
        published: { tone: 'success', label: 'Published' },
        failed: { tone: 'danger', label: 'Failed' },
    },
    client: {
        active: { tone: 'success', label: 'Active' },
        paused: { tone: 'warning', label: 'Paused' },
        offboarding: { tone: 'neutral', label: 'Offboarding' },
        archived: { tone: 'muted', label: 'Archived' },
    },
    health: {
        green: { tone: 'success', label: 'Green' },
        amber: { tone: 'warning', label: 'Amber' },
        red: { tone: 'danger', label: 'Red' },
    },
    campaign: {
        planning: { tone: 'neutral', label: 'Planning' },
        active: { tone: 'success', label: 'Active' },
        paused: { tone: 'warning', label: 'Paused' },
        completed: { tone: 'info', label: 'Completed' },
        archived: { tone: 'muted', label: 'Archived' },
    },
    // Campaigns enhancement C-2.1: a brief's sign-off.
    brief: {
        draft: { tone: 'neutral', label: 'Draft' },
        sent: { tone: 'info', label: 'With the client' },
        approved: { tone: 'success', label: 'Approved' },
        changes_requested: { tone: 'warning', label: 'Changes requested' },
        updating: { tone: 'neutral', label: 'Being updated' },
    },
    // C-3.2: a planned deliverable's state, derived from its post.
    deliverable: {
        not_started: { tone: 'muted', label: 'Not started' },
        in_production: { tone: 'neutral', label: 'In production' },
        in_review: { tone: 'info', label: 'In review' },
        approved: { tone: 'success', label: 'Approved' },
        scheduled: { tone: 'brand', label: 'Scheduled' },
        published: { tone: 'success', label: 'Published' },
        failed: { tone: 'danger', label: 'Failed' },
        dropped: { tone: 'muted', label: 'Dropped' },
    },
    // C-0.4: where a campaign sits in time (from its dates, never its status).
    timeline: {
        upcoming: { tone: 'info', label: 'Upcoming' },
        live: { tone: 'success', label: 'Live' },
        ended: { tone: 'muted', label: 'Ended' },
        undated: { tone: 'neutral', label: 'No dates' },
    },
    invoice: {
        draft: { tone: 'neutral', label: 'Draft' },
        sent: { tone: 'info', label: 'Sent' },
        // Step M-4.2: the client reported payment; the agency hasn't confirmed it yet.
        pending_confirmation: { tone: 'warning', label: 'Payment reported' },
        paid: { tone: 'success', label: 'Paid' },
        // Derived by the backend (`Invoice::displayStatus()`): sent and past its due date.
        overdue: { tone: 'danger', label: 'Overdue' },
        void: { tone: 'muted', label: 'Void' },
    },
    connection: {
        connected: { tone: 'success', label: 'Connected' },
        token_expired: { tone: 'warning', label: 'Token expired' },
        not_connected: { tone: 'neutral', label: 'Not connected' },
    },
    role: {
        owner: { tone: 'brand', label: 'Owner' },
        strategist: { tone: 'neutral', label: 'Strategist' },
        designer: { tone: 'neutral', label: 'Designer' },
        client_reviewer: { tone: 'info', label: 'Client reviewer' },
        viewer: { tone: 'muted', label: 'Viewer' },
        super_admin: { tone: 'brand', label: 'Super admin' },
    },
    approval: {
        approved: { tone: 'success', label: 'Approved' },
        changes_requested: { tone: 'warning', label: 'Changes requested' },
    },
    invitation: {
        pending: { tone: 'info', label: 'Pending' },
        accepted: { tone: 'success', label: 'Accepted' },
        revoked: { tone: 'muted', label: 'Revoked' },
        expired: { tone: 'neutral', label: 'Expired' },
    },
    // AI Image Studio (Phase 16): a set, and one tile within it.
    imageGeneration: {
        queued: { tone: 'neutral', label: 'Queued' },
        generating: { tone: 'info', label: 'Generating' },
        ready: { tone: 'success', label: 'Ready' },
        partially_failed: { tone: 'warning', label: 'Partly ready' },
        failed: { tone: 'danger', label: 'Failed' },
    },
    imageVariant: {
        queued: { tone: 'neutral', label: 'Queued' },
        generating: { tone: 'info', label: 'Drafting' },
        ready: { tone: 'success', label: 'Ready' },
        failed: { tone: 'danger', label: 'Failed' },
    },
    // Super admin console (Phase 15): an organization's platform status.
    // Step I-1.8: super admin system health — scheduler heartbeats.
    scheduler: {
        ok: { tone: 'success', label: 'Running' },
        stale: { tone: 'danger', label: 'Stale' },
    },
    // Step M-3.3: a monthly client report.
    clientReport: {
        draft: { tone: 'neutral', label: 'Draft' },
        published: { tone: 'success', label: 'Published' },
    },
    organization: {
        active: { tone: 'success', label: 'Active' },
        suspended: { tone: 'danger', label: 'Suspended' },
        preview: { tone: 'muted', label: 'Preview fixture' },
    },
    // Phase M-9: a social-inbox conversation's workflow state.
    inbox: {
        open: { tone: 'info', label: 'Open' },
        snoozed: { tone: 'warning', label: 'Snoozed' },
        resolved: { tone: 'success', label: 'Resolved' },
    },
    // Phase M-9: a comment's triage sentiment (set deterministically at ingestion).
    sentiment: {
        positive: { tone: 'success', label: 'Positive' },
        neutral: { tone: 'neutral', label: 'Neutral' },
        negative: { tone: 'danger', label: 'Negative' },
        question: { tone: 'info', label: 'Question' },
    },
};

function humanize(value: string): string {
    const spaced = value.replace(/_/g, ' ');

    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

export function resolveStatus(
    kind: StatusKind,
    value: string,
): StatusDefinition {
    return (
        statusMap[kind][value] ?? { tone: 'neutral', label: humanize(value) }
    );
}

export const toneBadgeVariant = {
    neutral: 'secondary',
    info: 'info',
    warning: 'warning',
    success: 'success',
    danger: 'destructive',
    muted: 'outline',
    brand: 'default',
} as const satisfies Record<StatusTone, string>;

/** Text colour per tone — for dots, icons and calendar chips outside a Badge. */
export const toneTextClass: Record<StatusTone, string> = {
    neutral: 'text-foreground/60',
    info: 'text-info',
    warning: 'text-warning',
    success: 'text-success',
    danger: 'text-destructive',
    muted: 'text-muted-foreground',
    brand: 'text-primary',
};

/** Solid fill per tone — for dots, bars and calendar markers. */
export const toneBgClass: Record<StatusTone, string> = {
    neutral: 'bg-foreground/40',
    info: 'bg-info',
    warning: 'bg-warning',
    success: 'bg-success',
    danger: 'bg-destructive',
    muted: 'bg-muted-foreground/50',
    brand: 'bg-primary',
};
