import { router } from '@inertiajs/react';
import { AlertTriangle, ShieldAlert, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import type { Phase } from '@/components/campaigns/types';
import { platformIcon } from '@/components/platform-icon';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { getJson, postJson } from '@/lib/campaigns';
import type { JsonResult } from '@/lib/campaigns';
import { formatSchedule } from '@/lib/dates';
import { plan as aiPlan } from '@/routes/campaigns/ai';
import { status as planStatus } from '@/routes/campaigns/ai/plan';
import { accept as acceptPlan } from '@/routes/campaigns/plan';

type Suggestion = {
    title: string;
    platform: string;
    format: string | null;
    pillar: string | null;
    phase_id: number | null;
    planned_for: string;
    planned_time: string | null;
    angle: string | null;
    flags: string[];
    warnings: string[];
};

type PlanRun =
    | { status: 'pending' }
    | { status: 'done'; suggestions: Suggestion[] }
    | { status: 'failed'; message: string };

const POLL_MS = 2000;
const GIVE_UP_MS = 5 * 60 * 1000;

/**
 * Planning runs on the queue (a reasoning model can take over a minute, longer than a request may
 * wait), so start the run, then poll its status until it settles.
 */
async function runPlan(campaignId: number): Promise<JsonResult<Suggestion[]>> {
    const started = await postJson<{ run: string }>(aiPlan.url(campaignId));

    if (!started.ok) {
        return started;
    }

    const deadline = Date.now() + GIVE_UP_MS;

    while (Date.now() < deadline) {
        await new Promise((resolve) => window.setTimeout(resolve, POLL_MS));
        const result = await getJson<PlanRun>(
            planStatus.url({ campaign: campaignId, run: started.data.run }),
        );

        if (!result.ok) {
            return result;
        }

        if (result.data.status === 'done') {
            return { ok: true, data: result.data.suggestions };
        }

        if (result.data.status === 'failed') {
            return { ok: false, message: result.data.message };
        }
    }

    return {
        ok: false,
        message: 'The AI is taking longer than usual. Try again in a moment.',
    };
}

/**
 * Step C-3.3 — "Plan with AI": suggestions open in a review sheet; the person ticks what to keep
 * and only those become deliverables. Flagged lines (Brand Brain do-nots) start unticked; cadence
 * clashes carry a warning.
 */
export function AiPlanner({
    campaignId,
    phases,
}: {
    campaignId: number;
    phases: Phase[];
}) {
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [open, setOpen] = useState(false);
    const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
    const [picked, setPicked] = useState<Set<number>>(new Set());
    const phaseName = (id: number | null) =>
        phases.find((phase) => phase.id === id)?.name ?? 'No phase';

    const fetchPlan = async () => {
        setLoading(true);
        const result = await runPlan(campaignId);
        setLoading(false);

        if (!result.ok) {
            toast.error(result.message);

            return;
        }

        setSuggestions(result.data);
        setPicked(
            new Set(
                result.data
                    .map((suggestion, index) =>
                        suggestion.flags.length === 0 ? index : -1,
                    )
                    .filter((index) => index >= 0),
            ),
        );
        setOpen(true);
    };

    const accept = () => {
        const items = suggestions
            .filter((_, index) => picked.has(index))
            .map((suggestion) => ({
                title: suggestion.title,
                platform: suggestion.platform,
                format: suggestion.format,
                pillar: suggestion.pillar,
                campaign_phase_id: suggestion.phase_id,
                planned_for: suggestion.planned_for,
                planned_time: suggestion.planned_time,
                notes: suggestion.angle,
            }));

        router.post(
            acceptPlan.url(campaignId),
            { items },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => setOpen(false),
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ??
                            'Some suggestions didn’t fit the campaign.',
                    ),
            },
        );
    };

    const toggle = (index: number) =>
        setPicked((current) => {
            const next = new Set(current);

            if (next.has(index)) {
                next.delete(index);
            } else {
                next.add(index);
            }

            return next;
        });

    return (
        <>
            <Button variant="secondary" onClick={fetchPlan} disabled={loading}>
                {loading ? <Spinner /> : <Sparkles />}
                {loading ? 'Planning…' : 'Plan with AI'}
            </Button>
            <Sheet open={open} onOpenChange={setOpen}>
                <SheetContent className="flex w-full flex-col gap-0 sm:max-w-xl">
                    <SheetHeader className="border-border border-b">
                        <SheetTitle className="flex items-center gap-2">
                            <Sparkles className="text-primary size-4" />
                            A suggested plan
                        </SheetTitle>
                        <SheetDescription>
                            From the brief, phases and Brand Brain pillars. Tick
                            what to keep.
                        </SheetDescription>
                    </SheetHeader>
                    <div className="flex-1 overflow-y-auto px-4 py-2">
                        {suggestions.length === 0 ? (
                            <p className="text-muted-foreground py-10 text-center text-sm">
                                Nothing came back this time. Give it another go,
                                or fill the plan by hand.
                            </p>
                        ) : (
                            <ul className="divide-border/70 flex flex-col divide-y">
                                {suggestions.map((suggestion, index) => {
                                    const Icon = platformIcon(
                                        suggestion.platform,
                                    );

                                    return (
                                        <li
                                            key={index}
                                            className="flex gap-3 py-3.5"
                                        >
                                            <Checkbox
                                                id={`suggestion-${index}`}
                                                checked={picked.has(index)}
                                                onCheckedChange={() =>
                                                    toggle(index)
                                                }
                                                className="mt-0.5"
                                            />
                                            <label
                                                htmlFor={`suggestion-${index}`}
                                                className="flex min-w-0 flex-1 cursor-pointer flex-col gap-1"
                                            >
                                                <span className="flex items-center gap-2 text-sm font-medium">
                                                    <Icon className="text-muted-foreground size-4 shrink-0" />
                                                    {suggestion.title}
                                                </span>
                                                {suggestion.angle && (
                                                    <span className="text-muted-foreground text-[13px]">
                                                        {suggestion.angle}
                                                    </span>
                                                )}
                                                <span className="text-muted-foreground font-mono text-[11px]">
                                                    {formatSchedule(
                                                        suggestion.planned_for,
                                                        suggestion.planned_time,
                                                    )}{' '}
                                                    ·{' '}
                                                    {phaseName(
                                                        suggestion.phase_id,
                                                    )}
                                                    {suggestion.format
                                                        ? ` · ${suggestion.format}`
                                                        : ''}
                                                    {suggestion.pillar
                                                        ? ` · ${suggestion.pillar}`
                                                        : ''}
                                                </span>
                                                {suggestion.warnings.map(
                                                    (warning) => (
                                                        <span
                                                            key={warning}
                                                            className="text-warning flex items-center gap-1 text-xs"
                                                        >
                                                            <AlertTriangle className="size-3.5" />
                                                            {warning}
                                                        </span>
                                                    ),
                                                )}
                                                {suggestion.flags.length >
                                                    0 && (
                                                    <span className="text-destructive flex items-center gap-1 text-xs">
                                                        <ShieldAlert className="size-3.5" />
                                                        Mentions{' '}
                                                        {suggestion.flags.join(
                                                            ', ',
                                                        )}
                                                        , which the Brand Brain
                                                        rules out.
                                                    </span>
                                                )}
                                            </label>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                    <SheetFooter className="border-border flex-row items-center justify-between border-t">
                        <span className="text-muted-foreground text-xs">
                            {picked.size} of {suggestions.length} picked
                        </span>
                        <div className="flex gap-2">
                            <Button
                                variant="ghost"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={accept}
                                disabled={saving || picked.size === 0}
                            >
                                Add {picked.size} to the plan
                            </Button>
                        </div>
                    </SheetFooter>
                </SheetContent>
            </Sheet>
        </>
    );
}
