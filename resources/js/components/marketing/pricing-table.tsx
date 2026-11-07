import { Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { register } from '@/routes';

type Currency = 'gbp' | 'usd';
type Interval = 'monthly' | 'annual';

export type MarketingPlan = {
    key: string;
    name: string;
    segment: string;
    tagline: string;
    trial_days: number;
    is_free: boolean;
    prices: Record<Currency, { monthly: number | null; annual: number | null }>;
    highlights: string[];
};

export type PricingData = {
    plans: MarketingPlan[];
    currencies: Record<Currency, { code: string; symbol: string }>;
    annual_discount_label: string;
};

function SegmentedToggle<T extends string>({
    options,
    value,
    onChange,
    label,
}: {
    options: { value: T; label: string }[];
    value: T;
    onChange: (value: T) => void;
    label: string;
}) {
    return (
        <div
            role="group"
            aria-label={label}
            className="border-border bg-muted/50 inline-flex rounded-full border p-1"
        >
            {options.map((option) => {
                const active = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'rounded-full px-4 py-1.5 text-sm font-medium transition-colors',
                            active
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}

/**
 * Step GTM-0.1 — the segments, in the order config lists their plans. A segment with no plans in
 * the catalogue is simply never rendered, so this never has to be kept in step with config.
 */
const SEGMENTS: Record<string, { title: string; description: string }> = {
    business: {
        title: 'For one brand',
        description:
            'You run your own social media, with no agency in between.',
    },
    creator: {
        title: 'For creators',
        description:
            'You earn from your audience — sponsors, your own products, or both.',
    },
    agency: {
        title: 'For agencies',
        description:
            'You run social media for other people. Tiers differ on how many brands and seats, never on features.',
    },
};

function PlanCard({
    plan,
    currency,
    interval,
    symbol,
    popular,
}: {
    plan: MarketingPlan;
    currency: Currency;
    interval: Interval;
    symbol: string;
    popular: boolean;
}) {
    const amount = plan.prices[currency]?.[interval];

    return (
        <div
            className={cn(
                'bg-card flex flex-col gap-6 rounded-2xl border p-6',
                popular
                    ? 'border-primary ring-primary/20 shadow-md ring-1'
                    : 'border-border',
            )}
        >
            <div className="flex flex-col gap-2">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="font-display text-2xl">{plan.name}</h3>
                    {popular && (
                        <span className="bg-primary text-primary-foreground shrink-0 rounded-full px-3 py-1 text-xs font-semibold">
                            Most popular
                        </span>
                    )}
                </div>
                <p className="text-muted-foreground text-sm">{plan.tagline}</p>
            </div>

            <div className="flex items-baseline gap-1.5">
                {plan.is_free || amount === 0 ? (
                    <span className="font-display text-4xl">Free</span>
                ) : (
                    <>
                        <span className="font-display text-4xl">
                            {symbol}
                            {amount}
                        </span>
                        <span className="text-muted-foreground text-sm">
                            {interval === 'monthly' ? '/month' : '/year'}
                        </span>
                    </>
                )}
            </div>

            <Button
                asChild
                variant={popular ? 'spark' : 'outline'}
                className="w-full"
            >
                <Link href={register()}>
                    {plan.is_free
                        ? 'Start free'
                        : plan.trial_days > 0
                          ? `Start ${plan.trial_days}-day trial`
                          : 'Choose plan'}
                </Link>
            </Button>

            <ul className="flex flex-col gap-3">
                {plan.highlights.map((highlight) => (
                    <li
                        key={highlight}
                        className="flex items-start gap-2.5 text-sm"
                    >
                        <Check className="text-primary mt-0.5 size-4 shrink-0" />
                        <span className="text-foreground/90">{highlight}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

export function PricingTable({ pricing }: { pricing: PricingData }) {
    const [currency, setCurrency] = useState<Currency>('gbp');
    const [interval, setInterval] = useState<Interval>('monthly');

    const symbol = pricing.currencies[currency]?.symbol ?? '';

    // Grouped by segment, first appearance in config order wins. Within a segment the second plan
    // is the headline recommendation (Pro, Agency) — a lone plan gets no badge.
    const groups = new Map<string, MarketingPlan[]>();
    for (const plan of pricing.plans) {
        groups.set(plan.segment, [...(groups.get(plan.segment) ?? []), plan]);
    }

    return (
        <section className="flex flex-col gap-12">
            <div className="flex flex-wrap items-center justify-center gap-3">
                <SegmentedToggle
                    label="Currency"
                    value={currency}
                    onChange={setCurrency}
                    options={[
                        { value: 'gbp', label: '£ GBP' },
                        { value: 'usd', label: '$ USD' },
                    ]}
                />
                <SegmentedToggle
                    label="Billing period"
                    value={interval}
                    onChange={setInterval}
                    options={[
                        { value: 'monthly', label: 'Monthly' },
                        { value: 'annual', label: 'Annual' },
                    ]}
                />
                {interval === 'annual' && (
                    <span className="text-primary text-sm font-medium">
                        {pricing.annual_discount_label}
                    </span>
                )}
            </div>

            {[...groups].map(([segment, plans]) => (
                <div key={segment} className="flex flex-col gap-6">
                    <div className="flex flex-col gap-1">
                        <h2 className="font-mono text-xs tracking-widest uppercase">
                            {SEGMENTS[segment]?.title ?? segment}
                        </h2>
                        {SEGMENTS[segment] && (
                            <p className="text-muted-foreground text-sm">
                                {SEGMENTS[segment].description}
                            </p>
                        )}
                    </div>

                    <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                        {plans.map((plan, index) => (
                            <PlanCard
                                key={plan.key}
                                plan={plan}
                                currency={currency}
                                interval={interval}
                                symbol={symbol}
                                popular={plans.length > 1 && index === 1}
                            />
                        ))}
                    </div>
                </div>
            ))}
        </section>
    );
}
