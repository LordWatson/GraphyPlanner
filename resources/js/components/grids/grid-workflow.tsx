import { Form, router } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    Copy,
    Ellipsis,
    ShieldCheck,
    Trash2,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { confirmDialog } from '@/components/confirm-dialog';
import type { GridPlanData } from '@/components/grids/types';
import InputError from '@/components/input-error';
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
import type { GridTransitionAction } from '@/lib/grid-workflow';
import {
    describeGridTransition,
    gridDisplayStatus,
    gridGuidance,
    gridStageIndex,
    gridStages,
} from '@/lib/grid-workflow';
import { resolveStatus, toneBgClass, toneTextClass } from '@/lib/status';
import { cn } from '@/lib/utils';
import {
    destroy as destroyGrid,
    duplicate as duplicateGrid,
    overrideApproval,
    transition as transitionGrid,
} from '@/routes/grids';

const textareaClass =
    'border-input bg-card focus-visible:border-ring focus-visible:ring-ring/40 dark:bg-input/30 placeholder:text-muted-foreground flex w-full rounded-lg border-2 px-3 py-2 text-sm outline-none focus-visible:ring-[3px]';

function StageTrack({ grid }: { grid: GridPlanData }) {
    const current = gridStageIndex(grid.status, grid.progress);
    const display = gridDisplayStatus(grid.status, grid.progress);
    const tone = resolveStatus('gridPlan', display).tone;
    const archived = current === -1;

    return (
        <ol
            className={cn(
                '-mx-1 flex min-w-0 items-start overflow-x-auto px-1 pb-1',
                archived && 'opacity-45',
            )}
            aria-label="Grid workflow"
        >
            {gridStages.map((stage, index) => {
                const done = !archived && index < current;
                const isCurrent = index === current;
                const isLast = index === gridStages.length - 1;

                return (
                    <li
                        key={stage.key}
                        className="flex min-w-[6.5rem] flex-1 flex-col gap-2"
                        aria-current={isCurrent ? 'step' : undefined}
                    >
                        <div className="flex items-center">
                            <span
                                className={cn(
                                    'flex size-7 shrink-0 items-center justify-center rounded-full border text-[11px] font-medium transition-colors',
                                    done &&
                                        'bg-foreground text-background border-transparent',
                                    isCurrent &&
                                        'border-foreground/15 bg-card ring-4 ring-current/15',
                                    isCurrent && toneTextClass[tone],
                                    !done &&
                                        !isCurrent &&
                                        'border-border bg-background text-muted-foreground font-mono',
                                )}
                            >
                                {done ? (
                                    <Check className="size-3.5" />
                                ) : isCurrent ? (
                                    <span
                                        className={cn(
                                            'size-2.5 rounded-full',
                                            toneBgClass[tone],
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
                                    isCurrent
                                        ? 'text-foreground font-semibold'
                                        : done
                                          ? 'text-foreground/80'
                                          : 'text-muted-foreground',
                                )}
                            >
                                {stage.label}
                            </span>
                            {isCurrent &&
                                grid.status === 'changes_requested' && (
                                    <span
                                        className={cn(
                                            'text-xs',
                                            toneTextClass[tone],
                                        )}
                                    >
                                        Changes requested
                                    </span>
                                )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

function TransitionDialog({
    grid,
    action,
    isDirty,
    onOpenChange,
}: {
    grid: GridPlanData;
    action: GridTransitionAction | null;
    isDirty: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const needsChecklist =
        action?.to === 'waiting_client' || action?.to === 'approved';
    const blockedBy = needsChecklist
        ? grid.checklist.items.filter((item) => !item.passed && !item.soft)
        : [];
    const Icon = action?.icon;

    return (
        <Dialog open={action !== null} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                {action && (
                    <Form
                        {...transitionGrid.form(grid.id)}
                        options={{
                            preserveScroll: true,
                            preserveState: 'errors',
                        }}
                        onSuccess={() => onOpenChange(false)}
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
                                    <StatusPill
                                        kind="gridPlan"
                                        value={grid.status}
                                    />
                                    <ArrowRight className="text-muted-foreground size-3.5" />
                                    <StatusPill
                                        kind="gridPlan"
                                        value={action.to}
                                    />
                                </div>
                                {blockedBy.length > 0 && (
                                    <div className="border-destructive/25 bg-destructive/5 grid gap-2 rounded-xl border p-3 text-sm">
                                        <p className="text-destructive font-medium">
                                            Finish the checklist first
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
                                    <p className="border-warning/30 bg-warning/10 rounded-xl border p-3 text-sm">
                                        You have unsaved tile edits. Save them
                                        first, or they’ll be left out.
                                    </p>
                                )}
                                <div className="grid gap-1.5">
                                    <Label htmlFor="grid-transition-note">
                                        Note{' '}
                                        <span className="text-muted-foreground font-normal">
                                            (optional, added to the activity
                                            log)
                                        </span>
                                    </Label>
                                    <textarea
                                        id="grid-transition-note"
                                        name="note"
                                        rows={3}
                                        placeholder={action.notePlaceholder}
                                        className={textareaClass}
                                    />
                                    <InputError message={errors.note} />
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

function OverrideDialog({
    gridId,
    clientName,
    open,
    onOpenChange,
}: {
    gridId: number;
    clientName: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <Form
                    {...overrideApproval.form(gridId)}
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
                                    This client signs off their grids. As an
                                    Owner you can approve it anyway. The reason
                                    goes on the activity log for everyone to
                                    see.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="grid gap-1.5">
                                <Label htmlFor="grid-override-reason">
                                    Reason
                                </Label>
                                <textarea
                                    id="grid-override-reason"
                                    name="reason"
                                    rows={3}
                                    required
                                    minLength={3}
                                    placeholder="e.g. Approved on our call this morning."
                                    className={textareaClass}
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
                                    {processing ? 'Approving…' : 'Approve grid'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Step 18.5 / 18.7 — the grid's workflow, like the post editor's: where it is, what that means,
 * the one recommended next step (an Ink button), and every other move under "More". Every move
 * confirms with an optional note.
 */
export function GridWorkflow({
    grid,
    clientName,
    allowedTransitions,
    isDirty,
    canDelete,
    canOverrideApproval,
    canDuplicate,
}: {
    grid: GridPlanData;
    clientName: string;
    allowedTransitions: { value: string; label: string; forward: boolean }[];
    isDirty: boolean;
    canDelete: boolean;
    canOverrideApproval: boolean;
    canDuplicate: boolean;
}) {
    const [pending, setPending] = useState<GridTransitionAction | null>(null);
    const [overriding, setOverriding] = useState(false);

    const actions = allowedTransitions.map((option) => ({
        ...describeGridTransition(grid.status, option.value, clientName),
        forward: option.forward,
    }));
    const forward = actions.filter((action) => action.forward);
    // From internal review, sending to the client is the recommended step when it's allowed.
    const primary =
        forward.find((action) => action.to === 'waiting_client') ??
        forward[0] ??
        null;
    const secondary = forward.filter((action) => action !== primary);
    const overflow = actions.filter((action) => !action.forward);
    const guidance = gridGuidance(grid.status, clientName, grid.progress);

    const deleteGrid = async () => {
        const confirmed = await confirmDialog({
            title: 'Delete this grid?',
            description:
                'The plan goes, and its review links stop working. Posts made from it stay exactly as they are.',
            confirmLabel: 'Delete grid',
            tone: 'destructive',
        });

        if (confirmed) {
            router.delete(destroyGrid.url(grid.id));
        }
    };

    return (
        <section className="animate-rise border-border bg-card flex flex-col rounded-2xl border shadow-sm [animation-delay:80ms]">
            <div className="px-5 pt-5 pb-4">
                <StageTrack grid={grid} />
            </div>
            <div className="border-border/70 flex flex-col gap-4 border-t px-5 py-4 md:flex-row md:items-center md:justify-between">
                <div className="flex min-w-0 flex-col gap-1">
                    <p className="font-display text-xl leading-tight">
                        {guidance.headline}
                    </p>
                    <p className="text-muted-foreground text-[13px] text-pretty">
                        {guidance.hint}
                    </p>
                </div>
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {(overflow.length > 0 || canDelete || canDuplicate) && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="More grid options"
                                >
                                    <Ellipsis />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                {overflow.length > 0 && (
                                    <DropdownMenuLabel className="eyebrow text-muted-foreground">
                                        Move grid
                                    </DropdownMenuLabel>
                                )}
                                {overflow.map((action) => {
                                    const Icon = action.icon;

                                    return (
                                        <DropdownMenuItem
                                            key={action.to}
                                            variant={
                                                action.intent === 'archive'
                                                    ? 'destructive'
                                                    : 'default'
                                            }
                                            onSelect={() => setPending(action)}
                                        >
                                            <Icon />
                                            {action.label}
                                        </DropdownMenuItem>
                                    );
                                })}
                                {canDuplicate && (
                                    <>
                                        {overflow.length > 0 && (
                                            <DropdownMenuSeparator />
                                        )}
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                router.post(
                                                    duplicateGrid.url(grid.id),
                                                )
                                            }
                                        >
                                            <Copy />
                                            Duplicate as a new draft
                                        </DropdownMenuItem>
                                    </>
                                )}
                                {canDelete && (
                                    <>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            variant="destructive"
                                            onSelect={() => void deleteGrid()}
                                        >
                                            <Trash2 />
                                            Delete grid
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
            </div>
            {canOverrideApproval && (
                <OverrideDialog
                    gridId={grid.id}
                    clientName={clientName}
                    open={overriding}
                    onOpenChange={setOverriding}
                />
            )}
            <TransitionDialog
                grid={grid}
                action={pending}
                isDirty={isDirty}
                onOpenChange={(open) => !open && setPending(null)}
            />
        </section>
    );
}
