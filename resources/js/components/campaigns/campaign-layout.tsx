import { Head, Link, router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Activity,
    ArrowLeft,
    BarChart3,
    CalendarClock,
    CheckCheck,
    ChevronDown,
    ClipboardList,
    FileText,
    FolderOpen,
    KanbanSquare,
    LayoutDashboard,
    LayoutTemplate,
    Link2,
    Pencil,
    Trash2,
    UserRound,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { CampaignSwatch } from '@/components/campaigns/campaign-chip';
import { HealthPill } from '@/components/campaigns/health-pill';
import {
    ResumeDialog,
    SaveTemplateDialog,
    ShiftDialog,
} from '@/components/campaigns/run-dialogs';
import { ShareLinksDialog } from '@/components/campaigns/share-links-dialog';
import type {
    CampaignWorkspaceProps,
    WorkspaceTab,
} from '@/components/campaigns/types';
import { confirmDialog } from '@/components/confirm-dialog';
import { Page, PageHeader } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDateRange } from '@/lib/dates';
import { cn } from '@/lib/utils';
import {
    activity as activityTab,
    approvals as approvalsTab,
    assets as assetsTab,
    board as boardTab,
    brief as briefTab,
    destroy as destroyCampaign,
    edit as editCampaign,
    pause as pauseCampaign,
    plan as planTab,
    results as resultsTab,
    show as overviewTab,
    transition as transitionCampaign,
} from '@/routes/campaigns';
import { index as clientCampaigns } from '@/routes/clients/campaigns';

type TabDefinition = {
    key: WorkspaceTab;
    label: string;
    icon: LucideIcon;
    href: (id: number) => ReturnType<typeof overviewTab>;
    count?: (counts: CampaignWorkspaceProps['counts']) => number | null;
};

const TABS: TabDefinition[] = [
    {
        key: 'overview',
        label: 'Overview',
        icon: LayoutDashboard,
        href: overviewTab,
    },
    { key: 'brief', label: 'Brief', icon: FileText, href: briefTab },
    {
        key: 'plan',
        label: 'Plan',
        icon: ClipboardList,
        href: planTab,
        count: (counts) => counts.deliverables || null,
    },
    {
        key: 'board',
        label: 'Board',
        icon: KanbanSquare,
        href: boardTab,
        count: (counts) => counts.posts || null,
    },
    {
        key: 'assets',
        label: 'Assets',
        icon: FolderOpen,
        href: assetsTab,
        count: (counts) => counts.assets || null,
    },
    {
        key: 'approvals',
        label: 'Approvals',
        icon: CheckCheck,
        href: approvalsTab,
        count: (counts) => counts.inReview + counts.awaitingClient || null,
    },
    { key: 'results', label: 'Results', icon: BarChart3, href: resultsTab },
    { key: 'activity', label: 'Activity', icon: Activity, href: activityTab },
];

/** Moves that end or shelve work are confirmed first; everything else is one click. */
const CONFIRMED_MOVES: Record<string, string> = {
    completed: 'Mark as complete',
    archived: 'Archive',
};

/**
 * Step C-1.2 — the campaign workspace chrome every tab shares: back to the client, the header
 * (status, health with its reasons, the timeline line, owner), the status actions the viewer may
 * take, and the tab row. Tabs are real routes, so the back button and deep links work.
 */
