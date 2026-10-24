import { ChevronDown, MessageSquarePlus } from 'lucide-react';
import { FeedbackBoard } from '@/components/feedback/feedback-board';
import { Badge } from '@/components/ui/badge';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import type { PostData } from '@/components/post-editor/types';
import {
    acceptSuggestion,
    dismissSuggestion,
    resolve as resolveFeedback,
} from '@/routes/comments';
import { store as storePostComment } from '@/routes/posts/comments';

/**
 * Step M-6.4: the visual-feedback board — pins, caption notes and suggested edits the client left,
 * which staff resolve (or accept) before approval.
 *
 * On the editor it's folded to a one-line header by default so it stays out of the way; it opens on
 * click, or on its own when there's unresolved feedback waiting to be cleared. Nothing renders when
 * there's no feedback and the user can't comment.
 */
export function FeedbackSection({
    post,
    canComment,
}: {
    post: PostData;
    canComment: boolean;
}) {
    const feedbackCount = post.feedback.length;
    const unresolved = post.feedback.filter(
        (t) => t.is_feedback && !t.resolved,
    ).length;

    if (feedbackCount === 0 && !canComment) {
        return null;
    }

    return (
        <Collapsible
            defaultOpen={unresolved > 0}
            className="border-border bg-card rounded-2xl border"
        >
            <CollapsibleTrigger className="group focus-visible:ring-ring/50 flex w-full items-center justify-between gap-3 rounded-2xl px-5 py-4 text-start outline-none focus-visible:ring-[3px]">
                <span className="flex items-center gap-2">
                    <MessageSquarePlus className="text-muted-foreground size-4" />
                    <h2 className="text-[15px] font-semibold">Feedback</h2>
                    <span className="text-muted-foreground font-mono text-xs">
                        {feedbackCount === 0
                            ? canComment
                                ? 'start a review'
                                : 'none yet'
                            : `${feedbackCount} ${feedbackCount === 1 ? 'note' : 'notes'}`}
                    </span>
                    {unresolved > 0 && (
                        <Badge variant="warning">{unresolved} to resolve</Badge>
                    )}
                </span>
                <ChevronDown className="text-muted-foreground size-4 shrink-0 transition-transform group-data-[state=open]:rotate-180" />
            </CollapsibleTrigger>
            <CollapsibleContent className="border-border/70 border-t p-4">
                <FeedbackBoard
                    assets={post.assets}
                    caption={post.master_caption ?? ''}
                    threads={post.feedback}
                    canComment={canComment}
                    mode="staff"
                    hideHeader
                    routes={{
                        create: storePostComment.url(post.id),
                        resolve: (id) => resolveFeedback.url(id),
                        acceptSuggestion: (id) => acceptSuggestion.url(id),
                        dismissSuggestion: (id) => dismissSuggestion.url(id),
                    }}
                />
            </CollapsibleContent>
        </Collapsible>
    );
}
