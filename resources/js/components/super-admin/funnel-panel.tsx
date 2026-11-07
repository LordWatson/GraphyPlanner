import { TrendingUp } from 'lucide-react';

import { Panel } from '@/components/page';
import { formatCurrency } from '@/lib/currency';

export type FunnelMetrics = {
    conversion: { trials: number; paid: number; rate: number | null };
    time_to_first_post: {
        median_hours: number | null;
        published: number;
        total: number;
    };
    bio_pages: { published: number; total: number; rate: number | null };
    store_gmv: {
        orders: number;
        totals: { currency: string; amount_minor: number }[];
    };
    churn_by_plan: {
        plan: string;
        active: number;
        churned: number;
        rate: number | null;
    }[];
    agency_utilisation: {
        plan: string;
        organizations: number;
        brand_limit: number | null;
        seat_limit: number | null;
        brands_used: number;
        seats_used: number;
        brand_utilisation: number | null;
        seat_utilisation: number | null;
        at_brand_cap: number;
    }[];
};

/**
 * Step GTM-7.1 — the figures that say whether the product works as a business.
 *
 * Lifetime to date, not range-filtered: "how long does a new workspace take to publish" isn't a
 * question about last month. A null rate renders as an em dash, because a percentage with no
 * denominator is unanswerable rather than 0%.
 */
export function FunnelPanel({ funnel }: { funnel: FunnelMetrics }) {
    const percent = (value: number | null) =>
        value === null ? '—' : `${value}%`;

    const hours = (value: number | null) => {
        if (value === null) {
            return '—';
        }

        return value >= 48 ? `${Math.round(value / 24)} days` : `${value} h`;
    };

    return (
        <Panel
            eyebrow="Lifetime to date"
            title="The funnel"
            icon={TrendingUp}
            description="Whether the product works as a business, as opposed to whether the software runs. Store GMV is read from our own orders — creators are paid directly, so our Stripe balance has never seen that money."
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Figure
                    label="Trial → paid"
                    value={percent(funnel.conversion.rate)}
                    caption={`${funnel.conversion.paid} of ${funnel.conversion.trials} trials`}
                />
                <Figure
                    label="Time to first post"
                    value={hours(funnel.time_to_first_post.median_hours)}
                    caption={`median · ${funnel.time_to_first_post.published} of ${funnel.time_to_first_post.total} ever published`}
                />
                <Figure
                    label="Bio page live"
                    value={percent(funnel.bio_pages.rate)}
                    caption={`${funnel.bio_pages.published} of ${funnel.bio_pages.total} solo workspaces`}
                />
                <Figure
                    label="Store GMV"
                    value={
                        funnel.store_gmv.totals.length === 0
                            ? '—'
                            : funnel.store_gmv.totals
                                  .map((total) =>
                                      formatCurrency(
                                          total.amount_minor / 100,
                                          total.currency,
                                      ),
                                  )
                                  .join(' · ')
                    }
                    caption={`${funnel.store_gmv.orders} paid orders`}
                />
            </div>

            {funnel.churn_by_plan.length > 0 && (
                <div>
                    <p className="font-mono text-[10px] tracking-widest uppercase opacity-70">
                        Churn by plan
                    </p>
                    <table className="mt-2 w-full text-sm">
                        <thead>
                            <tr className="text-muted-foreground border-border/70 border-b text-left">
                                <th className="py-2 font-medium">Plan</th>
                                <th className="py-2 text-right font-medium">
                                    On it now
                                </th>
                                <th className="py-2 text-right font-medium">
                                    Lapsed
                                </th>
                                <th className="py-2 text-right font-medium">
                                    Churn
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {funnel.churn_by_plan.map((row) => (
                                <tr
                                    key={row.plan}
                                    className="border-border/40 border-b"
                                >
                                    <td className="py-2">{row.plan}</td>
                                    <Num text={String(row.active)} />
                                    <Num text={String(row.churned)} />
                                    <Num text={percent(row.rate)} />
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {funnel.agency_utilisation.length > 0 && (
                <div>
                    <p className="font-mono text-[10px] tracking-widest uppercase opacity-70">
                        Agency caps (GTM-0.1)
                    </p>
                    <p className="text-muted-foreground mt-1 text-[13px]">
                        An agency near its cap is an upgrade waiting to happen;
                        a whole tier averaging a fifth of it is a cap set too
                        high. This is the evidence for the next price change.
                    </p>
                    <table className="mt-2 w-full text-sm">
                        <thead>
                            <tr className="text-muted-foreground border-border/70 border-b text-left">
                                <th className="py-2 font-medium">Tier</th>
                                <th className="py-2 text-right font-medium">
                                    Orgs
                                </th>
                                <th className="py-2 text-right font-medium">
                                    Brands used
                                </th>
                                <th className="py-2 text-right font-medium">
                                    Seats used
                                </th>
                                <th className="py-2 text-right font-medium">
                                    At the brand cap
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {funnel.agency_utilisation.map((row) => (
                                <tr
                                    key={row.plan}
                                    className="border-border/40 border-b"
                                >
                                    <td className="py-2">{row.plan}</td>
                                    <Num text={String(row.organizations)} />
                                    <Num
                                        text={`${row.brands_used} / ${row.brand_limit ?? '∞'}${
                                            row.brand_utilisation === null
                                                ? ''
                                                : ` (${row.brand_utilisation}%)`
                                        }`}
                                    />
                                    <Num
                                        text={`${row.seats_used} / ${row.seat_limit ?? '∞'}${
                                            row.seat_utilisation === null
                                                ? ''
                                                : ` (${row.seat_utilisation}%)`
                                        }`}
                                    />
                                    <Num text={String(row.at_brand_cap)} />
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Panel>
    );
}

function Figure({
    label,
    value,
    caption,
}: {
    label: string;
    value: string;
    caption: string;
}) {
    return (
        <div className="border-border/70 rounded-xl border p-4">
            <p className="font-mono text-[10px] tracking-widest uppercase opacity-70">
                {label}
            </p>
            <p className="font-display text-2xl">{value}</p>
            <p className="text-muted-foreground text-[13px]">{caption}</p>
        </div>
    );
}

function Num({ text }: { text: string }) {
    return (
        <td className="py-2 text-right font-mono text-xs tabular-nums">
            {text}
        </td>
    );
}
