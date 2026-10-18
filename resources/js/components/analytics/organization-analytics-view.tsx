import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    Building2,
    Clock3,
    HeartPulse,
    Hourglass,
    Megaphone,
    Receipt,
    Send,
    Share2,
    Sparkles,
    UsersRound,
    Workflow,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { EmptyState, Panel, StatStrip } from '@/components/page';
import type { StatStripItem } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { formatCurrency, formatCurrencyTotals } from '@/lib/currency';
import { formatDateTime } from '@/lib/dates';
import { resolveStatus, toneBgClass } from '@/lib/status';
import { cn } from '@/lib/utils';
import {
    analytics as clientAnalytics,
    show as showClient,
} from '@/routes/clients';
import { edit as editPost } from '@/routes/posts';
import { FigureList, MoneyRow, ShareBars } from './figures';
import {
    formatHours,
    formatPercent,
    integer,
    pad,
    primaryTotal,
} from './format';
import {
    AmountAreaChart,
    PairedBarChart,
    PublishingChart,
} from './series-charts';
import type { CampaignRow, OrgSummary, TopClientRow } from './types';

/**
 * Step 17.4 — renders a `GetOrganizationAnalyticsSummaryAction` result: the numeral spec row, then
 * one panel per visible section. Shared by the studio's analytics page, the client drill-down, a
 * saved report's run view and the super admin's per-org drill-down, so all four read identically.
 *
 * Sections the viewer can't see arrive as `null` and are simply not drawn. Data screen: hairlines,
 * no patterns or peeks; failures use the destructive token + a label, never Signal Orange; status
 * is always `StatusPill` (colour + label). "Right now" figures say so, the rest follow the range.
 */
