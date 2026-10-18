import { Form, router } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    Ellipsis,
    Mail,
    RefreshCw,
    Trash2,
    TriangleAlert,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import type {
    ActivityLogData,
    Checklist,
    TargetData,
    TransitionOption,
} from '@/components/post-editor/types';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import type { TransitionAction } from '@/lib/post-workflow';
import {
    describeTransition,
    primaryTransitionFor,
    stageIndexFor,
    statusGuidance,
    workflowStages,
} from '@/lib/post-workflow';
import { resolveStatus, toneBgClass, toneTextClass } from '@/lib/status';
import { cn } from '@/lib/utils';
import { confirmDialog } from '@/components/confirm-dialog';
import {
    destroy as destroyPost,
    retryFailedTargets,
    transition as transitionPost,
} from '@/routes/posts';
import postActivityLogs from '@/routes/posts/activity-logs';

const textareaClass =
    'border-input bg-card focus-visible:border-ring focus-visible:ring-ring/40 dark:bg-input/30 placeholder:text-muted-foreground flex w-full rounded-lg border px-3 py-2 text-sm outline-none focus-visible:ring-[3px]';

/**
 * The lifecycle at a glance: done stages get a check, the current one carries its real status
 * tone (so "Changes requested" and "Failed" read differently from the happy path), upcoming ones
 * stay quiet. Archived posts render the whole track dimmed.
 */
