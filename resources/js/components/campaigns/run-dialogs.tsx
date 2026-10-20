import { router } from '@inertiajs/react';
import { CalendarClock, CircleSlash } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateRange } from '@/lib/dates';
import { http } from '@/lib/http';
import { cn } from '@/lib/utils';
import {
    resume as resumeCampaign,
    shift as shiftCampaign,
} from '@/routes/campaigns';
import { store as storeTemplate } from '@/routes/campaigns/template';
import { Textarea } from '@/components/ui/textarea';
import { preview as previewShift } from '@/routes/campaigns/shift';

const MODES = [
    {
        value: 'keep',
        label: 'Keep their original times',
        hint: 'Anything whose slot has passed comes back for a new time.',
    },
    {
        value: 'shift',
        label: 'Push them all back',
        hint: 'Every paused post moves later by the days the campaign was paused.',
    },
    {
        value: 'none',
        label: 'Leave them unscheduled',
        hint: 'They stay approved. Schedule them yourself.',
    },
] as const;

/**
 * Step C-6.1 — resume a paused campaign, choosing what happens to the posts the pause took off
 * the schedule.
 */
export function ResumeDialog({
    campaignId,
    open,
    onOpenChange,
}: {
    campaignId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [mode, setMode] = useState<(typeof MODES)[number]['value']>('keep');
    const [busy, setBusy] = useState(false);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Back on.</DialogTitle>
                    <DialogDescription>
                        What should happen to the posts the pause took off the
                        schedule?
                    </DialogDescription>
                </DialogHeader>
                <div role="radiogroup" className="flex flex-col gap-2">
                    {MODES.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            role="radio"
                            aria-checked={mode === option.value}
                            onClick={() => setMode(option.value)}
                            className={cn(
                                'focus-visible:ring-ring/50 flex flex-col gap-0.5 rounded-xl border px-4 py-3 text-left transition-colors outline-none focus-visible:ring-[3px]',
                                mode === option.value
                                    ? 'border-primary bg-accent/60'
                                    : 'border-border hover:bg-muted/50',
                            )}
                        >
                            <span className="text-sm font-medium">
                                {option.label}
                            </span>
                            <span className="text-muted-foreground text-xs">
                                {option.hint}
                            </span>
                        </button>
                    ))}
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        disabled={busy}
                        onClick={() =>
                            router.post(
                                resumeCampaign.url(campaignId),
                                { mode },
                                {
                                    preserveScroll: true,
                                    onStart: () => setBusy(true),
                                    onFinish: () => setBusy(false),
                                    onSuccess: () => onOpenChange(false),
                                },
                            )
                        }
                    >
                        Resume campaign
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

type ShiftPreview = {
    days: number;
    campaign: { from: string | null; to: string | null };
    phases: number;
    deliverables: number;
    posts: { id: number; caption: string | null }[];
    blocked: { id: number; caption: string | null; reason: string }[];
};

/**
 * Step C-6.2 — move the whole campaign by N days. Shows what will move and what can't (already
 * out, or would land in the past) before anything changes.
 */
