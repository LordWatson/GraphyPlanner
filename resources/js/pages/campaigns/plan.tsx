import { Link } from '@inertiajs/react';
import { ClipboardList, LayoutGrid, List, Plus, Rows3 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { CampaignSwatch } from '@/components/campaigns/campaign-chip';
import { CampaignLayout } from '@/components/campaigns/campaign-layout';
import { AiPlanner } from '@/components/campaigns/plan/ai-planner';
import { BulkAddDialog } from '@/components/campaigns/plan/bulk-add-dialog';
import {
    DeliverableCard,
    StartPostButton,
} from '@/components/campaigns/plan/deliverable-card';
import { DeliverableDialog } from '@/components/campaigns/plan/deliverable-dialog';
import { PhaseStrip } from '@/components/campaigns/plan/phase-strip';
import type { PlanData } from '@/components/campaigns/plan/types';
import type {
    CampaignWorkspaceProps,
    Deliverable,
} from '@/components/campaigns/types';
import { EmptyState } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatDate, formatSchedule } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { index as campaignsIndex } from '@/routes/campaigns';
import { edit as editPost } from '@/routes/posts';

type View = 'matrix' | 'list';
const VIEW_KEY = 'campaign-plan-view';

function readView(): View {
    try {
        return window.localStorage.getItem(VIEW_KEY) === 'list'
            ? 'list'
            : 'matrix';
    } catch {
        return 'matrix';
    }
}

/**
 * Steps C-3.1 / C-3.2 / C-3.3 — the Plan tab: phases as a proportional band, then the
 * deliverables as a phases × platforms matrix (or a dated list). Planning is Owner/Strategist;
 * starting a post from a slot is anyone who produces (incl. Designers); Viewers read.
 */
