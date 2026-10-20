import type { ReactNode } from 'react';
import { type MentionableUser } from '@/components/mention-textarea';
import { EmptyState } from '@/components/page';
import type {
    ActivityLogData,
    CommentData,
} from '@/components/post-editor/types';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { StatusKind } from '@/lib/status';
import { store as storeComment } from '@/routes/posts/comments';
import type { RouteFormDefinition } from '@/wayfinder';
import {
    CommentComposer,
    CommentItem,
    ActivityItem,
} from '@/components/post-editor/discussion/discussion-items';

/**
 * Comments and the status history, side by side as tabs under the editor. Phase 18 reuses it for
 * grid plans: pass the grid's `commentForm` and `statusKind`, a `renderChip` ("Tile 4") and
 * `onCommentHover` to light up the tile a comment is about.
 */
export function PostDiscussion<C extends CommentData = CommentData>({
    postId,
    comments,
    activityLogs,
    canComment,
    canResendReviewEmail,
    mentionableUsers,
    commentForm,
    composerExtra,
    statusKind = 'post',
    renderChip,
    onCommentHover,
}: {
    postId: number;
    comments: C[];
    activityLogs: ActivityLogData[];
    canComment: boolean;
    canResendReviewEmail: boolean;
    mentionableUsers: MentionableUser[];
    commentForm?: RouteFormDefinition<'post'>;
    composerExtra?: ReactNode;
    statusKind?: StatusKind;
    renderChip?: (comment: C) => ReactNode;
    onCommentHover?: (comment: C | null) => void;
}) {
    return (
        <section className="border-border bg-card rounded-2xl border">
            <Tabs defaultValue="comments" className="gap-0">
                <div className="border-border/70 flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3">
                    <h2 className="text-[15px] font-semibold">Discussion</h2>
                    <TabsList>
                        <TabsTrigger value="comments">
                            Comments
                            <span className="text-muted-foreground font-mono text-[11px]">
                                {comments.length}
                            </span>
                        </TabsTrigger>
                        <TabsTrigger value="activity">
                            Activity
                            <span className="text-muted-foreground font-mono text-[11px]">
                                {activityLogs.length}
                            </span>
                        </TabsTrigger>
                    </TabsList>
                </div>

                <TabsContent value="comments" className="grid gap-5 p-5">
                    {canComment && (
                        <CommentComposer
                            form={commentForm ?? storeComment.form(postId)}
                            mentionableUsers={mentionableUsers}
                            extra={composerExtra}
                        />
                    )}
                    {comments.length === 0 ? (
                        <EmptyState
                            compact
                            title="No comments yet."
                            description="Leave a note for the team or the client — type @ to mention someone."
                        />
                    ) : (
                        <ul className="grid gap-5">
                            {comments.map((comment) => (
                                <CommentItem
                                    key={comment.id}
                                    comment={comment}
                                    chip={renderChip?.(comment)}
                                    onHover={
                                        onCommentHover
                                            ? (hovering) =>
                                                  onCommentHover(
                                                      hovering ? comment : null,
                                                  )
                                            : undefined
                                    }
                                />
                            ))}
                        </ul>
                    )}
                </TabsContent>

                <TabsContent value="activity" className="p-5">
                    {activityLogs.length === 0 ? (
                        <EmptyState
                            compact
                            title="Nothing yet."
                            description="Every status change, with its note, shows up here."
                        />
                    ) : (
                        <ol>
                            {activityLogs.map((log, index) => (
                                <ActivityItem
                                    key={log.id}
                                    log={log}
                                    postId={postId}
                                    canResend={canResendReviewEmail}
                                    isLast={index === activityLogs.length - 1}
                                    statusKind={statusKind}
                                />
                            ))}
                        </ol>
                    )}
                </TabsContent>
            </Tabs>
        </section>
    );
}