export function OrganizationAnalyticsView({
    summary,
    links = true,
}: {
    summary: OrgSummary;
    /** Off on the super admin console, which can't open studio pages. */
    links?: boolean;
}) {
    const currency = summary.primary_currency;
    const daily = summary.filters.granularity === 'day';
    const per = daily ? 'Per day' : 'Per month';
    const { clients, posts, approvals, team, revenue, ai } = summary;
    const series = summary.series;
    const nothingVisible = summary.metrics.length === 0;

    const strip: StatStripItem[] = [];

    if (posts) {
        strip.push({
            label: 'Published',
            value: pad(posts.published),
            caption:
                posts.success_rate === null
                    ? 'No publish attempts yet'
                    : `${formatPercent(posts.success_rate)} success · ${posts.failed} failed`,
        });
    }

    if (approvals) {
        strip.push({
            label: 'Client turnaround',
            value: formatHours(approvals.client_turnaround.median_hours),
            caption:
                approvals.client_turnaround.decisions === 0
                    ? 'No client decisions yet'
                    : `median · ${approvals.client_turnaround.decisions} decisions`,
        });
    }

    if (clients && summary.scope === 'organization') {
        strip.push({
            label: 'Active clients',
            value: pad(clients.active),
            caption:
                clients.health.red > 0
                    ? `${clients.health.red} red right now`
                    : `of ${integer.format(clients.total)} · +${clients.new} new`,
        });
    }

    if (approvals && summary.scope === 'client') {
        strip.push({
            label: 'Waiting on client',
            value: pad(approvals.waiting_now),
            caption: 'right now',
        });
    }

    if (revenue) {
        const { headline, others } = primaryTotal(revenue.invoiced, currency);

        strip.push({
            label: `Invoiced · ${currency}`,
            value: headline,
            caption: others.length
                ? `+ ${formatCurrencyTotals(others, currency, { compact: true })}`
                : `${revenue.invoices_issued} invoices issued`,
        });
    } else if (ai) {
        strip.push({
            label: 'AI drafts',
            value: pad(ai.generated),
            caption: `${ai.failed} failed attempts`,
        });
    }

    if (nothingVisible) {
        return (
            <EmptyState
                icon={Workflow}
                title="Nothing to show here."
                description="Pick at least one section you can see to build the view."
            />
        );
    }

    const hasActivity = series.some(
        (bucket) => bucket.published > 0 || bucket.failed > 0,
    );
    const hasApprovals = series.some(
        (bucket) => bucket.sent_for_approval > 0 || bucket.approved > 0,
    );
    const hasInvoiced = series.some((bucket) => (bucket.invoiced ?? 0) > 0);

    return (
        <div className="flex flex-col gap-8">
            {strip.length > 0 && (
                <StatStrip
                    className="animate-rise [animation-delay:80ms]"
                    items={strip}
                />
            )}

            {posts && (
                <div className="grid gap-5 lg:grid-cols-[1.45fr_1fr]">
                    <Panel eyebrow={per} title="Publishing" icon={Send}>
                        {hasActivity ? (
                            <PublishingChart series={series} daily={daily} />
                        ) : (
                            <EmptyState
                                compact
                                className="border-0"
                                title="Nothing to chart yet."
                                description="Publish your first post and we'll start counting."
                            />
                        )}
                        <FigureList
                            className="border-border/70 mt-5 border-t pt-4"
                            rows={[
                                [
                                    'Posts created in range',
                                    integer.format(posts.created),
                                ],
                                [
                                    'Scheduled right now',
                                    integer.format(posts.scheduled),
                                ],
                            ]}
                        />
                    </Panel>

                    <Panel
                        eyebrow="Right now"
                        title="Pipeline"
                        icon={Workflow}
                        description="Every post in scope, by where it sits today."
                    >
                        <ShareBars
                            emptyLabel="No posts yet."
                            rows={posts.pipeline
                                .filter((row) => row.count > 0)
                                .map((row) => ({
                                    key: row.status,
                                    label: (
                                        <StatusPill
                                            kind="post"
                                            value={row.status}
                                            label={row.label}
                                        />
                                    ),
                                    value: row.count,
                                    barClassName:
                                        toneBgClass[
                                            resolveStatus('post', row.status)
                                                .tone
                                        ],
                                }))}
                        />
                    </Panel>
                </div>
            )}

            {posts && (
                <div
                    className={cn(
                        'grid gap-5',
                        summary.scope === 'organization' &&
                            'lg:grid-cols-[1fr_1.45fr]',
                    )}
                >
                    <Panel
                        eyebrow="Published in range"
                        title="By platform"
                        icon={Share2}
                    >
                        <ShareBars
                            emptyLabel="No accounts on these platforms yet."
                            rows={posts.by_platform.map((row) => ({
                                key: row.platform,
                                label: row.label,
                                value: row.published,
                            }))}
                        />
                    </Panel>
                    {/* A client's drill-down lists its campaigns in full further down. */}
                    {summary.scope === 'organization' && (
                        <Panel
                            eyebrow="By posts published in range"
                            title="Top campaigns"
                            icon={Megaphone}
                            bodyClassName="p-0 sm:p-0"
                        >
                            {posts.top_campaigns.length === 0 ? (
                                <div className="p-5 sm:p-6">
                                    <EmptyState
                                        compact
                                        icon={Megaphone}
                                        title="No campaign posts out yet."
                                        description="Posts published under a campaign rank here."
                                    />
                                </div>
                            ) : (
                                <CampaignTable
                                    campaigns={posts.top_campaigns}
                                    showClient
                                />
                            )}
                        </Panel>
                    )}
                </div>
            )}

            {approvals && (
                <Panel
                    eyebrow={`${per} · turnaround in hours`}
                    title="Approvals"
                    icon={Clock3}
                    description="From a post going to review to the next decision. Waiting figures are right now."
                >
                    <div className="grid gap-6 lg:grid-cols-[1.45fr_1fr]">
                        {hasApprovals ? (
                            <PairedBarChart
                                series={series}
                                daily={daily}
                                first={{
                                    key: 'sent_for_approval',
                                    name: 'Sent to client',
                                }}
                                second={{ key: 'approved', name: 'Approved' }}
                            />
                        ) : (
                            <EmptyState
                                compact
                                className="border-0"
                                title="No approvals in this range."
                                description="Send a post to a client and their decisions will chart here."
                            />
                        )}
                        <div className="flex flex-col gap-5">
                            <FigureList
                                rows={[
                                    [
                                        'Sent to client',
                                        integer.format(
                                            approvals.sent_to_client,
                                        ),
                                    ],
                                    [
                                        'Approved',
                                        integer.format(approvals.approved),
                                    ],
                                    [
                                        'Changes requested',
                                        integer.format(
                                            approvals.changes_requested,
                                        ),
                                    ],
                                    [
                                        'Approved first time',
                                        formatPercent(
                                            approvals.first_time_approval_rate,
                                        ),
                                    ],
                                    [
                                        'Client decision · median / p90',
                                        `${formatHours(approvals.client_turnaround.median_hours)} / ${formatHours(approvals.client_turnaround.p90_hours)}`,
                                    ],
                                    [
                                        'Internal review · median / p90',
                                        `${formatHours(approvals.internal_turnaround.median_hours)} / ${formatHours(approvals.internal_turnaround.p90_hours)}`,
                                    ],
                                ]}
                            />
                            <WaitingCallout
                                waiting={approvals.waiting_now}
                                oldest={approvals.oldest_waiting}
                                links={links}
                            />
                        </div>
                    </div>
                </Panel>
            )}

            {(clients || team) && (
                <div
                    className={cn(
                        'grid gap-5',
                        clients && team && 'lg:grid-cols-[1fr_1.45fr]',
                    )}
                >
                    {clients && (
                        <Panel
                            eyebrow="Right now"
                            title={
                                summary.scope === 'client'
                                    ? 'Client health'
                                    : 'How clients are tracking'
                            }
                            icon={HeartPulse}
                        >
                            {summary.scope === 'client' && summary.client ? (
                                <div className="flex flex-col gap-3">
                                    <StatusPill
                                        kind="health"
                                        value={summary.client.health}
                                        className="self-start"
                                    />
                                    <p className="text-[15px] text-pretty">
                                        {summary.client.reason}
                                    </p>
                                </div>
                            ) : (
                                <ClientHealth clients={clients} links={links} />
                            )}
                        </Panel>
                    )}
                    {team && <TeamPanel team={team} />}
                </div>
            )}

            {revenue && (
                <Panel
                    eyebrow={`${per} · chart in ${currency}`}
                    title="Billing"
                    icon={Receipt}
                    description="Sent and paid invoices only. Currencies are never added together."
                >
                    <div className="grid gap-6 lg:grid-cols-[1.45fr_1fr]">
                        <div className="flex flex-col gap-5">
                            {hasInvoiced ? (
                                <AmountAreaChart
                                    series={series}
                                    daily={daily}
                                    dataKey="invoiced"
                                    name="Invoiced"
                                    currency={currency}
                                    gradientId="studioInvoiced"
                                />
                            ) : (
                                <EmptyState
                                    compact
                                    className="border-0"
                                    title="No invoices yet."
                                    description={`Invoices issued in ${currency} in this range will chart here.`}
                                />
                            )}
                            <FigureList
                                className="border-border/70 border-t pt-4"
                                rows={[
                                    [
                                        'Invoices issued',
                                        integer.format(revenue.invoices_issued),
                                    ],
                                    [
                                        'Invoices paid',
                                        integer.format(revenue.invoices_paid),
                                    ],
                                    [
                                        'Average days to pay',
                                        revenue.average_days_to_pay === null
                                            ? '—'
                                            : `${revenue.average_days_to_pay} days`,
                                    ],
                                ]}
                            />
                        </div>

                        <dl className="divide-border/70 flex flex-col divide-y">
                            <MoneyRow
                                label="Invoiced"
                                totals={revenue.invoiced}
                                currency={currency}
                            />
                            <MoneyRow
                                label="Collected"
                                totals={revenue.collected}
                                currency={currency}
                            />
                            <MoneyRow
                                label="Outstanding now"
                                totals={revenue.outstanding}
                                currency={currency}
                            />
                            <div className="flex items-baseline justify-between gap-4 py-3">
                                <dt className="flex flex-col">
                                    <span className="eyebrow text-muted-foreground">
                                        Overdue now
                                    </span>
                                    <span className="text-muted-foreground text-xs">
                                        {revenue.overdue_count === 0
                                            ? 'Nothing past due'
                                            : `${revenue.overdue_count} past due date`}
                                    </span>
                                </dt>
                                <dd
                                    className={cn(
                                        'font-display text-right text-lg font-bold tabular-nums',
                                        revenue.overdue_count > 0 &&
                                            'text-destructive',
                                    )}
                                >
                                    {formatCurrencyTotals(
                                        revenue.overdue,
                                        currency,
                                    )}
                                </dd>
                            </div>
                            <MoneyRow
                                label="30-day run rate"
                                totals={revenue.monthly_run_rate}
                                currency={currency}
                                hint="Billed in the 30 days to the end of the range"
                            />
                            <MoneyRow
                                label="Active retainers · monthly"
                                totals={revenue.retainers.monthly}
                                currency={currency}
                                hint={`${revenue.retainers.clients} ${revenue.retainers.clients === 1 ? 'client' : 'clients'} on retainer`}
                            />
                        </dl>
                    </div>
                </Panel>
            )}

            {ai && (
                <Panel
                    eyebrow="Drafts and suggestions in range"
                    title="AI usage"
                    icon={Sparkles}
                    description="AI drafts; people choose, approve and publish."
                >
                    <div className="grid gap-6 lg:grid-cols-[1.45fr_1fr]">
                        <ShareBars
                            rows={ai.by_method.map((row) => ({
                                key: row.method,
                                label: row.label,
                                value: row.succeeded,
                                hint:
                                    row.failed > 0
                                        ? `${integer.format(row.succeeded)} · ${row.failed} failed`
                                        : undefined,
                            }))}
                        />
                        <FigureList
                            rows={[
                                ['Generated', integer.format(ai.generated)],
                                ['Failed attempts', integer.format(ai.failed)],
                                [
                                    'Images this month',
                                    ai.images_this_month.quota === null
                                        ? `${integer.format(ai.images_this_month.used)} · no limit`
                                        : `${integer.format(ai.images_this_month.used)} of ${integer.format(ai.images_this_month.quota)}`,
                                ],
                                ...(ai.cost_estimate !== null
                                    ? ([
                                          [
                                              'Estimated cost',
                                              formatCurrency(
                                                  ai.cost_estimate,
                                                  'USD',
                                              ),
                                          ],
                                      ] as [string, string][])
                                    : []),
                            ]}
                        />
                    </div>
                </Panel>
            )}

            {summary.top_clients && (
                <Panel
                    eyebrow="By posts published in range"
                    title="Busiest clients"
                    icon={Building2}
                    bodyClassName="p-0 sm:p-0"
                >
                    {summary.top_clients.length === 0 ? (
                        <div className="p-5 sm:p-6">
                            <EmptyState
                                compact
                                icon={Building2}
                                title="No clients yet."
                                description="Add your first client and their work will rank here."
                            />
                        </div>
                    ) : (
                        <TopClientsTable
                            clients={summary.top_clients}
                            currency={currency}
                            links={links}
                        />
                    )}
                </Panel>
            )}

            {summary.top_campaigns && (
                <Panel
                    eyebrow="By posts published in range"
                    title="Campaigns"
                    icon={Megaphone}
                    bodyClassName="p-0 sm:p-0"
                >
                    {summary.top_campaigns.length === 0 ? (
                        <div className="p-5 sm:p-6">
                            <EmptyState
                                compact
                                icon={Megaphone}
                                title="No campaigns yet."
                                description="Group this client's posts into a campaign to track it here."
                            />
                        </div>
                    ) : (
                        <CampaignTable
                            campaigns={summary.top_campaigns}
                            showClient={false}
                        />
                    )}
                </Panel>
            )}
        </div>
    );
}

