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
    | 'postTarget'
    | 'client'
    | 'health'
    | 'campaign'
    | 'invoice'
    | 'connection'
    | 'role'
    | 'approval';

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
        failed: { tone: 'danger', label: 'Failed' },
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
        completed: { tone: 'info', label: 'Completed' },
        archived: { tone: 'muted', label: 'Archived' },
    },
    invoice: {
        draft: { tone: 'neutral', label: 'Draft' },
        sent: { tone: 'info', label: 'Sent' },
        paid: { tone: 'success', label: 'Paid' },
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
