import { Form } from '@inertiajs/react';
import { ArrowRight, ShieldCheck, TriangleAlert, XCircle } from 'lucide-react';
import InputError from '@/components/input-error';
import {
    MentionTextarea,
    type MentionableUser,
} from '@/components/mention-textarea';
import type { Checklist } from '@/components/post-editor/types';
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
import { Label } from '@/components/ui/label';
import type { TransitionAction } from '@/lib/post-workflow';
import { overrideApproval, transition as transitionPost } from '@/routes/posts';

/** Confirms one status move, blocking scheduling until the checklist passes; can attach a note. */
export function TransitionDialog({
    postId,
    status,
    action,
    checklist,
    isDirty,
    mentionableUsers,
    onOpenChange,
}: {
    postId: number;
    status: string;
    action: TransitionAction | null;
    checklist: Checklist;
    isDirty: boolean;
    /** Teammates who can be `@`-tagged in the note (same list as the comment composer). */
    mentionableUsers: MentionableUser[];
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
                                            (optional, added to the activity log
                                            — type @ to tag a teammate)
                                        </span>
                                    </Label>
                                    <MentionTextarea
                                        id="transition-comment"
                                        name="comment"
                                        rows={3}
                                        placeholder={action.notePlaceholder}
                                        users={mentionableUsers}
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
 * Step I-3.8 / M-2.9 — an Owner approves a post that needs the client's sign-off without it. The
 * reason is required and goes on the approval and the activity log.
 */
export function OverrideApprovalDialog({
    postId,
    clientName,
    open,
    mentionableUsers,
    onOpenChange,
}: {
    postId: number;
    clientName: string;
    open: boolean;
    /** Teammates who can be `@`-tagged in the reason (same list as the comment composer). */
    mentionableUsers: MentionableUser[];
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <Form
                    {...overrideApproval.form(postId)}
                    options={{ preserveScroll: true, preserveState: 'errors' }}
                    onSuccess={() => onOpenChange(false)}
                    className="grid gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle className="flex items-center gap-2">
                                    <ShieldCheck className="text-muted-foreground size-5" />
                                    Approve without {clientName}
                                </DialogTitle>
                                <DialogDescription>
                                    This post is set to need the client’s
                                    approval. As an Owner you can approve it
                                    anyway — the reason is recorded on the
                                    activity log for everyone to see.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="grid gap-1.5">
                                <Label htmlFor="override-reason">
                                    Reason{' '}
                                    <span className="text-muted-foreground font-normal">
                                        (type @ to tag a teammate)
                                    </span>
                                </Label>
                                <MentionTextarea
                                    id="override-reason"
                                    name="reason"
                                    rows={3}
                                    required
                                    minLength={5}
                                    placeholder="e.g. Approved on our call this morning."
                                    users={mentionableUsers}
                                />
                                <InputError message={errors.reason} />
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Approving…' : 'Approve post'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
