import type { LucideIcon } from 'lucide-react';
import {
    Archive,
    ArchiveRestore,
    CalendarClock,
    CheckCircle2,
    MessageSquareWarning,
    PenLine,
    RefreshCw,
    Rocket,
    Send,
    Undo2,
    UserCheck,
    XCircle,
} from 'lucide-react';

/**
 * Presentation layer for the post status state machine (`PostStatusTransitionMap` on the server
 * stays the source of truth for which moves are legal). This file only decides how a legal move
 * reads in the editor: which lifecycle stage a status belongs to, the verb on each button, and
 * which single move is the recommended next step.
 */

export type WorkflowStage = {
    key: string;
    label: string;
    statuses: string[];
};

/** The happy-path lifecycle shown as the stage track at the top of the editor. */
export const workflowStages: WorkflowStage[] = [
    { key: 'draft', label: 'Draft', statuses: ['idea', 'draft'] },
    {
        key: 'internal',
        label: 'Internal review',
        statuses: ['internal_review'],
    },
    {
        key: 'client',
        label: 'Client review',
        statuses: ['waiting_client', 'changes_requested'],
    },
    { key: 'approved', label: 'Approved', statuses: ['approved'] },
    { key: 'scheduled', label: 'Scheduled', statuses: ['scheduled'] },
    {
        key: 'live',
        label: 'Live',
        statuses: ['publishing', 'published', 'partially_published', 'failed'],
    },
];

/** Index into `workflowStages`, or -1 for off-track statuses (archived). */
export function stageIndexFor(status: string): number {
    return workflowStages.findIndex((stage) => stage.statuses.includes(status));
}

/**
 * - `forward`: progresses the post (shown as buttons).
 * - `back` / `system` / `archive`: rarer moves, tucked into the "More" menu.
 */
export type TransitionIntent = 'forward' | 'back' | 'system' | 'archive';

export type TransitionAction = {
    to: string;
    label: string;
    description: string;
    intent: TransitionIntent;
    icon: LucideIcon;
    notePlaceholder: string;
};

export function describeTransition(
    from: string,
    to: string,
    clientName: string,
): TransitionAction {
    switch (to) {
        case 'draft':
            if (from === 'idea') {
                return {
                    to,
                    label: 'Start drafting',
                    description:
                        'Turns the idea into a working draft the team can build out.',
                    intent: 'forward',
                    icon: PenLine,
                    notePlaceholder: 'Anything the team should know?',
                };
            }

            if (from === 'archived') {
                return {
                    to,
                    label: 'Restore to draft',
                    description:
                        'Brings the post back into the workflow as a draft.',
                    intent: 'forward',
                    icon: ArchiveRestore,
                    notePlaceholder: 'Why is this coming back?',
                };
            }

            return {
                to,
                label: 'Back to draft',
                description:
                    'Pulls the post back to draft so it can be reworked. It will need to go through review again.',
                intent: 'back',
                icon: Undo2,
                notePlaceholder: 'What needs reworking?',
            };
        case 'internal_review':
            return {
                to,
                label:
                    from === 'changes_requested'
                        ? 'Resubmit for review'
                        : 'Send for internal review',
                description:
                    'A teammate checks the post before anything is shared with the client.',
                intent: 'forward',
                icon: UserCheck,
                notePlaceholder: 'Context for the reviewer…',
            };
        case 'waiting_client':
            return {
                to,
                label: 'Send to client',
                description: `Emails ${clientName} a secure link to approve the post or request changes.`,
                intent: 'forward',
                icon: Send,
                notePlaceholder: 'Internal note for the activity log…',
            };
        case 'approved':
            if (from === 'scheduled') {
                return {
                    to,
                    label: 'Unschedule',
                    description:
                        'Takes it off the schedule but keeps the approval. Schedule it again when you’re ready.',
                    intent: 'back',
                    icon: Undo2,
                    notePlaceholder: 'Why is it coming off the schedule?',
                };
            }

            return from === 'waiting_client'
                ? {
                      to,
                      label: 'Mark as approved',
                      description: `Records ${clientName}'s approval on their behalf — e.g. they signed off by phone or email.`,
                      intent: 'forward',
                      icon: CheckCircle2,
                      notePlaceholder: 'How did the client approve?',
                  }
                : {
                      to,
                      label: 'Approve without client',
                      description:
                          'Skips client review and marks the post approved by the studio.',
                      intent: 'forward',
                      icon: CheckCircle2,
                      notePlaceholder: 'Why is client review being skipped?',
                  };
        case 'changes_requested':
            return {
                to,
                label: 'Log changes requested',
                description: `Records that ${clientName} asked for changes, on their behalf.`,
                intent: 'forward',
                icon: MessageSquareWarning,
                notePlaceholder: 'What does the client want changed?',
            };
        case 'scheduled':
            return {
                to,
                label: from === 'failed' ? 'Retry publishing' : 'Schedule post',
                description:
                    "Queues every channel to publish at its own local date and time. Content is locked while it's scheduled.",
                intent: 'forward',
                icon: from === 'failed' ? RefreshCw : CalendarClock,
                notePlaceholder: 'Optional note…',
            };
        case 'publishing':
            return {
                to,
                label: 'Mark as publishing',
                description:
                    'Manual override — publishing normally starts automatically at the scheduled time.',
                intent: 'system',
                icon: Rocket,
                notePlaceholder: 'Why the manual override?',
            };
        case 'published':
            return {
                to,
                label: 'Mark as published',
                description:
                    'Manual override — use when the post went live outside Alima Planner.',
                intent: 'system',
                icon: CheckCircle2,
                notePlaceholder: 'Why the manual override?',
            };
        case 'failed':
            return {
                to,
                label: 'Mark as failed',
                description:
                    'Manual override — flags the publish as failed so it can be retried.',
                intent: 'system',
                icon: XCircle,
                notePlaceholder: 'What went wrong?',
            };
        case 'archived':
            return {
                to,
                label: 'Archive post',
                description:
                    'Takes the post out of the workflow. You can restore it to draft later.',
                intent: 'archive',
                icon: Archive,
                notePlaceholder: 'Why is this being archived?',
            };
        default:
            return {
                to,
                label: `Move to ${to.replace(/_/g, ' ')}`,
                description: '',
                intent: 'forward',
                icon: CheckCircle2,
                notePlaceholder: 'Optional note…',
            };
    }
}

