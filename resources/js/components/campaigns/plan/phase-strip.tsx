import { router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Pencil,
    Plus,
    Sparkles,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import {
    campaignSwatch,
    campaignSwatchText,
} from '@/components/campaigns/campaign-chip';
import { PhaseDialog } from '@/components/campaigns/plan/phase-dialog';
import type { Phase } from '@/components/campaigns/types';
import { confirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { formatDateRange } from '@/lib/dates';
import { cn } from '@/lib/utils';
import {
    destroy as destroyPhase,
    reorder as reorderPhases,
    suggested as applySuggested,
} from '@/routes/campaigns/phases';

type SuggestedPhase = {
    name: string;
    starts_on: string;
    ends_on: string;
    colour: string;
};

function days(start: string | null, end: string | null): number {
    if (!start || !end) {
        return 1;
    }

    return Math.max(
        1,
        Math.round((Date.parse(end) - Date.parse(start)) / 86_400_000) + 1,
    );
}

/**
 * Step C-3.1 — the campaign's phases as one ruled band, each segment as wide as the phase is long,
 * with counts of what's planned in it. Owners/Strategists add, edit, reorder and remove; a dated
 * campaign with no phases is offered the suggested split (one click, never automatic).
 */
export function PhaseStrip({
    campaignId,
    phases,
    counts,
    suggested,
    editable,
    bounds,
}: {
    campaignId: number;
    phases: Phase[];
    counts: Record<number, number>;
    suggested: SuggestedPhase[];
    editable: boolean;
    bounds: { start: string | null; end: string | null };
}) {
    const [dialog, setDialog] = useState<{
        open: boolean;
        phase: Phase | null;
    }>({ open: false, phase: null });

    const move = (index: number, direction: -1 | 1) => {
        const order = phases.map((phase) => phase.id);
        const [moved] = order.splice(index, 1);
        order.splice(index + direction, 0, moved);
        router.post(
            reorderPhases.url(campaignId),
            { order },
            { preserveScroll: true },
        );
    };

    const remove = async (phase: Phase) => {
        if (
            await confirmDialog({
                title: `Remove the ${phase.name} phase?`,
                description:
                    'Its deliverables stay in the plan, just unphased.',
                confirmLabel: 'Remove phase',
                tone: 'destructive',
            })
        ) {
            router.delete(
                destroyPhase.url({ campaign: campaignId, phase: phase.id }),
                { preserveScroll: true },
            );
        }
    };

    if (phases.length === 0) {
        return (
            <section className="border-border bg-card/50 flex flex-col gap-4 rounded-2xl border-2 border-dashed p-5 sm:p-6">
                <div className="flex flex-col gap-1">
                    <p className="font-display text-lg">
                        Add phases, then plan what goes out when.
                    </p>
                    <p className="text-muted-foreground text-sm">
                        Phases turn a long campaign into chapters the team and
                        the client can follow.
                    </p>
                </div>
                {suggested.length > 0 && (
                    <div
                        className="flex h-9 overflow-hidden rounded-full"
                        aria-label="Suggested phases"
                    >
                        {suggested.map((phase) => (
                            <div
                                key={phase.name}
                                className={cn(
                                    'flex min-w-0 items-center justify-center px-2 text-[11px] font-medium',
                                    campaignSwatch(phase.colour),
                                    campaignSwatchText(phase.colour),
                                )}
                                style={{
                                    flexGrow: days(
                                        phase.starts_on,
                                        phase.ends_on,
                                    ),
                                }}
                                title={`${phase.name}: ${formatDateRange(phase.starts_on, phase.ends_on)}`}
                            >
                                <span className="truncate">{phase.name}</span>
                            </div>
                        ))}
                    </div>
                )}
                {editable && (
                    <div className="flex flex-wrap gap-2">
                        {suggested.length > 0 && (
                            <Button
                                onClick={() =>
                                    router.post(
                                        applySuggested.url(campaignId),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Sparkles />
                                Use suggested phases
                            </Button>
                        )}
                        <Button
                            variant="outline"
                            onClick={() =>
                                setDialog({ open: true, phase: null })
                            }
                        >
                            <Plus />
                            Add a phase
                        </Button>
                    </div>
                )}
                <PhaseDialog
                    key={dialog.phase?.id ?? 'new'}
                    campaignId={campaignId}
                    phase={dialog.phase}
                    open={dialog.open}
                    onOpenChange={(open) =>
                        setDialog((current) => ({ ...current, open }))
                    }
                    bounds={bounds}
                />
            </section>
        );
    }

    return (
        <section aria-label="Phases" className="flex flex-col gap-3">
            <div className="flex items-center justify-between gap-3">
                <span className="eyebrow text-muted-foreground">Phases</span>
                {editable && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setDialog({ open: true, phase: null })}
                    >
                        <Plus />
                        Add phase
                    </Button>
                )}
            </div>
            <ol className="flex flex-col gap-2 md:flex-row md:gap-1.5">
                {phases.map((phase, index) => (
                    <li
                        key={phase.id}
                        className="group border-border bg-card relative flex min-w-0 flex-col gap-2 overflow-hidden rounded-xl border p-3 pt-4 shadow-sm md:basis-0"
                        style={{
                            flexGrow: days(phase.starts_on, phase.ends_on),
                        }}
                    >
                        <span
                            aria-hidden
                            className={cn(
                                'absolute inset-x-0 top-0 h-1.5',
                                campaignSwatch(phase.colour),
                            )}
                        />
                        <div className="flex items-start justify-between gap-2">
                            <span className="min-w-0 truncate text-sm font-semibold">
                                {phase.name}
                            </span>
                            <span className="text-muted-foreground shrink-0 font-mono text-[11px] tabular-nums">
                                {counts[phase.id] ?? 0}
                            </span>
                        </div>
                        <span className="text-muted-foreground font-mono text-[11px]">
                            {phase.starts_on
                                ? formatDateRange(
                                      phase.starts_on,
                                      phase.ends_on,
                                  )
                                : 'No dates'}
                        </span>
                        {editable && (
                            <div className="flex gap-0.5 opacity-100 transition-opacity md:opacity-0 md:group-focus-within:opacity-100 md:group-hover:opacity-100">
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-7"
                                    aria-label={`Edit ${phase.name}`}
                                    onClick={() =>
                                        setDialog({ open: true, phase })
                                    }
                                >
                                    <Pencil className="size-3.5" />
                                </Button>
                                {index > 0 && (
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        className="size-7"
                                        aria-label={`Move ${phase.name} earlier`}
                                        onClick={() => move(index, -1)}
                                    >
                                        <ArrowLeft className="size-3.5" />
                                    </Button>
                                )}
                                {index < phases.length - 1 && (
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        className="size-7"
                                        aria-label={`Move ${phase.name} later`}
                                        onClick={() => move(index, 1)}
                                    >
                                        <ArrowRight className="size-3.5" />
                                    </Button>
                                )}
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="text-destructive size-7"
                                    aria-label={`Remove ${phase.name}`}
                                    onClick={() => remove(phase)}
                                >
                                    <Trash2 className="size-3.5" />
                                </Button>
                            </div>
                        )}
                    </li>
                ))}
            </ol>
            <PhaseDialog
                key={dialog.phase?.id ?? 'new'}
                campaignId={campaignId}
                phase={dialog.phase}
                open={dialog.open}
                onOpenChange={(open) =>
                    setDialog((current) => ({ ...current, open }))
                }
                bounds={bounds}
            />
        </section>
    );
}
