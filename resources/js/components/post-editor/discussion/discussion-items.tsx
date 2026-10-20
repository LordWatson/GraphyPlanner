import postActivityLogs from '@/routes/posts/activity-logs';
import { Form } from '@inertiajs/react';
import { ArrowRight, Eye, Lock, Mail } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import {
    MentionTextarea,
    renderCommentBody,
    type MentionableUser,
} from '@/components/mention-textarea';
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
import { useInitials } from '@/hooks/use-initials';
import type { StatusKind } from '@/lib/status';
import { resolveStatus, toneBgClass } from '@/lib/status';
import { cn } from '@/lib/utils';
import type { RouteFormDefinition } from '@/wayfinder';

export function CommentComposer({
    form,
    mentionableUsers,
    extra,
}: {
    form: RouteFormDefinition<'post'>;
    mentionableUsers: MentionableUser[];
    /** Extra fields inside the form (the grid's "About tile" picker). */
    extra?: ReactNode;
}) {
    const [internal, setInternal] = useState(false);

    return (
        <Form
            {...form}
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
                    {extra}
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

export function CommentItem({
    comment,
    chip,
    onHover,
}: {
    comment: CommentData;
    chip?: ReactNode;
    onHover?: (hovering: boolean) => void;
}) {
    const getInitials = useInitials();
    const name = comment.user_name ?? 'Unknown';

    return (
        <li
            className="flex gap-3"
            onMouseEnter={onHover ? () => onHover(true) : undefined}
            onMouseLeave={onHover ? () => onHover(false) : undefined}
        >
            <Avatar className="size-8 shrink-0">
                <AvatarFallback className="text-xs">
                    {getInitials(name)}
                </AvatarFallback>
            </Avatar>
            <div className="grid min-w-0 flex-1 gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-medium">{name}</span>
                    {chip}
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

export function ActivityItem({
    log,
    postId,
    canResend,
    isLast,
    statusKind,
}: {
    log: ActivityLogData;
    postId: number;
    canResend: boolean;
    isLast: boolean;
    statusKind: StatusKind;
}) {
    const tone = resolveStatus(statusKind, log.to_status).tone;

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
                            <StatusPill
                                kind={statusKind}
                                value={log.from_status}
                            />
                            <ArrowRight className="text-muted-foreground size-3.5" />
                        </>
                    )}
                    <StatusPill kind={statusKind} value={log.to_status} />
                    <span className="text-muted-foreground font-mono text-[11px]">
                        {formatTimestamp(log.created_at)}
                    </span>
                </div>
                {log.note && (
                    <blockquote className="border-border text-foreground/80 border-l-2 pl-3 text-sm leading-relaxed break-words whitespace-pre-wrap">
                        {log.note}
                    </blockquote>
                )}
                {log.review_link_sent && (
                    <div className="flex flex-wrap items-center gap-2 text-xs">
                        <span className="text-muted-foreground">
                            Review link emailed to the client
                        </span>
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
