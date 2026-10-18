import { Link, router } from '@inertiajs/react';
import { ArrowRightLeft, KanbanSquare, UserRound } from 'lucide-react';
import { useMemo, useState } from 'react';
import { CampaignSwatch } from '@/components/campaigns/campaign-chip';
import { CampaignLayout } from '@/components/campaigns/campaign-layout';
import type {
    CampaignPost,
    CampaignWorkspaceProps,
    Deliverable,
    Phase,
} from '@/components/campaigns/types';
import type { KanbanColumn } from '@/components/kanban/kanban-board';
import { KanbanBoard } from '@/components/kanban/kanban-board';
import { EmptyState } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate, formatSchedule } from '@/lib/dates';
import { resolveStatus, toneBgClass } from '@/lib/status';
import { cn } from '@/lib/utils';
import { plan as planTab, index as campaignsIndex } from '@/routes/campaigns';
import { move as moveOnBoard } from '@/routes/campaigns/board';
import { edit as editPost } from '@/routes/posts';

type BoardPost = CampaignPost & {
    assignee: string | null;
    drop_targets: string[];
    can_edit: boolean;
};

type BoardColumn = {
    key: string;
    label: string;
    drop_status: string | null;
    posts: BoardPost[];
};

type BoardProps = CampaignWorkspaceProps & {
    columns: BoardColumn[];
    notStarted: Deliverable[];
    phases: Phase[];
};

const ALL = '__all';

/**
 * Step C-4.3 — the campaign board: posts by status plus a "Not started" column of planned slots.
 * Dragging only offers the columns the state machine and the viewer's role allow; the server
 * re-validates every move. Viewers get a read-only board. Filters: phase, platform, assignee.
 */