export function CampaignLayout({
    campaign,
    tab,
    tabs,
    can,
    counts,
    shareLinks,
    children,
    actions,
}: CampaignWorkspaceProps & { children: ReactNode; actions?: ReactNode }) {
    const [busy, setBusy] = useState(false);
    const [resumeOpen, setResumeOpen] = useState(false);
    const [shiftOpen, setShiftOpen] = useState(false);
    const [templateOpen, setTemplateOpen] = useState(false);
    const [shareOpen, setShareOpen] = useState(false);
    const [primary, ...secondary] = campaign.transitions;

    const transition = async (to: string, label: string) => {
        // C-6.1: resuming asks what to do with the paused posts.
        if (campaign.status === 'paused' && to === 'active') {
            setResumeOpen(true);

            return;
        }

        if (to === 'paused') {
            if (
                !(await confirmDialog({
                    title: `Pause ${campaign.name}?`,
                    description:
                        counts.scheduled === 0
                            ? 'Nothing is scheduled right now, so nothing stops. You can resume any time.'
                            : `${counts.scheduled} scheduled ${counts.scheduled === 1 ? 'post' : 'posts'} will stop and go back to approved. Resume brings ${counts.scheduled === 1 ? 'it' : 'them'} back.`,
                    confirmLabel: 'Pause campaign',
                }))
            ) {
                return;
            }

            router.post(
                pauseCampaign.url(campaign.id),
                {},
                {
                    preserveScroll: true,
                    onStart: () => setBusy(true),
                    onFinish: () => setBusy(false),
                },
            );

            return;
        }

        if (
            CONFIRMED_MOVES[to] &&
            !(await confirmDialog({
                title: `${label}: ${campaign.name}?`,
                description:
                    to === 'archived'
                        ? 'It leaves the hub and the client portal’s list. You can restore it any time.'
                        : 'The campaign is marked as wrapped. Its posts and results stay exactly as they are.',
                confirmLabel: label,
            }))
        ) {
            return;
        }

        router.post(
            transitionCampaign.url(campaign.id),
            { to },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );
    };

    const handleDelete = async () => {
        if (
            !(await confirmDialog({
                title: `Delete ${campaign.name}?`,
                description:
                    'The campaign, its brief and plan go. Its posts stay on the client, just no longer grouped.',
                confirmLabel: 'Delete campaign',
                tone: 'destructive',
            }))
        ) {
            return;
        }

        router.delete(destroyCampaign.url(campaign.id));
    };

    const visibleTabs = TABS.filter((definition) =>
        tabs.includes(definition.key),
    );

    return (
        <>
            <Head title={`${campaign.name} — ${campaign.client.name ?? ''}`} />
            <Page>
                <Link
                    href={clientCampaigns(campaign.client.id)}
                    className="text-muted-foreground hover:text-foreground -mb-4 inline-flex items-center gap-1.5 self-start text-sm"
                >
                    <ArrowLeft className="size-4" />
                    {campaign.client.name}
                </Link>

                <PageHeader
                    eyebrow={
                        <span className="inline-flex items-center gap-2">
                            <CampaignSwatch colour={campaign.colour} />
                            {campaign.code
                                ? `Campaign ${campaign.code}`
                                : 'Campaign'}
                        </span>
                    }
                    title={campaign.name}
                    description={campaign.goal ?? undefined}
                    meta={
                        <>
                            <StatusPill
                                kind="campaign"
                                value={campaign.status}
                                label={campaign.status_label}
                            />
                            <HealthPill health={campaign.health} />
                            <span className="eyebrow text-muted-foreground">
                                {formatDateRange(
                                    campaign.start_date,
                                    campaign.end_date,
                                )}
                            </span>
                            <span className="text-foreground/80 text-[13px] font-medium">
                                {campaign.timeline.sentence}
                            </span>
                            {campaign.owner && (
                                <span className="text-muted-foreground inline-flex items-center gap-1 text-[13px]">
                                    <UserRound className="size-3.5" />
                                    {campaign.owner.name}
                                </span>
                            )}
                        </>
                    }
                    actions={
                        <>
                            {actions}
                            {primary && (
                                <Button
                                    disabled={busy}
                                    onClick={() =>
                                        transition(primary.to, primary.label)
                                    }
                                >
                                    {primary.label}
                                </Button>
                            )}
                            {(can.update || secondary.length > 0) && (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="outline"
                                            disabled={busy}
                                        >
                                            More
                                            <ChevronDown />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        className="w-52"
                                    >
                                        {secondary.map((move) => (
                                            <DropdownMenuItem
                                                key={move.to}
                                                onSelect={() =>
                                                    transition(
                                                        move.to,
                                                        move.label,
                                                    )
                                                }
                                            >
                                                {move.label}
                                            </DropdownMenuItem>
                                        ))}
                                        {can.transition &&
                                            campaign.start_date &&
                                            [
                                                'planning',
                                                'active',
                                                'paused',
                                            ].includes(campaign.status) && (
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setShiftOpen(true)
                                                    }
                                                >
                                                    <CalendarClock />
                                                    Shift dates…
                                                </DropdownMenuItem>
                                            )}
                                        {can.update && (
                                            <>
                                                {secondary.length > 0 && (
                                                    <DropdownMenuSeparator />
                                                )}
                                                <DropdownMenuItem asChild>
                                                    <Link
                                                        href={editCampaign(
                                                            campaign.id,
                                                        )}
                                                    >
                                                        <Pencil />
                                                        Edit details
                                                    </Link>
                                                </DropdownMenuItem>
                                                {campaign.start_date &&
                                                    can.saveTemplate && (
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                setTemplateOpen(
                                                                    true,
                                                                )
                                                            }
                                                        >
                                                            <LayoutTemplate />
                                                            Save as template…
                                                        </DropdownMenuItem>
                                                    )}
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setShareOpen(true)
                                                    }
                                                >
                                                    <Link2 />
                                                    Share a preview…
                                                </DropdownMenuItem>
                                            </>
                                        )}
                                        {can.delete && (
                                            <DropdownMenuItem
                                                className="text-destructive focus:text-destructive"
                                                onSelect={handleDelete}
                                            >
                                                <Trash2 />
                                                Delete campaign
                                            </DropdownMenuItem>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                        </>
                    }
                />

                <nav
                    aria-label="Campaign sections"
                    className="bg-muted -mt-2 flex max-w-full gap-1 self-start overflow-x-auto rounded-full p-1 whitespace-nowrap"
                >
                    {visibleTabs.map((definition) => {
                        const active = definition.key === tab;
                        const count = definition.count?.(counts) ?? null;

                        return (
                            <Link
                                key={definition.key}
                                href={definition.href(campaign.id)}
                                aria-current={active ? 'page' : undefined}
                                preserveScroll
                                className={cn(
                                    'focus-visible:ring-ring/50 inline-flex h-9 items-center gap-2 rounded-full px-3.5 text-[13px] font-medium transition-colors outline-none focus-visible:ring-[3px]',
                                    active
                                        ? 'bg-card text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <definition.icon className="size-4" />
                                {definition.label}
                                {count !== null && (
                                    <span
                                        className={cn(
                                            'rounded-full px-1.5 font-mono text-[10px] tabular-nums',
                                            active
                                                ? 'bg-muted text-foreground/80'
                                                : 'bg-card/70',
                                        )}
                                    >
                                        {count}
                                    </span>
                                )}
                            </Link>
                        );
                    })}
                </nav>

                <div className="animate-rise flex flex-col gap-8">
                    {children}
                </div>
            </Page>
            {can.update && (
                <ShareLinksDialog
                    campaignId={campaign.id}
                    links={shareLinks}
                    open={shareOpen}
                    onOpenChange={setShareOpen}
                />
            )}
            {can.transition && (
                <>
                    <ResumeDialog
                        campaignId={campaign.id}
                        open={resumeOpen}
                        onOpenChange={setResumeOpen}
                    />
                    <ShiftDialog
                        campaignId={campaign.id}
                        open={shiftOpen}
                        onOpenChange={setShiftOpen}
                    />
                    <SaveTemplateDialog
                        campaignId={campaign.id}
                        defaultName={campaign.name}
                        open={templateOpen}
                        onOpenChange={setTemplateOpen}
                    />
                </>
            )}
        </>
    );
}
