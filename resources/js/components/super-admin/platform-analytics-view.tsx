import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    Building2,
    Receipt,
    Send,
    Sparkles,
    UsersRound,
} from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { EmptyState, Panel, StatStrip } from '@/components/page';
import type { StatStripItem } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import type { CurrencyTotal } from '@/lib/currency';
import {
    formatCurrency,
    formatCurrencyCompact,
    formatCurrencyTotals,
} from '@/lib/currency';
import { show as showOrganization } from '@/routes/super-admin/organizations';
import type { MetricKey, PlatformSummary } from './types';

// Chart styling per brand guidelines §3.5 (mirrors the studio dashboard's charts).
const chartTooltipStyle = {
    borderRadius: '1rem',
    border: '1px solid var(--color-border)',
    background: 'var(--color-popover)',
    color: 'var(--color-popover-foreground)',
    fontSize: 12,
    padding: '8px 12px',
    boxShadow: 'var(--shadow-md)',
};

const axisTick = {
    fontSize: 11,
    fontFamily: 'var(--font-mono)',
    fill: 'var(--color-muted-foreground)',
};

const integer = new Intl.NumberFormat('en-GB');

function pad(value: number): string {
    return value < 10 ? String(value).padStart(2, '0') : integer.format(value);
}

/** The headline money total in the range's primary currency (others listed in the caption). */
function primaryTotal(
    totals: CurrencyTotal[],
    currency: string,
): { headline: string; others: CurrencyTotal[] } {
    const primary = totals.find((total) => total.currency === currency);

    return {
        headline: formatCurrencyCompact(primary?.total ?? 0, currency),
        others: totals.filter((total) => total.currency !== currency),
    };
}

/**
 * Step 15.4 — renders a `GetPlatformAnalyticsSummaryAction` result: the numeral spec row, then a
 * chart panel per selected section, the breakdowns, and the most active organizations. Shared by
 * the overview, the analytics page, the per-org drill-down and a saved report's run view, so they
 * always look identical. Sections the run didn't select are simply not drawn.
 */