export default function CampaignBoard(props: BoardProps) {
    const { campaign, can, columns, notStarted, phases } = props;
    const [phase, setPhase] = useState(ALL);
    const [platform, setPlatform] = useState(ALL);
    const [assignee, setAssignee] = useState(ALL);
    // Only producing roles can move posts; each card's own `drop_targets` says where it may go.
    const readOnly = !can.produce;

    const assignees = useMemo(
        () =>
            [
                ...new Set(
                    [
                        ...columns.flatMap((column) =>
                            column.posts.map((post) => post.assignee),
                        ),
                        ...notStarted.map(
                            (item) => item.assignee?.name ?? null,
                        ),
                    ].filter((name): name is string => Boolean(name)),
                ),
            ].sort(),
        [columns, notStarted],
    );

    const matches = (
        phaseId: number | null,
        platforms: (string | null)[],
        person: string | null,
    ) =>
        (phase === ALL || String(phaseId) === phase) &&
        (platform === ALL || platforms.includes(platform)) &&
        (assignee === ALL || person === assignee);

    const kanbanColumns: KanbanColumn<BoardPost>[] = columns.map((column) => ({
        key: column.key,
        label: column.label,
        dropStatus: column.drop_status,
        tone: toneBgClass[resolveStatus('post', column.key).tone],
        items: column.posts.filter((post) =>
            matches(
                post.phase_id,
                post.targets.map((target) => target.platform),
                post.assignee,
            ),
        ),
    }));

    const planned = notStarted.filter((item) =>
        matches(item.phase_id, [item.platform], item.assignee?.name ?? null),
    );

    const move = (post: BoardPost, status: string) =>
        router.post(
            moveOnBoard.url({ campaign: campaign.id, post: post.id }),
            { to: status },
            { preserveScroll: true, preserveState: true },
        );

    const statusLabel = (status: string) => resolveStatus('post', status).label;

    return (
        <CampaignLayout {...props}>
            <div className="flex flex-wrap items-center gap-2">
                <FilterSelect
                    label="Phase"
                    value={phase}
                    onChange={setPhase}
                    options={phases.map((item) => ({
                        value: String(item.id),
                        label: item.name,
                    }))}
                />
                <FilterSelect
                    label="Platform"
                    value={platform}
                    onChange={setPlatform}
                    options={[
                        'instagram',
                        'tiktok',
                        'facebook',
                        'linkedin',
                    ].map((value) => ({
                        value,
                        label: value.charAt(0).toUpperCase() + value.slice(1),
                    }))}
                />
                <FilterSelect
                    label="Assignee"
                    value={assignee}
                    onChange={setAssignee}
                    options={assignees.map((name) => ({
                        value: name,
                        label: name,
                    }))}
                />
                {readOnly && (
                    <span className="text-muted-foreground text-xs">
                        Read-only: you can see the board but not move posts.
                    </span>
                )}
            </div>

            {kanbanColumns.every((column) => column.items.length === 0) &&
            planned.length === 0 ? (
                <EmptyState
                    icon={KanbanSquare}
                    title="An empty board."
                    description="Plan deliverables and start posts from them, and the work shows up here as it moves."
                    action={
                        <Button asChild variant="outline">
                            <Link href={planTab(campaign.id)}>
                                Open the plan
                            </Link>
                        </Button>
                    }
                />
            ) : (
                <div className="flex flex-col gap-3 md:flex-row md:items-start">
                    <section
                        aria-label="Not started"
                        className="border-border flex w-full shrink-0 flex-col gap-2 rounded-2xl border border-dashed p-2 md:w-64"
                    >
                        <header className="flex items-center justify-between px-2 pt-1 pb-1.5">
                            <span className="text-[13px] font-semibold">
                                Not started
                            </span>
                            <span className="text-muted-foreground font-mono text-[11px]">
                                {planned.length}
                            </span>
                        </header>
                        <ol className="flex max-h-[60vh] flex-col gap-2 overflow-y-auto">
                            {planned.map((item) => {
                                const Icon = platformIcon(item.platform);

                                return (
                                    <li
                                        key={item.id}
                                        className="bg-card/60 border-border rounded-xl border p-3"
                                    >
                                        <span className="flex items-start gap-2 text-sm">
                                            <Icon className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                                            {item.title}
                                        </span>
                                        <span
                                            className={cn(
                                                'mt-1 block font-mono text-[11px]',
                                                item.is_overdue
                                                    ? 'text-destructive'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {formatSchedule(
                                                item.planned_for,
                                                item.planned_time,
                                                'No date',
                                            )}
                                            {item.due_on
                                                ? ` · due ${formatDate(item.due_on)}`
                                                : ''}
                                        </span>
                                    </li>
                                );
                            })}
                            {planned.length === 0 && (
                                <li className="text-muted-foreground px-2 py-4 text-center text-xs">
                                    Everything’s started.
                                </li>
                            )}
                        </ol>
                    </section>

                    <div className="min-w-0 flex-1">
                        <KanbanBoard
                            columns={kanbanColumns}
                            dropTargets={(post) => post.drop_targets}
                            onMove={move}
                            readOnly={readOnly}
                            renderCard={(post) => {
                                const phaseOf = phases.find(
                                    (item) => item.id === post.phase_id,
                                );

                                return (
                                    <article className="bg-card border-border hover:border-primary/40 flex flex-col gap-2 rounded-xl border p-3 shadow-sm transition-colors">
                                        <Link
                                            href={editPost(post.id)}
                                            className="hover:text-primary line-clamp-3 text-sm font-medium"
                                            draggable={false}
                                        >
                                            {post.master_caption ||
                                                'Untitled post'}
                                        </Link>
                                        <div className="text-muted-foreground flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px]">
                                            {post.targets.map(
                                                (target, index) => {
                                                    const Icon = platformIcon(
                                                        target.platform,
                                                    );

                                                    return (
                                                        <Icon
                                                            key={index}
                                                            className="size-3.5"
                                                            aria-label={
                                                                target.platform ??
                                                                undefined
                                                            }
                                                        />
                                                    );
                                                },
                                            )}
                                            <span className="font-mono">
                                                {formatSchedule(
                                                    post.scheduled_local_date,
                                                    post.scheduled_local_time,
                                                    'Unscheduled',
                                                )}
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-muted-foreground flex min-w-0 items-center gap-1.5 text-[11px]">
                                                {phaseOf && (
                                                    <CampaignSwatch
                                                        colour={phaseOf.colour}
                                                    />
                                                )}
                                                {post.assignee && (
                                                    <span className="inline-flex items-center gap-1 truncate">
                                                        <UserRound className="size-3" />
                                                        {post.assignee}
                                                    </span>
                                                )}
                                            </span>
                                            {post.drop_targets.length > 0 && (
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                        asChild
                                                    >
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            className="size-7"
                                                            aria-label="Move to"
                                                        >
                                                            <ArrowRightLeft className="size-3.5" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuLabel>
                                                            Move to
                                                        </DropdownMenuLabel>
                                                        {post.drop_targets.map(
                                                            (status) => (
                                                                <DropdownMenuItem
                                                                    key={status}
                                                                    onSelect={() =>
                                                                        move(
                                                                            post,
                                                                            status,
                                                                        )
                                                                    }
                                                                >
                                                                    {statusLabel(
                                                                        status,
                                                                    )}
                                                                </DropdownMenuItem>
                                                            ),
                                                        )}
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            )}
                                        </div>
                                    </article>
                                );
                            }}
                        />
                    </div>
                </div>
            )}
        </CampaignLayout>
    );
}

function FilterSelect({
    label,
    value,
    onChange,
    options,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger
                className="h-9 w-auto min-w-36 rounded-full"
                aria-label={label}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>Any {label.toLowerCase()}</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

CampaignBoard.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};
