import { Form } from '@inertiajs/react';
import { ArrowRight, Eye, Lock, Mail } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import {
    MentionTextarea,
    renderCommentBody,
    type MentionableUser,
} from '@/components/mention-textarea';
import { EmptyState } from '@/components/page';
import type {
    ActivityLogData,
    CommentData,
} from '@/components/post-editor/types';
import { formatTimestamp } from '@/components/post-editor/types';
import { StatusPill } from '@/components/status-pill';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useInitials } from '@/hooks/use-initials';
import { resolveStatus, toneBgClass } from '@/lib/status';
import { cn } from '@/lib/utils';
import postActivityLogs from '@/routes/posts/activity-logs';
import { store as storeComment } from '@/routes/posts/comments';

function CommentComposer({
    postId,
    mentionableUsers,
}: {
    postId: number;
    mentionableUsers: MentionableUser[];
}) {
    const [internal, setInternal] = useState(false);

    return (
        <Form
            {...storeComment.form(postId)}
            // Keep the editor's unsaved state intact — only the comment list refreshes.
            options={{ preserveScroll: true, preserveState: true }}
            resetOnSuccess
            onSuccess={() => setInternal(false)}
            className={cn(
                'grid gap-2 rounded-xl border p-3 transition-colors',
                internal
                    ? 'border-warning/40 bg-warning/5'
                    : 'border-border bg-background',
            )}
        >
            {({ processing, errors }) => (
                <>
                    <MentionTextarea
                        name="body"
                        rows={2}
                        placeholder="Write a comment… type @ to mention someone"
                        users={mentionableUsers}
                    />
                    <InputError message={errors.body} />
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <label className="flex cursor-pointer items-center gap-2 text-xs">
                            <Checkbox
                                name="internal_only"
                                value="1"
                                checked={internal}
                                onCheckedChange={(checked) =>
                                    setInternal(checked === true)
                                }
                            />
                            <span className="flex items-center gap-1.5">
                                {internal ? (
                                    <>
                                        <Lock className="size-3.5" />
                                        Internal — only the studio sees this
                                    </>
                                ) : (
                                    <>
                                        <Eye className="size-3.5" />
                                        Visible to the client
                                    </>
                                )}
                            </span>
                        </label>
                        <Button
                            type="submit"
                            size="sm"
                            variant="secondary"
                            disabled={processing}
                        >
                            {processing ? 'Posting…' : 'Comment'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function CommentItem({ comment }: { comment: CommentData }) {
    const getInitials = useInitials();
    const name = comment.user_name ?? 'Unknown';

    return (
        <li className="flex gap-3">
            <Avatar className="size-8 shrink-0">
                <AvatarFallback className="text-xs">
                    {getInitials(name)}
                </AvatarFallback>
            </Avatar>
            <div className="grid min-w-0 flex-1 gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-medium">{name}</span>
                    {comment.internal_only && (
                        <Badge variant="warning">
                            <Lock className="size-3" />
                            Internal
                        </Badge>
                    )}
                    <span className="text-muted-foreground font-mono text-[11px]">
                        {formatTimestamp(comment.created_at)}
                    </span>
                </div>
                <p className="text-foreground/85 text-sm leading-relaxed break-words whitespace-pre-wrap">
                    {renderCommentBody(comment.body)}
                </p>
            </div>
        </li>
    );
}

function ActivityItem({
    log,
    postId,
    canResend,
    isLast,
}: {
    log: ActivityLogData;
    postId: number;
    canResend: boolean;
    isLast: boolean;
}) {
    const tone = resolveStatus('post', log.to_status).tone;

    return (
        <li className="relative flex gap-3 pb-5 last:pb-0">
            {!isLast && (
                <span
                    aria-hidden
                    className="bg-border absolute top-4 bottom-0 left-[7px] w-px"
                />
            )}
            <span
                aria-hidden
                className={cn(
                    'ring-card relative mt-1 size-[15px] shrink-0 rounded-full ring-4',
                    toneBgClass[tone],
                )}
            />
            <div className="grid min-w-0 flex-1 gap-1.5">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                    <span className="font-medium">
                        {log.user_name ?? 'System'}
                    </span>
                    <span className="text-muted-foreground">moved it</span>
                    {log.from_status && (
                        <>
                            <StatusPill kind="post" value={log.from_status} />
                            <ArrowRight className="text-muted-foreground size-3.5" />
                        </>
                    )}
                    <StatusPill kind="post" value={log.to_status} />
                    <span className="text-muted-foreground font-mono text-[11px]">
                        {formatTimestamp(log.created_at)}
                    </span>
                </div>
                {log.note && (
                    <blockquote className="border-border text-foreground/80 border-l-2 pl-3 text-sm leading-relaxed break-words whitespace-pre-wrap">
                        {log.note}
                    </blockquote>
                )}
                {log.review_url && (
                    <div className="flex flex-wrap items-center gap-2 text-xs">
                        <a
                            href={log.review_url}
                            target="_blank"
                            rel="noreferrer"
                            className="text-muted-foreground hover:text-foreground min-w-0 break-all underline underline-offset-2"
                        >
                            Client review link
                        </a>
                        {canResend && (
                            <Form
                                {...postActivityLogs.resendReviewEmail.form([
                                    postId,
                                    log.id,
                                ])}
                                options={{
                                    preserveScroll: true,
                                    preserveState: true,
                                }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="link"
                                        className="h-auto text-xs"
                                        disabled={processing}
                                    >
                                        <Mail className="size-3" />
                                        {processing
                                            ? 'Sending…'
                                            : 'Resend email'}
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                )}
            </div>
        </li>
    );
}

/** Comments and the status history, side by side as tabs under the editor. */
export function PostDiscussion({
    postId,
    comments,
    activityLogs,
    canComment,
    canResendReviewEmail,
    mentionableUsers,
}: {
    postId: number;
    comments: CommentData[];
    activityLogs: ActivityLogData[];
    canComment: boolean;
    canResendReviewEmail: boolean;
    mentionableUsers: MentionableUser[];
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
                            postId={postId}
                            mentionableUsers={mentionableUsers}
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
                                />
                            ))}
                        </ol>
                    )}
                </TabsContent>
            </Tabs>
        </section>
    );
}