export function PlatformAnalyticsView({
    summary,
    scope = 'platform',
}: {
    summary: PlatformSummary;
    /** `organization` = a single org's drill-down: no org-level KPIs or leaderboard. */
    scope?: 'platform' | 'organization';
}) {
    const has = (metric: MetricKey) => summary.metrics.includes(metric);
    const currency = summary.primary_currency;
    const daily = summary.filters.granularity === 'day';
    const { organizations, users, clients, posts, revenue, ai } = summary;

    const strip: StatStripItem[] = [];

    if (scope === 'platform' && organizations) {
        strip.push({
            label: 'Organizations',
            value: pad(organizations.total),
            caption: `+${organizations.new} new · ${organizations.suspended} suspended`,
        });
    }

    if (posts) {
        strip.push({
            label: 'Published',
            value: pad(posts.published),
            caption:
                posts.success_rate === null
                    ? 'No publish attempts yet'
                    : `${posts.success_rate}% success · ${posts.failed} failed`,
        });
    }

    if (clients) {
        strip.push({
            label: 'Active clients',
            value: pad(clients.active),
            caption: `of ${integer.format(clients.total)} · +${clients.new} new`,
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
    }

    if (scope === 'organization' && users) {
        strip.push({
            label: 'People',
            value: pad(users.total),
            caption: `+${users.new} joined in range`,
        });
    }

    const series = summary.series;
    const hasActivity = series.some(
        (bucket) => bucket.published > 0 || bucket.failed > 0,
    );
    const hasSignups = series.some((bucket) => bucket.signups > 0);
    const hasInvoiced = series.some((bucket) => bucket.invoiced > 0);
    const tickInterval = daily
        ? Math.max(0, Math.ceil(series.length / 8) - 1)
        : 0;

    return (
        <div className="flex flex-col gap-8">
            {strip.length > 0 && (
                <StatStrip className="animate-rise" items={strip} />
            )}

            <div className="grid gap-5 lg:grid-cols-[1.45fr_1fr]">
                {has('posts') && (
                    <Panel
                        eyebrow={daily ? 'Per day' : 'Per month'}
                        title="Publishing"
                        icon={Send}
                        className={
                            has('organizations') && scope === 'platform'
                                ? ''
                                : 'lg:col-span-2'
                        }
                    >
                        {hasActivity ? (
                            <div className="h-64 w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <LineChart
                                        data={series}
                                        margin={{
                                            top: 8,
                                            right: 8,
                                            left: 0,
                                            bottom: 0,
                                        }}
                                    >
                                        <CartesianGrid
                                            vertical={false}
                                            stroke="var(--color-border)"
                                            strokeDasharray="2 4"
                                        />
                                        <XAxis
                                            dataKey="label"
                                            tick={axisTick}
                                            tickLine={false}
                                            axisLine={false}
                                            interval={tickInterval}
                                            dy={6}
                                        />
                                        <YAxis
                                            tick={axisTick}
                                            tickLine={false}
                                            axisLine={false}
                                            allowDecimals={false}
                                            width={36}
                                        />
                                        <Tooltip
                                            contentStyle={chartTooltipStyle}
                                            cursor={{
                                                stroke: 'var(--color-foreground)',
                                                strokeOpacity: 0.2,
                                                strokeWidth: 1,
                                            }}
                                        />
                                        <Legend
                                            verticalAlign="top"
                                            align="right"
                                            height={28}
                                            iconType="circle"
                                            iconSize={8}
                                            wrapperStyle={{ fontSize: 12 }}
                                        />
                                        <Line
                                            type="monotone"
                                            dataKey="published"
                                            name="Published"
                                            stroke="var(--color-success)"
                                            strokeWidth={2}
                                            dot={false}
                                            activeDot={{
                                                r: 4,
                                                strokeWidth: 2,
                                                stroke: 'var(--color-card)',
                                            }}
                                        />
                                        <Line
                                            type="monotone"
                                            dataKey="failed"
                                            name="Failed"
                                            stroke="var(--color-destructive)"
                                            strokeWidth={2}
                                            strokeDasharray="5 4"
                                            dot={false}
                                            activeDot={{
                                                r: 4,
                                                strokeWidth: 2,
                                                stroke: 'var(--color-card)',
                                            }}
                                        />
                                    </LineChart>
                                </ResponsiveContainer>
                            </div>
                        ) : (
                            <EmptyState
                                compact
                                className="border-0"
                                title="Nothing went out."
                                description="Published and failed posts will chart here as they happen."
                            />
                        )}
                    </Panel>
                )}

                {scope === 'platform' && has('organizations') && (
                    <Panel
                        eyebrow={daily ? 'Per day' : 'Per month'}
                        title="New organizations"
                        icon={Building2}
                        className={has('posts') ? '' : 'lg:col-span-2'}
                    >
                        {hasSignups ? (
                            <div className="h-64 w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart
                                        data={series}
                                        margin={{
                                            top: 8,
                                            right: 8,
                                            left: 0,
                                            bottom: 0,
                                        }}
                                    >
                                        <CartesianGrid
                                            vertical={false}
                                            stroke="var(--color-border)"
                                            strokeDasharray="2 4"
                                        />
                                        <XAxis
                                            dataKey="label"
                                            tick={axisTick}
                                            tickLine={false}
                                            axisLine={false}
                                            interval={tickInterval}
                                            dy={6}
                                        />
                                        <YAxis
                                            tick={axisTick}
                                            tickLine={false}
                                            axisLine={false}
                                            allowDecimals={false}
                                            width={36}
                                        />
                                        <Tooltip
                                            contentStyle={chartTooltipStyle}
                                            cursor={{
                                                fill: 'var(--color-muted)',
                                                opacity: 0.5,
                                            }}
                                        />
                                        <Bar
                                            dataKey="signups"
                                            name="Signups"
                                            fill="var(--color-chart-1)"
                                            radius={[4, 4, 0, 0]}
                                            maxBarSize={28}
                                        />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        ) : (
                            <EmptyState
                                compact
                                className="border-0"
                                title="A quiet stretch."
                                description="No new organizations signed up in this range."
                            />
                        )}
                    </Panel>
                )}
            </div>

            {revenue && (
                <Panel
                    eyebrow={`Billed through Alima · chart in ${currency}`}
                    title="Billing"
                    icon={Receipt}
                    description="What organizations invoiced their own clients. Currencies are never added together."
                >
                    <div className="grid gap-6 lg:grid-cols-[1.45fr_1fr]">
                        {hasInvoiced ? (
                            <div className="h-60 w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <AreaChart
                                        data={series}
                                        margin={{
                                            top: 8,
                                            right: 8,
                                            left: 0,
                                            bottom: 0,
                                        }}
                                    >
                                        <defs>
                                            <linearGradient
                                                id="platformInvoiced"
                                                x1="0"
                                                y1="0"
                                                x2="0"
                                                y2="1"
                                            >
                                                <stop
                                                    offset="0%"
                                                    stopColor="var(--color-chart-1)"
                                                    stopOpacity={0.22}
                                                />
                                                <stop
                                                    offset="100%"
                                                    stopColor="var(--color-chart-1)"
                                                    stopOpacity={0}
                                                />
                                            </linearGradient>
                                        </defs>
                                        <CartesianGrid
                                            vertical={false}
                                            stroke="var(--color-border)"
                                            strokeDasharray="2 4"
                                        />
                                        <XAxis
                                            dataKey="label"
                                            tick={axisTick}
                                            tickLine={false}
                                            axisLine={false}
                                            interval={tickInterval}
                                            dy={6}
                                        />
                                        <YAxis
                                            tick={axisTick}
                                            tickLine={false}
                                            axisLine={false}
                                            width={56}
                                            tickFormatter={(amount: number) =>
                                                formatCurrencyCompact(
                                                    amount,
                                                    currency,
                                                )
                                            }
                                        />
                                        <Tooltip
                                            contentStyle={chartTooltipStyle}
                                            formatter={(amount) =>
                                                formatCurrency(
                                                    Number(amount),
                                                    currency,
                                                )
                                            }
                                        />
                                        <Area
                                            type="monotone"
                                            dataKey="invoiced"
                                            name="Invoiced"
                                            stroke="var(--color-chart-1)"
                                            strokeWidth={2}
                                            fill="url(#platformInvoiced)"
                                            activeDot={{
                                                r: 4,
                                                strokeWidth: 2,
                                                stroke: 'var(--color-card)',
                                            }}
                                        />
                                    </AreaChart>
                                </ResponsiveContainer>
                            </div>
                        ) : (
                            <EmptyState
                                compact
                                className="border-0"
                                title="No invoices yet."
                                description="Invoices issued in this range will chart here."
                            />
                        )}

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
                            <MoneyRow
                                label="30-day run rate"
                                totals={revenue.monthly_run_rate}
                                currency={currency}
                                hint="Billed in the 30 days to the end of the range"
                            />
                        </dl>
                    </div>
                </Panel>
            )}

            {(users || clients || ai) && (
                <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    {users && (
                        <Panel
                            eyebrow={`${integer.format(users.total)} people`}
                            title="People by role"
                            icon={UsersRound}
                        >
                            <RoleBars
                                roles={users.by_role}
                                total={users.total}
                            />
                        </Panel>
                    )}
                    {clients && (
                        <Panel
                            eyebrow="Roster"
                            title="Clients"
                            icon={Building2}
                        >
                            <FigureList
                                rows={[
                                    ['Total', integer.format(clients.total)],
                                    ['Active', integer.format(clients.active)],
                                    [
                                        'Added in range',
                                        `+${integer.format(clients.new)}`,
                                    ],
                                    ...(posts
                                        ? ([
                                              [
                                                  'Posts scheduled now',
                                                  integer.format(
                                                      posts.scheduled,
                                                  ),
                                              ],
                                              [
                                                  'Published, all time',
                                                  integer.format(
                                                      posts.published_all_time,
                                                  ),
                                              ],
                                          ] as [string, string][])
                                        : []),
                                ]}
                            />
                        </Panel>
                    )}
                    {ai && (
                        <Panel
                            eyebrow="Image Studio"
                            title="AI usage"
                            icon={Sparkles}
                        >
                            <FigureList
                                rows={[
                                    [
                                        'Images generated',
                                        integer.format(ai.generated),
                                    ],
                                    [
                                        'Failed attempts',
                                        integer.format(ai.failed),
                                    ],
                                    [
                                        'Estimated cost',
                                        formatCurrency(ai.cost_estimate, 'USD'),
                                    ],
                                ]}
                            />
                        </Panel>
                    )}
                </div>
            )}

            {scope === 'platform' && (
                <Panel
                    eyebrow="By posts published in range"
                    title="Most active organizations"
                    bodyClassName="p-0 sm:p-0"
                >
                    {summary.top_organizations.length === 0 ? (
                        <div className="p-5 sm:p-6">
                            <EmptyState
                                compact
                                icon={Building2}
                                title="No organizations yet."
                                description="Once organizations sign up, the busiest show here."
                            />
                        </div>
                    ) : (
                        <TopOrganizationsTable summary={summary} />
                    )}
                </Panel>
            )}
        </div>
    );
}

function MoneyRow({
    label,
    totals,
    currency,
    hint,
}: {
    label: string;
    totals: CurrencyTotal[];
    currency: string;
    hint?: string;
}) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-3 first:pt-0">
            <dt className="flex flex-col">
                <span className="eyebrow text-muted-foreground">{label}</span>
                {hint && (
                    <span className="text-muted-foreground text-xs">
                        {hint}
                    </span>
                )}
            </dt>
            <dd className="font-display text-right text-lg font-bold tabular-nums">
                {formatCurrencyTotals(totals, currency)}
            </dd>
        </div>
    );
}

