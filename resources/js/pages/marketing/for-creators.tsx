import { BadgeCheck, Link2, Wallet } from 'lucide-react';

import {
    MarketingHero,
    MarketingShell,
} from '@/components/marketing/marketing-shell';
import {
    PricingTable,
    type PricingData,
} from '@/components/marketing/pricing-table';

const value = [
    {
        icon: Link2,
        title: 'A link in bio that works',
        description:
            'One brand-styled page for your links, your latest posts and your media kit — built from your real data, not a template.',
    },
    {
        icon: Wallet,
        title: 'Sell at a 0% fee',
        description:
            'Downloads, bookings, tips and paid Q&As from your bio page. You keep 100% — Alima makes its money from the plan, never a cut of your sales.',
    },
    {
        icon: BadgeCheck,
        title: 'Land the brand deals',
        description:
            'A media kit with a sponsor share-preview, a deals tracker and automatic paid-partnership disclosure. Look the part, stay compliant.',
    },
];

/**
 * Step CG-2.7 — the "for creators" segment landing page.
 */
export default function ForCreators({ pricing }: { pricing: PricingData }) {
    return (
        <MarketingShell title="For creators">
            <MarketingHero
                eyebrow="For creators"
                title={
                    <>
                        Grow your brand and{' '}
                        <span className="text-signal">get paid for it.</span>
                    </>
                }
                description="Everything in Pro, plus a storefront, a media kit and a link in bio — so your audience turns into income, at a 0% platform fee."
            />

            <section className="grid grid-cols-1 gap-10 sm:grid-cols-3">
                {value.map(({ icon: Icon, title, description }, i) => (
                    <div
                        key={title}
                        className="border-foreground flex flex-col gap-4 border-t-2 pt-6"
                    >
                        <div className="flex items-center justify-between">
                            <span className="bg-primary text-primary-foreground flex size-12 items-center justify-center rounded-full">
                                <Icon className="size-6" />
                            </span>
                            <span className="text-muted-foreground font-mono text-xs">
                                {String(i + 1).padStart(2, '0')}
                            </span>
                        </div>
                        <h2 className="font-display text-2xl leading-[1.15]">
                            {title}
                        </h2>
                        <p className="text-muted-foreground text-[15px] leading-relaxed">
                            {description}
                        </p>
                    </div>
                ))}
            </section>

            <PricingTable pricing={pricing} />
        </MarketingShell>
    );
}
