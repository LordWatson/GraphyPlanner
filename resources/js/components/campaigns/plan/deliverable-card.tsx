import { Link, router } from '@inertiajs/react';
import { AlarmClock, ArrowUpRight, PackageCheck, Play } from 'lucide-react';
import { useState } from 'react';
import type { PlanAccount } from '@/components/campaigns/plan/types';
import type { Deliverable } from '@/components/campaigns/types';
import { platformIcon } from '@/components/platform-icon';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDate, formatSchedule } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { start as startDeliverable } from '@/routes/campaigns/deliverables';
import { edit as editPost } from '@/routes/posts';

/**
 * "Start post" (Step C-3.2): one click when the client has one account on the platform, a choice
 * when they have several. Lands in the post editor.
 */
export function StartPostButton({
    campaignId,
    deliverable,
    accounts,
    size = 'sm',
}: {
    campaignId: number;
    deliverable: Deliverable;
    accounts: PlanAccount[];
    size?: 'sm' | 'default';
}) {
    const [busy, setBusy] = useState(false);
    const options = accounts.filter(
        (account) => account.platform === deliverable.platform,
    );
    const start = (socialAccountId?: number) =>
        router.post(
            startDeliverable.url({
                campaign: campaignId,
                deliverable: deliverable.id,
            }),
            socialAccountId ? { social_account_id: socialAccountId } : {},
            { onStart: () => setBusy(true), onFinish: () => setBusy(false) },
        );

    if (options.length > 1) {
        return (
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button size={size} variant="secondary" disabled={busy}>
                        <Play />
                        Start post
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuLabel>Post from</DropdownMenuLabel>
                    {options.map((account) => (
                        <DropdownMenuItem
                            key={account.id}
                            onSelect={() => start(account.id)}
                        >
                            {account.handle}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
        );
    }

    return (
        <Button
            size={size}
            variant="secondary"
            disabled={busy}
            onClick={() => start(options[0]?.id)}
        >
            <Play />
            Start post
        </Button>
    );
}

/**
 * A planned deliverable on the Plan matrix: platform glyph, working title, slot, derived state,
 * assignee, due date (red when overdue) and unmet asset requirements. Clicking opens the dialog.
 */
export function DeliverableCard({
    campaignId,
    deliverable,
    accounts,
    canProduce,
    onOpen,
}: {
    campaignId: number;
    deliverable: Deliverable;
    accounts: PlanAccount[];
    canProduce: boolean;
    onOpen: () => void;
}) {
    const Icon = platformIcon(deliverable.platform);
    const unmet = deliverable.asset_requirements.filter(
        (requirement) => !requirement.met,
    ).length;
    const total = deliverable.asset_requirements.length;

    return (
        <article
            id={`deliverable-${deliverable.id}`}
            className="border-border bg-card hover:border-primary/40 group target:ring-primary flex scroll-mt-24 flex-col gap-2.5 rounded-xl border p-3 shadow-sm transition-colors target:ring-2"
        >
            <button
                type="button"
                onClick={onOpen}
                className="focus-visible:ring-ring/50 flex flex-col gap-1.5 rounded-md text-left outline-none focus-visible:ring-[3px]"
            >
                <span className="flex items-start gap-2">
                    <Icon
                        className="text-muted-foreground mt-0.5 size-4 shrink-0"
                        aria-label={deliverable.platform}
                    />
                    <span className="group-hover:text-primary line-clamp-2 text-sm font-medium">
                        {deliverable.title}
                    </span>
                </span>
                <span className="text-muted-foreground font-mono text-[11px] tabular-nums">
                    {formatSchedule(
                        deliverable.planned_for,
                        deliverable.planned_time,
                        'No date',
                    )}
                    {deliverable.format ? ` · ${deliverable.format}` : ''}
                </span>
            </button>

            <div className="flex flex-wrap items-center gap-1.5">
                <StatusPill
                    kind="deliverable"
                    value={deliverable.state}
                    label={deliverable.state_label}
                />
                {total > 0 && (
                    <span
                        className={cn(
                            'inline-flex items-center gap-1 text-[11px]',
                            unmet > 0 ? 'text-warning' : 'text-success',
                        )}
                        title={
                            unmet > 0
                                ? `${unmet} of ${total} asset requirements unmet`
                                : 'Assets ready'
                        }
                    >
                        <PackageCheck className="size-3.5" />
                        {total - unmet}/{total}
                    </span>
                )}
            </div>

            <div className="flex items-center justify-between gap-2">
                <span
                    className={cn(
                        'flex min-w-0 items-center gap-1 text-[11px]',
                        deliverable.is_overdue
                            ? 'text-destructive font-medium'
                            : 'text-muted-foreground',
                    )}
                >
                    {deliverable.is_overdue && (
                        <AlarmClock className="size-3.5 shrink-0" />
                    )}
                    <span className="truncate">
                        {deliverable.assignee?.name ?? 'Unassigned'}
                        {deliverable.due_on
                            ? ` · due ${formatDate(deliverable.due_on)}`
                            : ''}
                    </span>
                </span>
                {deliverable.post_id ? (
                    <Button
                        asChild
                        size="sm"
                        variant="ghost"
                        className="h-7 px-2"
                    >
                        <Link href={editPost(deliverable.post_id)}>
                            Post
                            <ArrowUpRight />
                        </Link>
                    </Button>
                ) : (
                    canProduce && (
                        <StartPostButton
                            campaignId={campaignId}
                            deliverable={deliverable}
                            accounts={accounts}
                        />
                    )
                )}
            </div>
        </article>
    );
}
