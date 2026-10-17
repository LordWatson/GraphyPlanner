import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    Building2,
    Receipt,
    Send,
    Sparkles,
    UsersRound,
} from 'lucide-react';
import {
    FigureList,
    MoneyRow,
    ShareBars,
} from '@/components/analytics/figures';
import { integer, pad, primaryTotal } from '@/components/analytics/format';
import {
    AmountAreaChart,
    CountBarChart,
    PublishingChart,
} from '@/components/analytics/series-charts';
import { EmptyState, Panel, StatStrip } from '@/components/page';
import type { StatStripItem } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { formatCurrency, formatCurrencyTotals } from '@/lib/currency';
import { show as showOrganization } from '@/routes/super-admin/organizations';
import type { MetricKey, PlatformSummary } from './types';

/**
 * Step 15.4 — renders a `GetPlatformAnalyticsSummaryAction` result: the numeral spec row, then a
 * chart panel per selected section, the breakdowns, and the most active organizations. Shared by
 * the overview, the analytics page and a saved report's run view, so they always look identical.
 * Sections the run didn't select are simply not drawn. Charts and figure rows come from the shared
 * `components/analytics` layer (Step 17.4), the same one the studio's own analytics uses.
 */
export function PlatformAnalyticsView({
    summary,
    scope = 'platform',
}: {
    summary: PlatformSummary;
    /** `organization` = a report scoped to one org: no org-level KPIs or leaderboard. */
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
                            <PublishingChart series={series} daily={daily} />
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
                            <CountBarChart
                                series={series}
                                daily={daily}
                                dataKey="signups"
                                name="Signups"
                            />
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
                            <AmountAreaChart
                                series={series}
                                daily={daily}
                                dataKey="invoiced"
                                name="Invoiced"
                                currency={currency}
                                gradientId="platformInvoiced"
                            />
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
                            <ShareBars
                                total={users.total}
                                rows={users.by_role.map((role) => ({
                                    key: role.role,
                                    label: role.label,
                                    value: role.count,
                                }))}
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
