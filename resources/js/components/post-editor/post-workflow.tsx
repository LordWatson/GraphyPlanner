import { router } from '@inertiajs/react';
import { ArrowRight, Ellipsis, ShieldCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { confirmDialog } from '@/components/confirm-dialog';
import type { MentionableUser } from '@/components/mention-textarea';
import type {
    ActivityLogData,
    Checklist,
    TargetData,
    TransitionOption,
} from '@/components/post-editor/types';
import { StageTrack } from '@/components/post-editor/workflow/stage-track';
import {
    OverrideApprovalDialog,
    TransitionDialog,
} from '@/components/post-editor/workflow/workflow-dialogs';
import {
    RetryFailedTargetsRow,
    ReviewLinkRow,
} from '@/components/post-editor/workflow/workflow-rows';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcut';
import type { TransitionAction } from '@/lib/post-workflow';
import {
    describeTransition,
    primaryTransitionFor,
    statusGuidance,
} from '@/lib/post-workflow';
import { destroy as destroyPost } from '@/routes/posts';

/**
 * The status workflow, front and centre at the top of the editor: where the post is in its
 * lifecycle, what that means, the one recommended next step, and every other legal move one
 * click away. Each move confirms in a dialog that can attach a note to the activity log.
 */
export function PostWorkflow({
    post,
    clientName,
    allowedTransitions,
    isDirty,
    mentionableUsers,
    canResendReviewEmail,
    canRetryFailedTargets = false,
    canDelete = false,
    canOverrideApproval = false,
}: {
    post: {
        id: number;
        status: string;
        approval_mode: string;
        checklist: Checklist;
        activity_logs: ActivityLogData[];
        targets?: TargetData[];
    };
    clientName: string;
    allowedTransitions: TransitionOption[];
    isDirty: boolean;
    /** Teammates who can be `@`-tagged in a transition note. */
    mentionableUsers: MentionableUser[];
    canResendReviewEmail: boolean;
    canRetryFailedTargets?: boolean;
    canDelete?: boolean;
    /** Step M-2.9: Owner may approve a client-required post without the client. */
    canOverrideApproval?: boolean;
}) {
    const [pending, setPending] = useState<TransitionAction | null>(null);
    const [overriding, setOverriding] = useState(false);

    const allowed = allowedTransitions.map((option) => option.value);
    const actions = allowed.map((to) =>
        describeTransition(post.status, to, clientName),
    );
    const primaryTo = primaryTransitionFor(
        post.status,
        allowed,
        post.approval_mode,
    );
    const primary = actions.find((action) => action.to === primaryTo) ?? null;
    const secondary = actions.filter(
        (action) => action.intent === 'forward' && action.to !== primaryTo,
    );
    const overflow = actions.filter((action) => action.intent !== 'forward');

    // Step I-4.6 / M-1.6: ⌘/Ctrl + Enter opens "send for review" (internal first, else the
    // client). It still confirms in the dialog — the shortcut never moves a post on its own.
    const reviewAction =
        actions.find((action) => action.to === 'internal_review') ??
        actions.find((action) => action.to === 'waiting_client') ??
        null;
    useKeyboardShortcut(
        { key: 'Enter', mod: true, whileTyping: true },
        () => reviewAction && setPending(reviewAction),
        reviewAction !== null,
    );

    // Step I-1.5: only posts that never went out can be deleted (soft); the rest are archived.
    const deletePost = async () => {
        const confirmed = await confirmDialog({
            title: 'Delete this post?',
            description:
                'It disappears from the calendar and every list. Posts that went live can only be archived.',
            confirmLabel: 'Delete post',
            tone: 'destructive',
        });

        if (confirmed) {
            router.delete(destroyPost.url(post.id));
        }
    };
    const guidance = statusGuidance(post.status, clientName);

    const reviewLog =
        post.status === 'waiting_client'
            ? post.activity_logs.find(
                  (log) =>
                      log.to_status === 'waiting_client' &&
                      log.review_link_sent,
              )
            : undefined;

    const scheduleBlocked =
        primary?.to === 'scheduled' && !post.checklist.passed;

    const failedTargets = canRetryFailedTargets
        ? (post.targets ?? []).filter((target) => target.status === 'failed')
        : [];

    return (
        <section className="animate-rise border-border bg-card flex flex-col rounded-2xl border [animation-delay:80ms]">
            <div className="px-5 pt-5 pb-4">
                <StageTrack status={post.status} />
            </div>

            <div className="border-border/70 flex flex-col gap-4 border-t px-5 py-4 md:flex-row md:items-center md:justify-between">
                <div className="flex min-w-0 flex-col gap-1">
                    <p className="font-display text-xl leading-tight">
                        {guidance.headline}
                    </p>
                    <p className="text-muted-foreground text-[13px] text-pretty">
                        {guidance.hint}
                        {scheduleBlocked &&
                            ' Finish the readiness checklist to unlock scheduling.'}
                    </p>
                </div>

                {(actions.length > 0 || canOverrideApproval) && (
                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                        {(overflow.length > 0 || canDelete) && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="More status options"
                                    >
                                        <Ellipsis />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    className="w-56"
                                >
                                    <DropdownMenuLabel className="eyebrow text-muted-foreground">
                                        Move post
                                    </DropdownMenuLabel>
                                    {overflow.map((action, index) => {
                                        const Icon = action.icon;
                                        const startsArchive =
                                            action.intent === 'archive' &&
                                            index > 0;

                                        return (
                                            <div key={action.to}>
                                                {startsArchive && (
                                                    <DropdownMenuSeparator />
                                                )}
                                                <DropdownMenuItem
                                                    variant={
                                                        action.intent ===
                                                        'archive'
                                                            ? 'destructive'
                                                            : 'default'
                                                    }
                                                    onSelect={() =>
                                                        setPending(action)
                                                    }
                                                >
                                                    <Icon />
                                                    {action.label}
                                                </DropdownMenuItem>
                                            </div>
                                        );
                                    })}
                                    {canDelete && (
                                        <>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                variant="destructive"
                                                onSelect={() =>
                                                    void deletePost()
                                                }
                                            >
                                                <Trash2 />
                                                Delete post
                                            </DropdownMenuItem>
                                        </>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                        {canOverrideApproval && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOverriding(true)}
                            >
                                <ShieldCheck />
                                Approve without client
                            </Button>
                        )}
                        {secondary.map((action) => {
                            const Icon = action.icon;

                            return (
                                <Button
                                    key={action.to}
                                    type="button"
                                    variant="outline"
                                    onClick={() => setPending(action)}
                                >
                                    <Icon />
                                    {action.label}
                                </Button>
                            );
                        })}
                        {primary && (
                            <Button
                                type="button"
                                onClick={() => setPending(primary)}
                            >
                                <primary.icon />
                                {primary.label}
                                <ArrowRight />
                            </Button>
                        )}
                    </div>
                )}
            </div>

            {failedTargets.length > 0 && (
                <div className="px-5 pb-4">
                    <RetryFailedTargetsRow
                        postId={post.id}
                        failedTargets={failedTargets}
                    />
                </div>
            )}

            {reviewLog && (
                <div className="px-5 pb-4">
                    <ReviewLinkRow
                        postId={post.id}
                        log={reviewLog}
                        canResend={canResendReviewEmail}
                    />
                </div>
            )}

            {canOverrideApproval && (
                <OverrideApprovalDialog
                    postId={post.id}
                    clientName={clientName}
                    open={overriding}
                    mentionableUsers={mentionableUsers}
                    onOpenChange={setOverriding}
                />
            )}

            <TransitionDialog
                postId={post.id}
                status={post.status}
                action={pending}
                checklist={post.checklist}
                isDirty={isDirty}
                mentionableUsers={mentionableUsers}
                onOpenChange={(open) => !open && setPending(null)}
            />
        </section>
    );
}
