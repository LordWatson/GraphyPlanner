import {
    Composer,
    InboxWorkspace,
    type InboxWorkspaceProps,
} from '@/components/inbox/inbox-workspace';
import { useWorkspace } from '@/hooks/use-workspace';

// Re-exported so the composer component test (and any importer) keeps its stable path.
export { Composer };
export type {
    ConversationDetail,
    Message,
    SavedReply,
} from '@/components/inbox/inbox-workspace';

type PageProps = Omit<
    InboxWorkspaceProps,
    'routes' | 'multiClient' | 'description'
>;

/**
 * Phase M-9 / Step CG-1.1 — the social inbox, a surface shared by the agency studio and the
 * business/creator shell (one `/inbox` route, like the calendar). The whole experience is
 * {@link InboxWorkspace}; this page only flips the client filter and copy from the shared
 * `workspace` prop — a business workspace has one implicit brand, so it drops the client column
 * and filter. Assignment UI is gated server-side by seat count (`showAssignment`).
 */
export default function InboxIndex(props: PageProps) {
    const { isBusiness } = useWorkspace();

    return (
        <InboxWorkspace
            {...props}
            multiClient={!isBusiness}
            description={
                isBusiness
                    ? 'Every comment on your posts, triaged and ready for an on-brand reply you always send.'
                    : undefined
            }
        />
    );
}
