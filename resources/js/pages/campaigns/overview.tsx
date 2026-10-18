import { Deferred, Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    CalendarClock,
    Check,
    Circle,
    CircleAlert,
    FileText,
    HeartPulse,
    MessagesSquare,
    Receipt,
    Rocket,
    Wallet,
} from 'lucide-react';
import { confirmDialog } from '@/components/confirm-dialog';
import { CampaignConversation } from '@/components/campaigns/campaign-conversation';
import { CampaignLayout } from '@/components/campaigns/campaign-layout';
import { CampaignPostRow } from '@/components/campaigns/campaign-post-row';
import { ReadinessMeter } from '@/components/campaigns/health-pill';
import type {
    ActivityItem,
    CampaignComment,
    CampaignPost,
    CampaignWorkspaceProps,
} from '@/components/campaigns/types';
import type { MentionableUser } from '@/components/mention-textarea';
import { EmptyState, Panel, StatStrip } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { formatCurrency } from '@/lib/currency';
import { formatDate, formatDateTime } from '@/lib/dates';
import { toneTextClass } from '@/lib/status';
import { cn } from '@/lib/utils';
import {
    activity as activityTab,
    board as boardTab,
    brief as briefTab,
    index as campaignsIndex,
    invoice as invoiceRoute,
    plan as planTab,
} from '@/routes/campaigns';
import { store as storeComment } from '@/routes/campaigns/comments';
import { edit as editPost } from '@/routes/posts';

type OverviewProps = CampaignWorkspaceProps & {
    funnel: {
        planned: number;
        in_production: number;
        approved: number;
        scheduled: number;
        published: number;
    };
    upcoming: CampaignPost[];
    launchChecklist: {
        key: string;
        label: string;
        done: boolean;
        hint: string;
    }[];
    brief: {
        status: string;
        status_label: string;
        approved_at: string | null;
    } | null;
    comments?: CampaignComment[];
    recentActivity?: ActivityItem[];
    mentionables: MentionableUser[];
};

const pad = (value: number) => String(value).padStart(2, '0');

/** Step C-8.2 — bill the fee; if it's already on an invoice, ask before billing it again. */
function invoiceCampaign(campaignId: number, confirm = false) {
    router.post(
        invoiceRoute.url(campaignId),
        confirm ? { confirm: true } : {},
        {
            preserveScroll: true,
            onError: async (errors) => {
                if (
                    errors.confirm &&
                    (await confirmDialog({
                        title: 'Invoice it again?',
                        description: errors.confirm,
                        confirmLabel: 'Add to invoice',
                    }))
                ) {
                    invoiceCampaign(campaignId, true);
                }
            },
        },
    );
}

/**
 * Step C-1.2 — the campaign workspace's Overview: the production funnel as a spec strip, what goes
 * out next, health and readiness with every reason, the brief's sign-off, money (Owner/Strategist
 * only), the conversation with the client (C-5.3) and the latest activity.
 */