function FigureList({ rows }: { rows: [string, ReactNode][] }) {
    return (
        <dl className="divide-border/70 flex flex-col divide-y">
            {rows.map(([label, value]) => (
                <div
                    key={label}
                    className="flex items-baseline justify-between gap-4 py-2.5 first:pt-0 last:pb-0"
                >
                    <dt className="text-muted-foreground text-sm">{label}</dt>
                    <dd className="font-mono text-sm tabular-nums">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

function RoleBars({
    roles,
    total,
}: {
    roles: { role: string; label: string; count: number }[];
    total: number;
}) {
    return (
        <ul className="flex flex-col gap-3">
            {roles.map((role) => {
                const share = total > 0 ? (role.count / total) * 100 : 0;

                return (
                    <li key={role.role} className="flex flex-col gap-1.5">
                        <div className="flex items-baseline justify-between gap-3 text-sm">
                            <span>{role.label}</span>
                            <span className="text-muted-foreground font-mono text-xs tabular-nums">
                                {integer.format(role.count)}
                            </span>
                        </div>
                        <div
                            className="bg-muted h-1.5 overflow-hidden rounded-full"
                            role="presentation"
                        >
                            <div
                                className="bg-chart-1 h-full rounded-full"
                                style={{ width: `${share}%` }}
                            />
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

function TopOrganizationsTable({ summary }: { summary: PlatformSummary }) {
    const currency = summary.primary_currency;

    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
                <thead>
                    <tr className="border-border/70 text-muted-foreground border-b text-left">
                        <th className="eyebrow px-5 py-3 font-normal sm:px-6">
                            Organization
                        </th>
                        <th className="eyebrow px-3 py-3 text-right font-normal">
                            Published
                        </th>
                        <th className="eyebrow px-3 py-3 text-right font-normal">
                            Clients
                        </th>
                        <th className="eyebrow px-3 py-3 text-right font-normal">
                            People
                        </th>
                        <th className="eyebrow px-5 py-3 text-right font-normal sm:px-6">
                            Invoiced · {currency}
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-border/70 divide-y">
                    {summary.top_organizations.map((organization, index) => (
                        <tr
                            key={organization.id}
                            className="group hover:bg-muted/50 transition-colors"
                        >
                            <td className="px-5 py-3 sm:px-6">
                                <Link
                                    href={showOrganization(organization.id)}
                                    className="flex items-center gap-3 font-medium"
                                >
                                    <span className="text-muted-foreground w-5 font-mono text-[11px] tabular-nums">
                                        {String(index + 1).padStart(2, '0')}
                                    </span>
                                    <span className="truncate group-hover:underline">
                                        {organization.name}
                                    </span>
                                    {organization.suspended && (
                                        <StatusPill
                                            kind="organization"
                                            value="suspended"
                                        />
                                    )}
                                    <ArrowUpRight className="text-muted-foreground size-3.5 opacity-0 transition-opacity group-hover:opacity-100" />
                                </Link>
                            </td>
                            <td className="px-3 py-3 text-right font-mono tabular-nums">
                                {integer.format(organization.published)}
                            </td>
                            <td className="px-3 py-3 text-right font-mono tabular-nums">
                                {integer.format(organization.clients)}
                            </td>
                            <td className="px-3 py-3 text-right font-mono tabular-nums">
                                {integer.format(organization.users)}
                            </td>
                            <td className="px-5 py-3 text-right font-mono tabular-nums sm:px-6">
                                {formatCurrency(
                                    organization.invoiced,
                                    currency,
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
