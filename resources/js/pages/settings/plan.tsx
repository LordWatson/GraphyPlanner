import { Form, Head, Link } from '@inertiajs/react';
import { Check, CreditCard } from 'lucide-react';
import { useState } from 'react';

import PlanController from '@/actions/App/Http/Controllers/Settings/PlanController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { edit, portal } from '@/routes/plan';

type Interval = 'monthly' | 'annual';

type PlanShape = {
    key: string;
    name: string;
    tagline: string;
    trial_days: number;
    is_free: boolean;
    prices: Record<string, { monthly: number | null; annual: number | null }>;
    highlights: string[];
    is_current: boolean;
    has_price: boolean;
};

type UsageMeter = {
    feature: string;
    label: string;
    used: number;
    limit: number | null;
};

type PlanPageProps = {
    currency: 'gbp' | 'usd';
    billingEnabled: boolean;
    current: {
        key: string;
        name: string;
        tagline: string;
        is_free: boolean;
        on_trial: boolean;
        trial_ends_at: string | null;
        subscribed: boolean;
        on_grace_period: boolean;
        stripe_status: string | null;
        ends_at: string | null;
    };
    usage: UsageMeter[];
    plans: PlanShape[];
    annualDiscountLabel: string;
    can: { manage_billing: boolean };
};

const SYMBOL: Record<string, string> = { gbp: '£', usd: '$' };

function Meter({ meter }: { meter: UsageMeter }) {
    const unlimited = meter.limit === null;
    const pct = unlimited
        ? 0
        : Math.min(
              100,
              Math.round((meter.used / Math.max(1, meter.limit!)) * 100),
          );
    const atLimit = !unlimited && meter.used >= (meter.limit ?? 0);

    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex items-baseline justify-between text-sm">
                <span className="text-foreground font-medium">
                    {meter.label}
                </span>
                <span className="text-muted-foreground tabular-nums">
                    {meter.used}
                    {unlimited ? ' · Unlimited' : ` / ${meter.limit}`}
                </span>
            </div>
            {!unlimited && (
                <div className="bg-muted h-2 overflow-hidden rounded-full">
                    <div
                        className={cn(
                            'h-full rounded-full',
                            atLimit ? 'bg-destructive' : 'bg-primary',
                        )}
                        style={{ width: `${pct}%` }}
                    />
                </div>
            )}
        </div>
    );
}

/**
 * Settings → Plan & billing (Owner only). Current plan, this month's usage against the plan's
 * limits, and self-serve upgrade / manage-billing via Stripe. When billing is switched off or a plan
 * has no Stripe price yet, the upgrade buttons explain that checkout isn't available — never an error.
 */