function StageTrack({ status }: { status: string }) {
    const currentIndex = stageIndexFor(status);
    const tone = resolveStatus('post', status).tone;
    const archived = currentIndex === -1;

    return (
        <ol
            className={cn(
                '-mx-1 flex min-w-0 items-start overflow-x-auto px-1 pb-1',
                archived && 'opacity-45',
            )}
            aria-label="Post workflow"
        >
            {workflowStages.map((stage, index) => {
                const done = !archived && index < currentIndex;
                const current = index === currentIndex;
                const isLast = index === workflowStages.length - 1;

                return (
                    <li
                        key={stage.key}
                        className="flex min-w-[6.5rem] flex-1 flex-col gap-2"
                        aria-current={current ? 'step' : undefined}
                    >
                        <div className="flex items-center">
                            <span
                                className={cn(
                                    'flex size-7 shrink-0 items-center justify-center rounded-full border text-[11px] font-medium transition-colors',
                                    done &&
                                        'bg-foreground text-background border-transparent',
                                    current &&
                                        'border-foreground/15 bg-card ring-4 ring-current/15',
                                    current && toneTextClass[tone],
                                    !done &&
                                        !current &&
                                        'border-border bg-background text-muted-foreground font-mono',
                                )}
                            >
                                {done ? (
                                    <Check className="size-3.5" />
                                ) : current ? (
                                    <span
                                        className={cn(
                                            'size-2.5 rounded-full',
                                            toneBgClass[tone],
                                            status === 'publishing' &&
                                                'animate-pulse',
                                        )}
                                    />
                                ) : (
                                    String(index + 1).padStart(2, '0')
                                )}
                            </span>
                            {!isLast && (
                                <span
                                    aria-hidden
                                    className={cn(
                                        'mx-2 h-px flex-1',
                                        done ? 'bg-foreground/60' : 'bg-border',
                                    )}
                                />
                            )}
                        </div>
                        <div className="flex flex-col pr-3">
                            <span
                                className={cn(
                                    'text-[13px] leading-tight',
                                    current
                                        ? 'text-foreground font-semibold'
                                        : done
                                          ? 'text-foreground/80'
                                          : 'text-muted-foreground',
                                )}
                            >
                                {stage.label}
                            </span>
                            {current &&
                                stage.statuses.length > 1 &&
                                status !== stage.statuses[0] && (
                                    <span
                                        className={cn(
                                            'text-xs',
                                            toneTextClass[tone],
                                        )}
                                    >
                                        {resolveStatus('post', status).label}
                                    </span>
                                )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

function ReviewLinkRow({
    postId,
    log,
    canResend,
}: {
    postId: number;
    log: ActivityLogData;
    canResend: boolean;
}) {
    return (
        <div className="border-border bg-muted/40 flex flex-wrap items-center gap-2 rounded-xl border px-3 py-2">
            <Mail className="text-muted-foreground size-4 shrink-0" />
            <span className="text-muted-foreground min-w-0 flex-1 text-xs">
                Review link emailed to the client. Re-sending issues a fresh
                link and turns off the old one.
            </span>
            {canResend && (
                <Form
                    {...postActivityLogs.resendReviewEmail.form([
                        postId,
                        log.id,
                    ])}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={() => toast.success('Fresh review link sent.')}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                        >
                            <Mail />
                            {processing ? 'Sending…' : 'Resend email'}
                        </Button>
                    )}
                </Form>
            )}
        </div>
    );
}

function TransitionDialog({
    postId,
    status,
    action,
    checklist,
    isDirty,
    onOpenChange,
}: {
    postId: number;
    status: string;
    action: TransitionAction | null;
    checklist: Checklist;
    isDirty: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const blockedBy =
        action?.to === 'scheduled' && !checklist.passed
            ? checklist.items.filter((item) => !item.passed && !item.soft)
            : [];
    const Icon = action?.icon;

    return (
        <Dialog open={action !== null} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                {action && (
                    <Form
                        {...transitionPost.form(postId)}
                        options={{
                            preserveScroll: true,
                            preserveState: 'errors',
                        }}
                        className="grid gap-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle className="flex items-center gap-2">
                                        {Icon && (
                                            <Icon className="text-muted-foreground size-5" />
                                        )}
                                        {action.label}
                                    </DialogTitle>
                                    <DialogDescription>
                                        {action.description}
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="flex items-center gap-2">
                                    <StatusPill kind="post" value={status} />
                                    <ArrowRight className="text-muted-foreground size-3.5" />
                                    <StatusPill kind="post" value={action.to} />
                                </div>

                                {blockedBy.length > 0 && (
                                    <div className="border-destructive/25 bg-destructive/5 grid gap-2 rounded-xl border p-3 text-sm">
                                        <p className="text-destructive font-medium">
                                            Not ready to schedule yet
                                        </p>
                                        <ul className="grid gap-1">
                                            {blockedBy.map((item) => (
                                                <li
                                                    key={item.key}
                                                    className="text-muted-foreground flex items-start gap-2"
                                                >
                                                    <XCircle className="text-destructive mt-0.5 size-3.5 shrink-0" />
                                                    {item.label}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}

                                {isDirty && (
                                    <div className="border-warning/30 bg-warning/10 flex items-start gap-2 rounded-xl border p-3 text-sm">
                                        <TriangleAlert className="text-warning mt-0.5 size-4 shrink-0" />
                                        <span>
                                            You have unsaved edits. They'll be
                                            discarded if you continue — save
                                            them first to keep them.
                                        </span>
                                    </div>
                                )}

                                <div className="grid gap-1.5">
                                    <Label htmlFor="transition-comment">
                                        Note{' '}
                                        <span className="text-muted-foreground font-normal">
                                            (optional, added to the activity
                                            log)
                                        </span>
                                    </Label>
                                    <textarea
                                        id="transition-comment"
                                        name="comment"
                                        rows={3}
                                        placeholder={action.notePlaceholder}
                                        className={textareaClass}
                                    />
                                    <InputError message={errors.comment} />
                                </div>

                                <input
                                    type="hidden"
                                    name="to"
                                    value={action.to}
                                />
                                <InputError message={errors.to} />

                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => onOpenChange(false)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant={
                                            action.intent === 'archive'
                                                ? 'destructive'
                                                : 'default'
                                        }
                                        disabled={
                                            processing || blockedBy.length > 0
                                        }
                                    >
                                        {processing
                                            ? 'Updating…'
                                            : action.label}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}

/**
 * Step I-1.3 — shown on a `failed`/`partially_published` post: which channels failed, and one
 * button that resends only those (channels that already went out are never touched).
 */
function RetryFailedTargetsRow({
    postId,
    failedTargets,
}: {
    postId: number;
    failedTargets: TargetData[];
}) {
    return (
        <div className="border-destructive/30 bg-destructive/5 flex flex-wrap items-center gap-2 rounded-xl border px-3 py-2">
            <TriangleAlert className="text-destructive size-4 shrink-0" />
            <span className="min-w-0 flex-1 text-xs">
                {failedTargets.length === 1
                    ? '1 channel failed: '
                    : `${failedTargets.length} channels failed: `}
                <span className="text-muted-foreground">
                    {failedTargets
                        .map((target) =>
                            [target.platform, target.handle]
                                .filter(Boolean)
                                .join(' · '),
                        )
                        .join(', ')}
                </span>
            </span>
            <Form
                {...retryFailedTargets.form(postId)}
                options={{ preserveScroll: true }}
            >
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        disabled={processing}
                    >
                        <RefreshCw />
                        {processing ? 'Retrying…' : 'Retry failed channels'}
                    </Button>
                )}
            </Form>
        </div>
    );
}

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
    canResendReviewEmail,
    canRetryFailedTargets = false,
    canDelete = false,
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
    canResendReviewEmail: boolean;
    canRetryFailedTargets?: boolean;
    canDelete?: boolean;
}) {
    const [pending, setPending] = useState<TransitionAction | null>(null);

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

                {actions.length > 0 && (
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

            <TransitionDialog
                postId={post.id}
                status={post.status}
                action={pending}
                checklist={post.checklist}
                isDirty={isDirty}
                onOpenChange={(open) => !open && setPending(null)}
            />
        </section>
    );
}
