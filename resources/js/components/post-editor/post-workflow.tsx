import { router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, ShieldCheck } from 'lucide-react';
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
import { MoveMenu } from '@/components/post-editor/workflow/transition-controls';
import {
    OverrideApprovalDialog,
    TransitionDialog,
} from '@/components/post-editor/workflow/workflow-dialogs';
import {
    RetryFailedTargetsRow,
    ReviewLinkRow,
} from '@/components/post-editor/workflow/workflow-rows';
import { Button } from '@/components/ui/button';
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
    // Forward moves advance the post; back moves return it a step. Both are shown as buttons so
    // moving back and forth is obvious; system/archive moves sit in the "more" menu.
    const forwardSecondary = actions.filter(
        (action) => action.intent === 'forward' && action.to !== primaryTo,
    );
    const backMoves = actions.filter((action) => action.intent === 'back');
    const overflow = actions.filter(
        (action) => action.intent === 'system' || action.intent === 'archive',
    );

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

    const hasMoves =
        backMoves.length > 0 ||
        forwardSecondary.length > 0 ||
        primary !== null ||
        canOverrideApproval;

    return (
        <section className="animate-rise border-border bg-card flex flex-col rounded-2xl border [animation-delay:80ms]">
            <div className="px-5 pt-5 pb-4">
                <StageTrack status={post.status} />
            </div>

            <div className="border-border/70 flex flex-col gap-4 border-t px-5 py-4">
                <div className="flex items-start justify-between gap-3">
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
                    <MoveMenu
                        overflow={overflow}
                        canDelete={canDelete}
                        onSelect={setPending}
                        onDelete={() => void deletePost()}
                    />
                </div>

                {hasMoves && (
                    <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                            {backMoves.map((action) => (
                                <Button
                                    key={action.to}
                                    type="button"
                                    variant="outline"
                                    onClick={() => setPending(action)}
                                >
                                    <ArrowLeft />
                                    {action.label}
                                </Button>
                            ))}
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
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
                            {forwardSecondary.map((action) => {
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
