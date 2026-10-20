import type { LucideIcon } from 'lucide-react';
import {
    Archive,
    CheckCheck,
    MessageSquareWarning,
    RotateCcw,
    Send,
    UsersRound,
} from 'lucide-react';
import type { GridProgress } from '@/components/grids/types';

/**
 * Phase 18 (Step 18.5) — the grid planner's workflow copy: the stage track (Draft → Internal
 * review → Client review → Approved → In production → Live), what each status means, and how each
 * move is described. "In production" and "Live" are derived from the linked posts, never stored.
 */
export type GridStage = { key: string; label: string };

export const gridStages: GridStage[] = [
    { key: 'draft', label: 'Draft' },
    { key: 'internal_review', label: 'Internal review' },
    { key: 'client', label: 'Client review' },
    { key: 'approved', label: 'Approved' },
    { key: 'in_production', label: 'In production' },
    { key: 'live', label: 'Live' },
];

/** The current stage, or -1 for archived. */
export function gridStageIndex(status: string, progress: GridProgress): number {
    switch (status) {
        case 'draft':
            return 0;
        case 'internal_review':
            return 1;
        case 'waiting_client':
        case 'changes_requested':
            return 2;
        case 'approved':
            return progress.stage === 'live'
                ? 5
                : progress.stage === 'in_production'
                  ? 4
                  : 3;
        default:
            return -1;
    }
}

/** The status a stage shows as (derived stages for an approved grid). */
export function gridDisplayStatus(
    status: string,
    progress: GridProgress,
): string {
    if (status === 'approved' && progress.stage) {
        return progress.stage;
    }

    return status;
}

export function gridGuidance(
    status: string,
    clientName: string,
    progress: GridProgress,
): { headline: string; hint: string } {
    switch (status) {
        case 'draft':
            return {
                headline: 'Build the grid.',
                hint: 'Add tiles, set the order and framing, then send it for a look.',
            };
        case 'internal_review':
            return {
                headline: 'Ready for a second pair of eyes.',
                hint: `Check the order and the framing, then send it to ${clientName}.`,
            };
        case 'waiting_client':
            return {
                headline: `With ${clientName} now.`,
                hint: 'The grid is locked while they look. They can approve it or ask for changes.',
            };
        case 'changes_requested':
            return {
                headline: `${clientName} asked for changes.`,
                hint: 'Read the comments pinned to tiles, make the changes, then send it back.',
            };
        case 'approved':
            if (progress.stage === 'live') {
                return {
                    headline: 'Live on the profile.',
                    hint: 'Every tile has gone out in order. Nice work.',
                };
            }

            return progress.posts > 0
                ? {
                      headline: 'In production.',
                      hint: `${progress.posts} of ${progress.tiles} posts made · ${progress.scheduled} scheduled · ${progress.live} live. Each caption gets its own approval.`,
                  }
                : {
                      headline: 'Approved. Turn the tiles into posts.',
                      hint: 'The look is signed off. Each post still gets its caption approved in the usual way.',
                  };
        case 'archived':
            return {
                headline: 'Archived.',
                hint: 'Restore it to draft to work on it again. Its posts are untouched.',
            };
        default:
            return { headline: 'Grid plan.', hint: '' };
    }
}

export type GridTransitionAction = {
    to: string;
    label: string;
    description: string;
    icon: LucideIcon;
    intent: 'forward' | 'back' | 'archive';
    notePlaceholder: string;
};

export function describeGridTransition(
    from: string,
    to: string,
    clientName: string,
): GridTransitionAction {
    switch (to) {
        case 'internal_review':
            return {
                to,
                label: 'Send for internal review',
                description:
                    'The team gets a heads-up to check the order, framing and dates.',
                icon: UsersRound,
                intent: 'forward',
                notePlaceholder: 'Anything to look out for?',
            };
        case 'waiting_client':
            return {
                to,
                label: `Send to ${clientName}`,
                description: `We’ll email ${clientName} a link to the grid on their real profile. The grid is locked while they look.`,
                icon: Send,
                intent: 'forward',
                notePlaceholder: 'A short note for the activity log.',
            };
        case 'approved':
            return {
                to,
                label:
                    from === 'waiting_client'
                        ? 'Record their approval'
                        : 'Approve the grid',
                description:
                    from === 'waiting_client'
                        ? `Use this when ${clientName} approved somewhere else, like on a call.`
                        : 'Signs off the look. The captions are approved post by post.',
                icon: CheckCheck,
                intent: 'forward',
                notePlaceholder: 'e.g. Approved on our call this morning.',
            };
        case 'changes_requested':
            return {
                to,
                label: 'Record changes requested',
                description: `Use this when ${clientName} asked for changes somewhere else.`,
                icon: MessageSquareWarning,
                intent: 'back',
                notePlaceholder: 'What did they ask for?',
            };
        case 'archived':
            return {
                to,
                label: 'Archive grid',
                description: 'Put it away. Its posts stay exactly as they are.',
                icon: Archive,
                intent: 'archive',
                notePlaceholder: 'Why is it being archived?',
            };
        default:
            return {
                to,
                label:
                    from === 'archived' ? 'Restore to draft' : 'Back to draft',
                description:
                    from === 'approved'
                        ? 'Reopens the look for changes. It will need approving again.'
                        : 'Takes it back to draft for more work.',
                icon: RotateCcw,
                intent: 'back',
                notePlaceholder: 'What needs changing?',
            };
    }
}
