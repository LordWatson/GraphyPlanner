import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Eyebrow } from '@/components/page';
import type { PricingData } from '@/components/marketing/pricing-table';
import { register } from '@/routes';

/**
 * Homepage pricing — the three agency tiers. Everything (name, price, feature highlights, trial
 * length) comes from the `pricing` prop, which is built from `config/plans.php` via
 * `PlanCatalog::publicPricing()` (the same source as the /pricing page), so nothing can drift from
 * what the app enforces. Annual shows the per-month equivalent (two months free → annual ÷ 12).
 */
const CARDS = [
    {
        key: 'agency_starter',
        tone: 'light' as const,
        badge: null as string | null,
        action: 'trial' as const,
    },
    {
        key: 'agency_standard',
        tone: 'light' as const,
        badge: null,
        action: 'trial' as const,
    },
    {
        key: 'agency_scale',
        tone: 'ink' as const,
        badge: 'MOST COMPLETE',
        action: 'talk' as const,
    },
];

export function Pricing({
    pricing,
    onTalk,
}: {
    pricing: PricingData;
    onTalk: () => void;
}) {
    const [yearly, setYearly] = useState(false);
    const period = yearly ? 'per month, billed yearly' : 'per month';
    const symbol = pricing.currencies.gbp?.symbol ?? '£';
    const byKey = Object.fromEntries(pricing.plans.map((p) => [p.key, p]));

    const priceLabel = (key: string) => {
        const gbp = byKey[key]?.prices.gbp;
        if (!gbp) return '—';
        if (yearly) return `${symbol}${Math.round((gbp.annual ?? 0) / 12)}`;
        return `${symbol}${gbp.monthly ?? 0}`;
    };

    return (
        <section
            id="pricing"
            className="mx-auto flex max-w-[1280px] scroll-mt-24 flex-col gap-9 px-5 py-16 md:px-10 md:py-24 lg:px-20 lg:py-28"
        >
            <div className="hm-reveal flex flex-wrap items-end justify-between gap-6">
                <div className="flex max-w-[760px] flex-col gap-5">
                    <Eyebrow dash className="text-primary">
                        PRICING
                    </Eyebrow>
                    <h2 className="font-display text-[clamp(40px,5.6vw,76px)] leading-none font-bold tracking-tight">
                        Simple plans. No cut of your sales
                        <span className="text-signal">.</span>
                    </h2>
                </div>
                <div
                    role="group"
                    aria-label="Billing period"
                    className="bg-secondary inline-flex gap-1 rounded-full p-1"
                >
                    {(
                        [
                            ['Monthly', false],
                            ['Yearly', true],
                        ] as const
                    ).map(([label, val]) => (
                        <button
                            key={label}
                            type="button"
                            onClick={() => setYearly(val)}
                            aria-pressed={yearly === val}
                            className="h-11 cursor-pointer rounded-full px-5 text-[15px] font-semibold"
                            style={{
                                background:
                                    yearly === val
                                        ? 'var(--ink)'
                                        : 'transparent',
                                color:
                                    yearly === val
                                        ? 'var(--cream)'
                                        : 'var(--ink)',
                            }}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {CARDS.map((card) => {
                    const plan = byKey[card.key];
                    const ink = card.tone === 'ink';
                    const trial = plan?.trial_days ?? 0;
                    return (
                        <article
                            key={card.key}
                            className={`hm-tile flex flex-col gap-4.5 rounded-[28px] p-8 ${ink ? 'bg-ink text-cream' : 'bg-card shadow-[0_1px_2px_rgb(24_23_35/0.06)]'}`}
                        >
                            <span className="flex items-center justify-between gap-2.5">
                                <span className="text-[22px] font-bold">
                                    {plan?.name ?? card.key}
                                </span>
                                {card.badge && (
                                    <span className="bg-lime text-ink rounded-full px-3 py-1.5 font-mono text-[10px] tracking-[0.16em]">
                                        {card.badge}
                                    </span>
                                )}
                            </span>
                            <span className="flex flex-wrap items-baseline gap-2">
                                <span className="text-5xl leading-none font-bold tracking-tight">
                                    {priceLabel(card.key)}
                                </span>
                                <span
                                    className={`text-[15px] ${ink ? 'text-cream/75' : 'text-muted-foreground'}`}
                                >
                                    {period}
                                </span>
                            </span>
                            <ul
                                className={`flex flex-col gap-2.5 border-t pt-4.5 text-base ${ink ? 'border-cream/15' : 'border-border'}`}
                            >
                                {(plan?.highlights ?? []).map((f) => (
                                    <li key={f}>{f}</li>
                                ))}
                            </ul>
                            {card.action === 'talk' ? (
                                <button
                                    type="button"
                                    onClick={onTalk}
                                    className="bg-lime text-ink mt-auto flex h-12 cursor-pointer items-center justify-center rounded-full text-base font-semibold"
                                >
                                    Let&rsquo;s talk
                                </button>
                            ) : (
                                <Link
                                    href={register()}
                                    className="border-foreground text-foreground hover:bg-secondary mt-auto flex h-12 items-center justify-center rounded-full border-[1.5px] text-base font-semibold"
                                >
                                    {trial > 0
                                        ? `Start ${trial}-day trial`
                                        : 'Get started'}
                                </Link>
                            )}
                        </article>
                    );
                })}
            </div>
        </section>
    );
}