function WaitingCallout({
    waiting,
    oldest,
    links,
}: {
    waiting: number;
    oldest: NonNullable<OrgSummary['approvals']>['oldest_waiting'];
    links: boolean;
}) {
    const body: ReactNode = oldest ? (
        <>
            <span className="text-muted-foreground text-xs">
                Longest wait · since {formatDateTime(oldest.since)}
            </span>
            <span className="text-sm font-medium">
                {oldest.client_name} —{' '}
                <span className="font-mono tabular-nums">
                    {formatHours(oldest.hours_waiting)}
                </span>
            </span>
            {oldest.caption && (
                <span className="text-muted-foreground line-clamp-1 text-xs">
                    “{oldest.caption}”
                </span>
            )}
        </>
    ) : (
        <span className="text-muted-foreground text-sm">
            {waiting === 0
                ? 'All clear — nothing is waiting on a client.'
                : 'Sent before we started logging send dates, so we can’t time the wait.'}
        </span>
    );

    return (
        <div className="bg-muted/50 flex flex-col gap-3 rounded-xl p-4">
            <div className="flex items-baseline justify-between gap-3">
                <span className="eyebrow text-muted-foreground flex items-center gap-2">
                    <Hourglass className="size-3.5" />
                    Waiting on clients now
                </span>
                <span className="font-display text-2xl font-bold tabular-nums">
                    {pad(waiting)}
                </span>
            </div>
            {oldest && links ? (
                <Link
                    href={editPost(oldest.post_id)}
                    className="group focus-visible:ring-ring/40 flex flex-col gap-1 rounded-lg outline-none focus-visible:ring-[3px]"
                >
                    {body}
                    <span className="text-primary inline-flex items-center gap-1 text-xs font-medium group-hover:underline">
                        Open the post
                        <ArrowUpRight className="size-3" />
                    </span>
                </Link>
            ) : (
                <div className="flex flex-col gap-1">{body}</div>
            )}
        </div>
    );
}