export default function CampaignOverview(props: OverviewProps) {
    const {
        campaign,
        can,
        funnel,
        upcoming,
        brief,
        comments,
        recentActivity,
        mentionables,
        launchChecklist,
    } = props;
    const showChecklist =
        ['planning', 'active'].includes(campaign.status) &&
        campaign.timeline.state !== 'ended';
    const checklistDone = launchChecklist.filter((item) => item.done).length;
    const { currency } = usePage<{ currency: string }>().props;
    const findings = campaign.health.findings;
    const blockers = campaign.readiness.blockers;

    return (
        <CampaignLayout {...props}>
            <StatStrip
                items={[
                    {
                        label: 'Planned',
                        value: pad(funnel.planned),
                        caption: 'Deliverables in the plan',
                        render: (cell) => (
                            <Link href={planTab(campaign.id)}>{cell}</Link>
                        ),
                    },
                    {
                        label: 'In production',
                        value: pad(funnel.in_production),
                        caption: 'Ideas, drafts, reviews',
                        render: (cell) => (
                            <Link href={boardTab(campaign.id)}>{cell}</Link>
                        ),
                    },
                    {
                        label: 'Approved',
                        value: pad(funnel.approved),
                        caption: 'Signed off',
                    },
                    {
                        label: 'Scheduled',
                        value: pad(funnel.scheduled),
                        caption: 'Queued to go out',
                    },
                    {
                        label: 'Published',
                        value: pad(funnel.published),
                        caption: 'Live so far',
                    },
                ]}
            />

            <div className="grid gap-5 lg:grid-cols-[1.45fr_1fr]">
                <div className="flex min-w-0 flex-col gap-5">
                    <Panel
                        icon={HeartPulse}
                        eyebrow="Health & readiness"
                        title={campaign.health.reason}
                    >
                        <div className="flex flex-col gap-5">
                            <div className="flex flex-col gap-2">
                                <div className="flex items-baseline justify-between gap-3">
                                    <span className="eyebrow text-muted-foreground">
                                        Readiness
                                    </span>
                                    <span className="text-sm font-semibold tabular-nums">
                                        {campaign.readiness.label}
                                    </span>
                                </div>
                                <ReadinessMeter
                                    readiness={campaign.readiness}
                                    showLabel={false}
                                />
                            </div>

                            {findings.length === 0 && blockers.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    Nothing in the way. Keep it moving.
                                </p>
                            ) : (
                                <ul className="divide-border/70 flex flex-col divide-y">
                                    {findings.map((finding) => (
                                        <li
                                            key={finding.code}
                                            className="flex items-start gap-3 py-2.5 first:pt-0"
                                        >
                                            <CircleAlert
                                                className={cn(
                                                    'mt-0.5 size-4 shrink-0',
                                                    toneTextClass[
                                                        finding.level === 'red'
                                                            ? 'danger'
                                                            : 'warning'
                                                    ],
                                                )}
                                            />
                                            <span className="text-sm">
                                                {finding.reason}
                                            </span>
                                        </li>
                                    ))}
                                    {blockers.map((blocker) => (
                                        <li
                                            key={`b-${blocker.code}`}
                                            className="text-muted-foreground flex items-start gap-3 py-2.5 first:pt-0"
                                        >
                                            <span
                                                aria-hidden
                                                className="bg-muted-foreground/50 mt-2 size-1.5 shrink-0 rounded-full"
                                            />
                                            <span className="text-sm">
                                                {blocker.reason}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </Panel>

                    <Panel
                        icon={CalendarClock}
                        eyebrow="Next out"
                        title="Going out soon"
                        bodyClassName="p-0 sm:p-0"
                        actions={
                            <Button asChild variant="ghost" size="sm">
                                <Link href={boardTab(campaign.id)}>
                                    Open board
                                </Link>
                            </Button>
                        }
                    >
                        {upcoming.length === 0 ? (
                            <div className="p-5 sm:p-6">
                                <EmptyState
                                    compact
                                    icon={CalendarClock}
                                    title="Nothing queued yet."
                                    description="Approved and scheduled posts line up here, soonest first."
                                />
                            </div>
                        ) : (
                            <ul className="divide-border/70 divide-y">
                                {upcoming.map((post, index) => (
                                    <CampaignPostRow
                                        key={post.id}
                                        post={post}
                                        index={index}
                                        href={editPost(post.id)}
                                    />
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel
                        icon={MessagesSquare}
                        eyebrow="Conversation"
                        title="Talk to the client"
                    >
                        <Deferred
                            data="comments"
                            fallback={<Skeleton className="h-32 rounded-xl" />}
                        >
                            <CampaignConversation
                                comments={comments ?? []}
                                form={storeComment.form(campaign.id)}
                                mentionables={mentionables}
                                allowInternal
                                emptyHint="Feedback that isn’t about one post lives here: timings, priorities, the big picture."
                            />
                        </Deferred>
                    </Panel>
                </div>

                <div className="flex min-w-0 flex-col gap-5">
                    {showChecklist && (
                        <Panel
                            icon={Rocket}
                            eyebrow={`Launch checklist · ${checklistDone}/${launchChecklist.length}`}
                            title={
                                checklistDone === launchChecklist.length
                                    ? 'Ready for launch.'
                                    : 'Before it goes live'
                            }
                        >
                            <ul className="flex flex-col gap-3">
                                {launchChecklist.map((item) => (
                                    <li
                                        key={item.key}
                                        className="flex items-start gap-2.5"
                                    >
                                        {item.done ? (
                                            <span className="bg-success/15 text-success mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full">
                                                <Check className="size-3" />
                                                <span className="sr-only">
                                                    Done:
                                                </span>
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground mt-0.5 flex size-5 shrink-0 items-center justify-center">
                                                <Circle className="size-4" />
                                                <span className="sr-only">
                                                    To do:
                                                </span>
                                            </span>
                                        )}
                                        <span className="flex flex-col">
                                            <span
                                                className={cn(
                                                    'text-sm',
                                                    item.done
                                                        ? 'text-foreground'
                                                        : 'text-foreground/85',
                                                )}
                                            >
                                                {item.label}
                                            </span>
                                            {!item.done && (
                                                <span className="text-muted-foreground text-xs">
                                                    {item.hint}
                                                </span>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    )}

                    <Panel
                        icon={FileText}
                        eyebrow="Brief"
                        title={brief ? brief.status_label : 'No brief yet'}
                        actions={
                            <Button asChild variant="ghost" size="sm">
                                <Link href={briefTab(campaign.id)}>
                                    {brief
                                        ? 'Open'
                                        : can.update
                                          ? 'Write it'
                                          : 'View'}
                                </Link>
                            </Button>
                        }
                    >
                        {brief ? (
                            <div className="flex flex-col gap-2">
                                <StatusPill
                                    kind="brief"
                                    value={brief.status}
                                    label={brief.status_label}
                                />
                                <p className="text-muted-foreground text-sm">
                                    {brief.approved_at
                                        ? `Signed off ${formatDate(brief.approved_at)}.`
                                        : brief.status === 'sent'
                                          ? 'With the client for sign-off.'
                                          : 'Not signed off yet. Send it when it’s ready.'}
                                </p>
                            </div>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                A brief the client signs off keeps everyone
                                pointing the same way.
                            </p>
                        )}
                    </Panel>

                    {can.viewBilling && campaign.billing && (
                        <Panel
                            icon={Wallet}
                            eyebrow="Commercials"
                            title={
                                campaign.billing.billing_type_label ?? 'Not set'
                            }
                        >
                            {campaign.billing.fee_amount !== null ? (
                                <div className="flex flex-col gap-3">
                                    <p className="font-display text-3xl font-bold tabular-nums">
                                        {formatCurrency(
                                            campaign.billing.fee_amount,
                                            currency,
                                        )}
                                    </p>
                                    <dl className="divide-border/70 flex flex-col divide-y text-sm">
                                        {[
                                            [
                                                'Invoiced',
                                                formatCurrency(
                                                    campaign.billing
                                                        .invoiced_amount,
                                                    currency,
                                                ),
                                            ],
                                            [
                                                'Paid',
                                                formatCurrency(
                                                    campaign.billing
                                                        .paid_amount,
                                                    currency,
                                                ),
                                            ],
                                            ...(campaign.billing
                                                .ai_cost_estimate === null
                                                ? []
                                                : [
                                                      [
                                                          'AI spend (est.)',
                                                          formatCurrency(
                                                              campaign.billing
                                                                  .ai_cost_estimate,
                                                              'USD',
                                                          ),
                                                      ],
                                                  ]),
                                        ].map(([label, value]) => (
                                            <div
                                                key={label}
                                                className="flex items-baseline justify-between gap-3 py-2"
                                            >
                                                <dt className="text-muted-foreground">
                                                    {label}
                                                </dt>
                                                <dd className="font-medium tabular-nums">
                                                    {value}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                    {campaign.billing.is_billable &&
                                        can.invoice && (
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    invoiceCampaign(campaign.id)
                                                }
                                                className="self-start"
                                            >
                                                <Receipt />
                                                Invoice this campaign
                                            </Button>
                                        )}
                                </div>
                            ) : (
                                <p className="text-muted-foreground text-sm">
                                    No fee recorded. Set one in the campaign’s
                                    details.
                                </p>
                            )}
                        </Panel>
                    )}

                    <Panel
                        icon={Activity}
                        eyebrow="Activity"
                        title="Latest"
                        actions={
                            <Button asChild variant="ghost" size="sm">
                                <Link href={activityTab(campaign.id)}>
                                    All activity
                                </Link>
                            </Button>
                        }
                    >
                        <Deferred
                            data="recentActivity"
                            fallback={<Skeleton className="h-40 rounded-xl" />}
                        >
                            {(recentActivity ?? []).length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    Nothing yet.
                                </p>
                            ) : (
                                <ol className="flex flex-col gap-3">
                                    {(recentActivity ?? []).map((item) => (
                                        <li
                                            key={item.key}
                                            className="flex flex-col gap-0.5"
                                        >
                                            <span className="text-sm">
                                                {item.summary}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                {item.user ?? 'System'} ·{' '}
                                                {formatDateTime(
                                                    item.created_at,
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </Deferred>
                    </Panel>
                </div>
            </div>
        </CampaignLayout>
    );
}

CampaignOverview.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};