export default function CampaignPlan(props: CampaignWorkspaceProps & PlanData) {
    const { campaign, can } = props;
    const plan: PlanData = props;
    const [view, setView] = useState<View>(readView);
    const [editing, setEditing] = useState<{
        open: boolean;
        deliverable: Deliverable | null;
        phaseId?: number | null;
        platform?: string;
    }>({ open: false, deliverable: null });
    const [bulkOpen, setBulkOpen] = useState(false);

    const platforms = useMemo(() => {
        const used = new Set<string>([
            ...campaign.platforms,
            ...plan.deliverables.map((deliverable) => deliverable.platform),
        ]);

        return plan.platformOptions.filter((option) => used.has(option.value));
    }, [campaign.platforms, plan.deliverables, plan.platformOptions]);

    const rows = useMemo(
        () => [
            ...plan.phases.map((phase) => ({
                id: phase.id as number | null,
                name: phase.name,
                colour: phase.colour,
            })),
            ...(plan.deliverables.some(
                (deliverable) => deliverable.phase_id === null,
            ) || plan.phases.length === 0
                ? [
                      {
                          id: null,
                          name:
                              plan.phases.length === 0
                                  ? 'The plan'
                                  : 'Unphased',
                          colour: null,
                      },
                  ]
                : []),
        ],
        [plan.phases, plan.deliverables],
    );

    const phaseCounts = useMemo(
        () =>
            plan.deliverables.reduce<Record<number, number>>(
                (counts, deliverable) => {
                    if (deliverable.phase_id !== null) {
                        counts[deliverable.phase_id] =
                            (counts[deliverable.phase_id] ?? 0) + 1;
                    }

                    return counts;
                },
                {},
            ),
        [plan.deliverables],
    );

    const changeView = (next: string) => {
        if (next !== 'matrix' && next !== 'list') {
            return;
        }

        setView(next);

        try {
            window.localStorage.setItem(VIEW_KEY, next);
        } catch {
            // Storage unavailable (private window): the choice just isn't remembered.
        }
    };

    const open = (deliverable: Deliverable) =>
        setEditing({ open: true, deliverable });
    const openNew = (phaseId: number | null = null, platform?: string) =>
        setEditing({ open: true, deliverable: null, phaseId, platform });

    return (
        <CampaignLayout {...props}>
            <PhaseStrip
                campaignId={campaign.id}
                phases={plan.phases}
                counts={phaseCounts}
                suggested={plan.suggestedPhases}
                editable={can.update}
                bounds={{ start: campaign.start_date, end: campaign.end_date }}
            />

            <section className="flex flex-col gap-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div className="flex flex-col gap-1">
                        <span className="eyebrow text-muted-foreground">
                            Deliverables
                        </span>
                        <h2 className="font-display text-[1.6rem] leading-tight">
                            What goes out, when.
                        </h2>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <ToggleGroup
                            type="single"
                            value={view}
                            onValueChange={changeView}
                            aria-label="Plan view"
                        >
                            <ToggleGroupItem
                                value="matrix"
                                aria-label="Matrix"
                                className="px-3"
                            >
                                <LayoutGrid />
                            </ToggleGroupItem>
                            <ToggleGroupItem
                                value="list"
                                aria-label="List"
                                className="px-3"
                            >
                                <List />
                            </ToggleGroupItem>
                        </ToggleGroup>
                        {can.aiPlanner && (
                            <AiPlanner
                                campaignId={campaign.id}
                                phases={plan.phases}
                            />
                        )}
                        {can.update && (
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() => setBulkOpen(true)}
                                >
                                    <Rows3 />
                                    Fill the plan
                                </Button>
                                <Button
                                    onClick={() =>
                                        openNew(plan.phases[0]?.id ?? null)
                                    }
                                >
                                    <Plus />
                                    Add deliverable
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {plan.deliverables.length === 0 ? (
                    <EmptyState
                        icon={ClipboardList}
                        title="Nothing planned yet."
                        description={
                            can.update
                                ? 'Add slots one by one, fill a rhythm in one go, or let the AI suggest a plan from the brief.'
                                : 'The strategist hasn’t planned this campaign yet.'
                        }
                    />
                ) : view === 'matrix' ? (
                    <div className="border-border bg-border/60 overflow-x-auto rounded-2xl border">
                        <div
                            className="grid min-w-[720px] gap-px"
                            style={{
                                gridTemplateColumns: `180px repeat(${Math.max(platforms.length, 1)}, minmax(220px, 1fr))`,
                            }}
                        >
                            <div className="bg-muted/60 eyebrow text-muted-foreground px-4 py-3">
                                Phase
                            </div>
                            {platforms.map((platform) => {
                                const Icon = platformIcon(platform.value);

                                return (
                                    <div
                                        key={platform.value}
                                        className="bg-muted/60 flex items-center gap-2 px-4 py-3 text-sm font-medium"
                                    >
                                        <Icon className="size-4" />
                                        {platform.label}
                                    </div>
                                );
                            })}
                            {rows.map((row) => (
                                <MatrixRow
                                    key={row.id ?? 'none'}
                                    row={row}
                                    platforms={platforms.map(
                                        (platform) => platform.value,
                                    )}
                                    deliverables={plan.deliverables}
                                    renderCard={(deliverable) => (
                                        <DeliverableCard
                                            key={deliverable.id}
                                            campaignId={campaign.id}
                                            deliverable={deliverable}
                                            accounts={plan.accounts}
                                            canProduce={can.produce}
                                            onOpen={() => open(deliverable)}
                                        />
                                    )}
                                    onAdd={
                                        can.update
                                            ? (platform) =>
                                                  openNew(row.id, platform)
                                            : undefined
                                    }
                                />
                            ))}
                        </div>
                    </div>
                ) : (
                    <ul className="border-border bg-card divide-border/70 flex flex-col divide-y overflow-hidden rounded-2xl border shadow-sm">
                        {plan.deliverables.map((deliverable) => {
                            const Icon = platformIcon(deliverable.platform);
                            const phase = plan.phases.find(
                                (candidate) =>
                                    candidate.id === deliverable.phase_id,
                            );

                            return (
                                <li
                                    key={deliverable.id}
                                    className="hover:bg-muted/50 flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:gap-4 sm:px-5"
                                >
                                    <span className="text-muted-foreground w-36 shrink-0 font-mono text-[11px] tabular-nums">
                                        {formatSchedule(
                                            deliverable.planned_for,
                                            deliverable.planned_time,
                                            'No date',
                                        )}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => open(deliverable)}
                                        className="flex min-w-0 flex-1 items-center gap-2 text-left"
                                    >
                                        <Icon className="text-muted-foreground size-4 shrink-0" />
                                        <span className="hover:text-primary truncate text-sm font-medium">
                                            {deliverable.title}
                                        </span>
                                    </button>
                                    <span className="text-muted-foreground flex w-32 shrink-0 items-center gap-1.5 truncate text-xs">
                                        {phase && (
                                            <CampaignSwatch
                                                colour={phase.colour}
                                            />
                                        )}
                                        {phase?.name ?? 'Unphased'}
                                    </span>
                                    <span
                                        className={cn(
                                            'w-40 shrink-0 truncate text-xs',
                                            deliverable.is_overdue
                                                ? 'text-destructive font-medium'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {deliverable.assignee?.name ??
                                            'Unassigned'}
                                        {deliverable.due_on
                                            ? ` · ${formatDate(deliverable.due_on)}`
                                            : ''}
                                    </span>
                                    <StatusPill
                                        kind="deliverable"
                                        value={deliverable.state}
                                        label={deliverable.state_label}
                                        className="shrink-0"
                                    />
                                    <span className="shrink-0">
                                        {deliverable.post_id ? (
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="ghost"
                                            >
                                                <Link
                                                    href={editPost(
                                                        deliverable.post_id,
                                                    )}
                                                >
                                                    Post
                                                </Link>
                                            </Button>
                                        ) : (
                                            can.produce && (
                                                <StartPostButton
                                                    campaignId={campaign.id}
                                                    deliverable={deliverable}
                                                    accounts={plan.accounts}
                                                />
                                            )
                                        )}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>

            {editing.open && (
                <DeliverableDialog
                    key={
                        editing.deliverable?.id ??
                        `new-${editing.phaseId}-${editing.platform}`
                    }
                    campaignId={campaign.id}
                    deliverable={editing.deliverable}
                    plan={plan}
                    open={editing.open}
                    onOpenChange={(isOpen) =>
                        setEditing((current) => ({ ...current, open: isOpen }))
                    }
                    checklistOnly={!can.update}
                    defaults={
                        editing.deliverable
                            ? undefined
                            : {
                                  campaign_phase_id: editing.phaseId
                                      ? String(editing.phaseId)
                                      : '__none',
                                  ...(editing.platform
                                      ? { platform: editing.platform }
                                      : {}),
                              }
                    }
                />
            )}
            {can.update && (
                <BulkAddDialog
                    campaignId={campaign.id}
                    plan={plan}
                    defaultPlatforms={
                        campaign.platforms.length > 0
                            ? campaign.platforms
                            : platforms.map((platform) => platform.value)
                    }
                    open={bulkOpen}
                    onOpenChange={setBulkOpen}
                />
            )}
        </CampaignLayout>
    );
}

function MatrixRow({
    row,
    platforms,
    deliverables,
    renderCard,
    onAdd,
}: {
    row: { id: number | null; name: string; colour: string | null };
    platforms: string[];
    deliverables: Deliverable[];
    renderCard: (deliverable: Deliverable) => React.ReactNode;
    onAdd?: (platform: string) => void;
}) {
    return (
        <>
            <div className="bg-card flex items-start gap-2 px-4 py-4">
                <CampaignSwatch colour={row.colour} className="mt-1" />
                <span className="text-sm font-semibold">{row.name}</span>
            </div>
            {(platforms.length > 0 ? platforms : ['']).map((platform) => {
                const cell = deliverables.filter(
                    (deliverable) =>
                        deliverable.phase_id === row.id &&
                        (platform === '' || deliverable.platform === platform),
                );

                return (
                    <div
                        key={platform}
                        className="bg-card flex flex-col gap-2 p-2.5"
                    >
                        {cell.map(renderCard)}
                        {onAdd && platform !== '' && (
                            <button
                                type="button"
                                onClick={() => onAdd(platform)}
                                className="text-muted-foreground hover:border-primary/40 hover:text-foreground border-border flex h-9 items-center justify-center gap-1.5 rounded-lg border border-dashed text-xs transition-colors"
                            >
                                <Plus className="size-3.5" />
                                Add
                            </button>
                        )}
                    </div>
                );
            })}
        </>
    );
}

CampaignPlan.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};
