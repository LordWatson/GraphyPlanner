import { Brain, Gauge, Share2 } from 'lucide-react';

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
        icon: Brain,
        title: 'AI that knows your brand',
        description:
            'Your voice, your never-say rules, your pillars. Plan a week, repurpose a post, or caption a photo — always on-brand, never generic.',
    },
    {
        icon: Gauge,
        title: 'Insights in plain words',
        description:
            'Not just charts — takeaways that tell you what worked and what to do next, with the best time to post for your audience.',
    },
    {
        icon: Share2,
        title: 'Nine networks, one tap',
        description:
            'Instagram, TikTok, Facebook, LinkedIn, Threads, Pinterest, X, YouTube and Google Business — connected and scheduled from one calendar.',
    },
];

/**
 * Step CG-2.7 — the "for business" segment landing page.
 */
export default function ForBusiness({ pricing }: { pricing: PricingData }) {
    return (
        <MarketingShell title="For business">
            <MarketingHero
                eyebrow="For business"
                title={
                    <>
                        The AI marketing partner for{' '}
                        <span className="text-signal">one brand.</span>
                    </>
                }
                description="Plan, create and publish for your business without an agency in between. The strategist is built in — you stay in control."
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