export function ShiftDialog({
    campaignId,
    open,
    onOpenChange,
}: {
    campaignId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [days, setDays] = useState('7');
    const [preview, setPreview] = useState<ShiftPreview | null>(null);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const value = Number(days);
    const valid =
        Number.isInteger(value) && value !== 0 && Math.abs(value) <= 365;

    useEffect(() => {
        if (!open || !valid) {
            setPreview(null);

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setLoading(true);
            void http
                .get<ShiftPreview>(
                    previewShift.url(campaignId, { query: { days: value } }),
                    { signal: controller.signal },
                )
                .then((result) => setPreview(result.ok ? result.data : null))
                .finally(() => setLoading(false));
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, value, valid, campaignId]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Shift the campaign</DialogTitle>
                    <DialogDescription>
                        Moves its dates, phases, plan and every post that hasn’t
                        gone out. Each channel keeps its local time.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-1.5">
                    <Label htmlFor="shift-days">
                        Days (negative moves it earlier)
                    </Label>
                    <Input
                        id="shift-days"
                        type="number"
                        min={-365}
                        max={365}
                        value={days}
                        onChange={(event) => setDays(event.target.value)}
                        className="w-32"
                    />
                </div>

                {loading && <Spinner />}
                {preview && !loading && (
                    <div className="flex flex-col gap-3 text-sm">
                        {preview.campaign.from && (
                            <p className="flex items-center gap-2">
                                <CalendarClock className="text-muted-foreground size-4" />
                                New dates:{' '}
                                <span className="font-mono">
                                    {formatDateRange(
                                        preview.campaign.from,
                                        preview.campaign.to,
                                    )}
                                </span>
                            </p>
                        )}
                        <p className="text-muted-foreground">
                            {preview.posts.length}{' '}
                            {preview.posts.length === 1 ? 'post' : 'posts'},{' '}
                            {preview.phases}{' '}
                            {preview.phases === 1 ? 'phase' : 'phases'} and{' '}
                            {preview.deliverables} planned{' '}
                            {preview.deliverables === 1 ? 'slot' : 'slots'}{' '}
                            move.
                        </p>
                        {preview.blocked.length > 0 && (
                            <div className="border-warning/25 bg-warning/5 flex flex-col gap-1.5 rounded-xl border p-3">
                                <span className="text-warning flex items-center gap-1.5 text-xs font-medium">
                                    <CircleSlash className="size-3.5" />
                                    Staying put
                                </span>
                                <ul className="flex flex-col gap-1">
                                    {preview.blocked.map((post) => (
                                        <li
                                            key={post.id}
                                            className="flex justify-between gap-3 text-xs"
                                        >
                                            <span className="truncate">
                                                {post.caption ??
                                                    `Post ${post.id}`}
                                            </span>
                                            <span className="text-muted-foreground shrink-0">
                                                {post.reason}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                )}

                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        disabled={!valid || busy || loading}
                        onClick={() =>
                            router.post(
                                shiftCampaign.url(campaignId),
                                { days: value },
                                {
                                    preserveScroll: true,
                                    onStart: () => setBusy(true),
                                    onFinish: () => setBusy(false),
                                    onSuccess: () => onOpenChange(false),
                                },
                            )
                        }
                    >
                        Shift by {valid ? Math.abs(value) : '…'}{' '}
                        {Math.abs(value) === 1 ? 'day' : 'days'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Step C-3.4 — "Save as template": the campaign's phases and plan become day offsets, its brief a
 * skeleton with `{client_name}`; nothing client-specific is kept.
 */
export function SaveTemplateDialog({
    campaignId,
    defaultName,
    open,
    onOpenChange,
}: {
    campaignId: number;
    defaultName: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [name, setName] = useState(defaultName);
    const [description, setDescription] = useState('');
    const [busy, setBusy] = useState(false);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Save as a template</DialogTitle>
                    <DialogDescription>
                        Keeps the phases, the plan and the brief’s structure, as
                        offsets from the start. Nothing client-specific comes
                        along.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="save-template-name">
                            Template name
                        </Label>
                        <Input
                            id="save-template-name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="save-template-description">
                            What it’s for
                        </Label>
                        <Textarea
                            id="save-template-description"
                            rows={2}
                            value={description}
                            onChange={(event) =>
                                setDescription(event.target.value)
                            }
                        />
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        disabled={busy || name.trim() === ''}
                        onClick={() =>
                            router.post(
                                storeTemplate.url(campaignId),
                                { name, description: description || null },
                                {
                                    preserveScroll: true,
                                    onStart: () => setBusy(true),
                                    onFinish: () => setBusy(false),
                                    onSuccess: () => onOpenChange(false),
                                },
                            )
                        }
                    >
                        Save template
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