export default function Plan({
    currency,
    billingEnabled,
    current,
    usage,
    plans,
    annualDiscountLabel,
    can,
}: PlanPageProps) {
    const [interval, setInterval] = useState<Interval>('monthly');
    const symbol = SYMBOL[currency] ?? '';

    return (
        <>
            <Head title="Plan & billing" />
            <h1 className="sr-only">Plan &amp; billing</h1>

            <div className="space-y-10">
                <Heading
                    variant="small"
                    title="Plan & billing"
                    description="Your current plan, what you've used this month, and your options. Upgrade or downgrade anytime."
                />

                {/* Current plan */}
                <div className="border-border bg-card flex flex-col gap-4 rounded-2xl border p-5 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-1">
                        <p className="eyebrow text-primary">Current plan</p>
                        <p className="font-display text-2xl">{current.name}</p>
                        <p className="text-muted-foreground text-sm">
                            {current.on_grace_period && current.ends_at
                                ? `Cancels on ${formatDate(current.ends_at)}`
                                : current.on_trial && current.trial_ends_at
                                  ? `Trial ends ${formatDate(current.trial_ends_at)}`
                                  : current.is_free
                                    ? 'Free forever'
                                    : current.subscribed
                                      ? 'Active subscription'
                                      : current.tagline}
                        </p>
                    </div>
                    {can.manage_billing && (
                        <Button variant="outline" asChild>
                            <Link href={portal()}>
                                <CreditCard />
                                Manage billing
                            </Link>
                        </Button>
                    )}
                </div>

                {/* Usage this month */}
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="This month"
                        description="Your usage against the plan's limits. Unlimited features don't show a bar."
                    />
                    <div className="grid gap-5 sm:grid-cols-2">
                        {usage.map((meter) => (
                            <Meter key={meter.feature} meter={meter} />
                        ))}
                    </div>
                </div>

                {/* Plan options */}
                <div className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Heading
                            variant="small"
                            title="Change plan"
                            description=""
                        />
                        <div
                            role="group"
                            aria-label="Billing period"
                            className="border-border bg-muted/50 inline-flex rounded-full border p-1"
                        >
                            {(['monthly', 'annual'] as const).map((value) => (
                                <button
                                    key={value}
                                    type="button"
                                    aria-pressed={interval === value}
                                    onClick={() => setInterval(value)}
                                    className={cn(
                                        'rounded-full px-4 py-1.5 text-sm font-medium capitalize transition-colors',
                                        interval === value
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {value}
                                </button>
                            ))}
                        </div>
                    </div>

                    {interval === 'annual' && (
                        <p className="text-primary text-sm font-medium">
                            {annualDiscountLabel}
                        </p>
                    )}

                    <div className="grid gap-6 md:grid-cols-3">
                        {plans.map((plan) => {
                            const amount = plan.prices[currency]?.[interval];
                            const canCheckout =
                                billingEnabled &&
                                plan.has_price &&
                                !plan.is_current &&
                                !plan.is_free;

                            return (
                                <div
                                    key={plan.key}
                                    className={cn(
                                        'flex flex-col gap-5 rounded-2xl border p-5',
                                        plan.is_current
                                            ? 'border-primary bg-primary/5'
                                            : 'border-border bg-card',
                                    )}
                                >
                                    <div className="space-y-1">
                                        <div className="flex items-center justify-between">
                                            <h3 className="font-display text-xl">
                                                {plan.name}
                                            </h3>
                                            {plan.is_current && (
                                                <span className="bg-primary text-primary-foreground rounded-full px-2.5 py-0.5 text-xs font-semibold">
                                                    Current
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-muted-foreground text-sm">
                                            {plan.tagline}
                                        </p>
                                    </div>

                                    <div className="flex items-baseline gap-1">
                                        {plan.is_free || amount === 0 ? (
                                            <span className="font-display text-3xl">
                                                Free
                                            </span>
                                        ) : (
                                            <>
                                                <span className="font-display text-3xl">
                                                    {symbol}
                                                    {amount}
                                                </span>
                                                <span className="text-muted-foreground text-sm">
                                                    {interval === 'monthly'
                                                        ? '/mo'
                                                        : '/yr'}
                                                </span>
                                            </>
                                        )}
                                    </div>

                                    <ul className="flex flex-1 flex-col gap-2">
                                        {plan.highlights.map((h) => (
                                            <li
                                                key={h}
                                                className="flex items-start gap-2 text-sm"
                                            >
                                                <Check className="text-primary mt-0.5 size-4 shrink-0" />
                                                <span className="text-foreground/90">
                                                    {h}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>

                                    {plan.is_current ? (
                                        <Button
                                            variant="outline"
                                            disabled
                                            className="w-full"
                                        >
                                            Your plan
                                        </Button>
                                    ) : plan.is_free ? (
                                        <p className="text-muted-foreground text-center text-xs">
                                            Downgrade from Manage billing
                                        </p>
                                    ) : canCheckout ? (
                                        <Form
                                            {...PlanController.checkout.form()}
                                            options={{ preserveScroll: true }}
                                            className="w-full"
                                        >
                                            {({ processing }) => (
                                                <>
                                                    <input
                                                        type="hidden"
                                                        name="plan"
                                                        value={plan.key}
                                                    />
                                                    <input
                                                        type="hidden"
                                                        name="currency"
                                                        value={currency}
                                                    />
                                                    <input
                                                        type="hidden"
                                                        name="interval"
                                                        value={interval}
                                                    />
                                                    <Button
                                                        type="submit"
                                                        variant="brand"
                                                        className="w-full"
                                                        disabled={processing}
                                                    >
                                                        {plan.trial_days > 0
                                                            ? `Start ${plan.trial_days}-day trial`
                                                            : 'Upgrade'}
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    ) : (
                                        <div className="space-y-1">
                                            <Button
                                                variant="outline"
                                                disabled
                                                className="w-full"
                                            >
                                                Upgrade
                                            </Button>
                                            <p className="text-muted-foreground text-center text-xs">
                                                Billing isn&apos;t available yet
                                            </p>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        </>
    );
}

Plan.layout = {
    breadcrumbs: [
        {
            title: 'Plan & billing',
            href: edit(),
        },
    ],
};
