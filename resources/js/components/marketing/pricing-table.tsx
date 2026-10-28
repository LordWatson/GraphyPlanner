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

export function PricingTable({ pricing }: { pricing: PricingData }) {
    const [currency, setCurrency] = useState<Currency>('gbp');
    const [interval, setInterval] = useState<Interval>('monthly');

    const symbol = pricing.currencies[currency]?.symbol ?? '';

    // The second public plan (Pro) is the headline recommendation.
    const popularKey = pricing.plans[1]?.key;

    return (
        <section className="flex flex-col gap-10">
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

            <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                {pricing.plans.map((plan) => {
                    const amount = plan.prices[currency]?.[interval];
                    const popular = plan.key === popularKey;

                    return (
                        <div
                            key={plan.key}
                            className={cn(
                                'bg-card flex flex-col gap-6 rounded-2xl border p-6',
                                popular
                                    ? 'border-primary ring-primary/20 shadow-md ring-1'
                                    : 'border-border',
                            )}
                        >
                            <div className="flex flex-col gap-2">
                                <div className="flex items-center justify-between">
                                    <h3 className="font-display text-2xl">
                                        {plan.name}
                                    </h3>
                                    {popular && (
                                        <span className="bg-primary text-primary-foreground rounded-full px-3 py-1 text-xs font-semibold">
                                            Most popular
                                        </span>
                                    )}
                                </div>
                                <p className="text-muted-foreground text-sm">
                                    {plan.tagline}
                                </p>
                            </div>

                            <div className="flex items-baseline gap-1.5">
                                {plan.is_free || amount === 0 ? (
                                    <span className="font-display text-4xl">
                                        Free
                                    </span>
                                ) : (
                                    <>
                                        <span className="font-display text-4xl">
                                            {symbol}
                                            {amount}
                                        </span>
                                        <span className="text-muted-foreground text-sm">
                                            {interval === 'monthly'
                                                ? '/month'
                                                : '/year'}
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
                                        <span className="text-foreground/90">
                                            {highlight}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