/**
 * The one recommended move for the current status, if it's allowed for this user. Statuses that
 * are waiting on someone else (the client, the publisher) deliberately have no primary action.
 */
export function primaryTransitionFor(
    from: string,
    allowed: string[],
    approvalMode: string,
): string | null {
    const preferred: Record<string, string> = {
        idea: 'draft',
        draft: 'internal_review',
        internal_review:
            approvalMode === 'internal_only' ? 'approved' : 'waiting_client',
        changes_requested: 'internal_review',
        approved: 'scheduled',
        failed: 'scheduled',
        archived: 'draft',
    };

    const candidate = preferred[from];

    return candidate && allowed.includes(candidate) ? candidate : null;
}

/** A one-line, human read of where the post is and what happens next. */
export function statusGuidance(
    status: string,
    clientName: string,
): { headline: string; hint: string } {
    switch (status) {
        case 'idea':
            return {
                headline: 'Just an idea.',
                hint: "Flesh it out into a draft when you're ready to work on it.",
            };
        case 'draft':
            return {
                headline: 'In the works.',
                hint: 'Finish the content, then send it to a teammate for internal review.',
            };
        case 'internal_review':
            return {
                headline: 'With the studio.',
                hint: `A teammate checks it over before it goes to ${clientName}.`,
            };
        case 'waiting_client':
            return {
                headline: `With ${clientName}.`,
                hint: "They've been emailed a review link. You'll be notified when they decide.",
            };
        case 'changes_requested':
            return {
                headline: 'Changes requested.',
                hint: 'Check the feedback in the activity log, update the post, then resubmit it.',
            };
        case 'approved':
            return {
                headline: 'Approved and ready.',
                hint: 'Schedule it once every channel has a date and time.',
            };
        case 'scheduled':
            return {
                headline: 'On the schedule.',
                hint: "Queued to publish at each channel's local time. Content is locked — move it back to draft to edit.",
            };
        case 'publishing':
            return {
                headline: 'Publishing now.',
                hint: 'Sending to the platforms — this usually takes a moment.',
            };
        case 'published':
            return {
                headline: 'Live.',
                hint: 'Published to every channel.',
            };
        case 'partially_published':
            return {
                headline: 'Partly published.',
                hint: 'Some channels went out, others failed. Retry just the failed channels — the live ones are left alone.',
            };
        case 'failed':
            return {
                headline: 'Publishing failed.',
                hint: 'Check the activity log for the platform error, then retry or move it back to draft.',
            };
        case 'archived':
            return {
                headline: 'Archived.',
                hint: 'Out of the workflow. Restore it to draft to pick it back up.',
            };
        default:
            return { headline: 'Post', hint: '' };
    }
}