function ClientHealth({
    clients,
    links,
}: {
    clients: NonNullable<OrgSummary['clients']>;
    links: boolean;
}) {
    const total =
        clients.health.green + clients.health.amber + clients.health.red;

    if (total === 0) {
        return (
            <EmptyState
                compact
                className="border-0"
                icon={Building2}
                title="No clients yet."
                description="Add your first client and we'll track how they're doing."
            />
        );
    }

    return (
        <div className="flex flex-col gap-5">
            <div
                className="bg-muted flex h-2.5 overflow-hidden rounded-full"
                role="img"
                aria-label={`${clients.health.green} green, ${clients.health.amber} amber, ${clients.health.red} red`}
            >
                {(['green', 'amber', 'red'] as const).map((tone) =>
                    clients.health[tone] > 0 ? (
                        <div
                            key={tone}
                            className={
                                toneBgClass[resolveStatus('health', tone).tone]
                            }
                            style={{
                                width: `${(clients.health[tone] / total) * 100}%`,
                            }}
                        />
                    ) : null,
                )}
            </div>
            <div className="flex flex-wrap gap-2">
                {(['green', 'amber', 'red'] as const).map((tone) => (
                    <StatusPill
                        key={tone}
                        kind="health"
                        value={tone}
                        label={`${resolveStatus('health', tone).label} · ${clients.health[tone]}`}
                    />
                ))}
            </div>

            <FigureList
                rows={[
                    ['Clients', integer.format(clients.total)],
                    ['Active', integer.format(clients.active)],
                    ['Paused', integer.format(clients.paused)],
                    ['Offboarding', integer.format(clients.offboarding)],
                    ['Added in range', `+${integer.format(clients.new)}`],
                ]}
            />

            {clients.attention.length > 0 ? (
                <div className="flex flex-col gap-2">
                    <span className="eyebrow text-muted-foreground">
                        Needs a look
                    </span>
                    <ul className="divide-border/70 flex flex-col divide-y">
                        {clients.attention.map((client) => {
                            const row = (
                                <>
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="truncate text-sm font-medium">
                                            {client.name}
                                        </span>
                                        <StatusPill
                                            kind="health"
                                            value={client.health}
                                        />
                                    </div>
                                    <span className="text-muted-foreground text-xs text-pretty">
                                        {client.reason}
                                    </span>
                                </>
                            );

                            return (
                                <li key={client.id}>
                                    {links ? (
                                        <Link
                                            href={showClient(client.id)}
                                            className="hover:bg-muted/50 -mx-2 flex flex-col gap-1 rounded-lg px-2 py-2.5 transition-colors"
                                        >
                                            {row}
                                        </Link>
                                    ) : (
                                        <div className="flex flex-col gap-1 py-2.5">
                                            {row}
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ) : (
                <p className="text-muted-foreground text-sm">
                    All clear — every client is in good standing.
                </p>
            )}
        </div>
    );
}

function TeamPanel({ team }: { team: NonNullable<OrgSummary['team']> }) {
    return (
        <Panel
            eyebrow="Throughput in range · by name"
            title="Team workload"
            icon={UsersRound}
            description="Who's carrying what, so you can plan around it. Not a leaderboard."
            bodyClassName="p-0 sm:p-0"
        >
            {team.members.length === 0 ? (
                <div className="p-5 sm:p-6">
                    <EmptyState
                        compact
                        icon={UsersRound}
                        title="Just you so far."
                        description="Invite your team and their workload shows here."
                    />
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[560px] text-sm">
                        <thead>
                            <tr className="border-border/70 text-muted-foreground border-b text-left">
                                <th className="eyebrow px-5 py-3 font-normal sm:px-6">
                                    Person
                                </th>
                                <th className="eyebrow px-3 py-3 text-right font-normal">
                                    Created
                                </th>
                                <th className="eyebrow px-3 py-3 text-right font-normal">
                                    Moves
                                </th>
                                <th className="eyebrow px-3 py-3 text-right font-normal">
                                    To client
                                </th>
                                <th className="eyebrow px-5 py-3 text-right font-normal sm:px-6">
                                    Scheduled
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-border/70 divide-y">
                            {team.members.map((member) => (
                                <tr
                                    key={member.id}
                                    className="hover:bg-muted/50 transition-colors"
                                >
                                    <td className="px-5 py-3 sm:px-6">
                                        <div className="flex items-center gap-2">
                                            <span className="truncate font-medium">
                                                {member.name}
                                            </span>
                                            <StatusPill
                                                kind="role"
                                                value={member.role}
                                                label={member.role_label}
                                            />
                                        </div>
                                    </td>
                                    <td className="px-3 py-3 text-right font-mono tabular-nums">
                                        {integer.format(member.posts_created)}
                                    </td>
                                    <td className="px-3 py-3 text-right font-mono tabular-nums">
                                        {integer.format(member.transitions)}
                                    </td>
                                    <td className="px-3 py-3 text-right font-mono tabular-nums">
                                        {integer.format(member.sent_to_client)}
                                    </td>
                                    <td className="px-5 py-3 text-right font-mono tabular-nums sm:px-6">
                                        {integer.format(member.scheduled)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-foreground/85 text-muted-foreground border-t">
                                <td className="px-5 py-3 text-xs sm:px-6">
                                    Client decisions (review links and portal)
                                </td>
                                <td className="px-3 py-3 text-right font-mono">
                                    —
                                </td>
                                <td className="text-foreground px-3 py-3 text-right font-mono tabular-nums">
                                    {integer.format(team.client_decisions)}
                                </td>
                                <td className="px-3 py-3 text-right font-mono">
                                    —
                                </td>
                                <td className="px-5 py-3 text-right font-mono sm:px-6">
                                    —
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}
        </Panel>
    );
}

function TopClientsTable({
    clients,
    currency,
    links,
}: {
    clients: TopClientRow[];
    currency: string;
    links: boolean;
}) {
    const withInvoiced = clients.some(
        (client) => client.invoiced !== undefined,
    );

    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
                <thead>
                    <tr className="border-border/70 text-muted-foreground border-b text-left">
                        <th className="eyebrow px-5 py-3 font-normal sm:px-6">
                            Client
                        </th>
                        <th className="eyebrow px-3 py-3 font-normal">
                            Health
                        </th>
                        <th className="eyebrow px-3 py-3 text-right font-normal">
                            Published
                        </th>
                        <th className="eyebrow px-3 py-3 text-right font-normal">
                            Scheduled
                        </th>
                        <th
                            className={cn(
                                'eyebrow px-3 py-3 text-right font-normal',
                                !withInvoiced && 'px-5 sm:px-6',
                            )}
                        >
                            Waiting
                        </th>
                        {withInvoiced && (
                            <th className="eyebrow px-5 py-3 text-right font-normal sm:px-6">
                                Invoiced · {currency}
                            </th>
                        )}
                    </tr>
                </thead>
                <tbody className="divide-border/70 divide-y">
                    {clients.map((client, index) => (
                        <tr
                            key={client.id}
                            className="group hover:bg-muted/50 transition-colors"
                        >
                            <td className="px-5 py-3 sm:px-6">
                                {links ? (
                                    <Link
                                        href={clientAnalytics(client.id)}
                                        className="flex items-center gap-3 font-medium"
                                    >
                                        <Index value={index} />
                                        <span className="truncate group-hover:underline">
                                            {client.name}
                                        </span>
                                        <ArrowUpRight className="text-muted-foreground size-3.5 opacity-0 transition-opacity group-hover:opacity-100" />
                                    </Link>
                                ) : (
                                    <span className="flex items-center gap-3 font-medium">
                                        <Index value={index} />
                                        <span className="truncate">
                                            {client.name}
                                        </span>
                                    </span>
                                )}
                            </td>
                            <td className="px-3 py-3">
                                <StatusPill
                                    kind="health"
                                    value={client.health}
                                    title={client.health_reason}
                                />
                            </td>
                            <td className="px-3 py-3 text-right font-mono tabular-nums">
                                {integer.format(client.published)}
                            </td>
                            <td className="px-3 py-3 text-right font-mono tabular-nums">
                                {integer.format(client.scheduled)}
                            </td>
                            <td
                                className={cn(
                                    'px-3 py-3 text-right font-mono tabular-nums',
                                    !withInvoiced && 'px-5 sm:px-6',
                                )}
                            >
                                {integer.format(client.waiting_on_client)}
                            </td>
                            {withInvoiced && (
                                <td className="px-5 py-3 text-right font-mono tabular-nums sm:px-6">
                                    {formatCurrency(
                                        client.invoiced ?? 0,
                                        currency,
                                    )}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function CampaignTable({
    campaigns,
    showClient,
}: {
    campaigns: CampaignRow[];
    showClient: boolean;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[480px] text-sm">
                <thead>
                    <tr className="border-border/70 text-muted-foreground border-b text-left">
                        <th className="eyebrow px-5 py-3 font-normal sm:px-6">
                            Campaign
                        </th>
                        <th className="eyebrow px-3 py-3 text-right font-normal">
                            Published
                        </th>
                        <th className="eyebrow px-3 py-3 text-right font-normal">
                            Scheduled
                        </th>
                        <th className="eyebrow px-5 py-3 text-right font-normal sm:px-6">
                            Waiting
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-border/70 divide-y">
                    {campaigns.map((campaign, index) => (
                        <tr
                            key={campaign.id}
                            className="hover:bg-muted/50 transition-colors"
                        >
                            <td className="px-5 py-3 sm:px-6">
                                <div className="flex items-center gap-3">
                                    <Index value={index} />
                                    <span className="flex min-w-0 flex-col">
                                        <span className="truncate font-medium">
                                            {campaign.name}
                                        </span>
                                        {showClient && (
                                            <span className="text-muted-foreground truncate text-xs">
                                                {campaign.client_name}
                                            </span>
                                        )}
                                    </span>
                                </div>
                            </td>
                            <td className="px-3 py-3 text-right font-mono tabular-nums">
                                {integer.format(campaign.published)}
                            </td>
                            <td className="px-3 py-3 text-right font-mono tabular-nums">
                                {integer.format(campaign.scheduled)}
                            </td>
                            <td className="px-5 py-3 text-right font-mono tabular-nums sm:px-6">
                                {integer.format(campaign.waiting_on_client)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function Index({ value }: { value: number }) {
    return (
        <span className="text-muted-foreground w-5 shrink-0 font-mono text-[11px] tabular-nums">
            {String(value + 1).padStart(2, '0')}
        </span>
    );
}
