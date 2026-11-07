import { Link } from '@inertiajs/react';
import { Check, Minus } from 'lucide-react';

import {
    MarketingHero,
    MarketingShell,
} from '@/components/marketing/marketing-shell';
import {
    PricingTable,
    type PricingData,
} from '@/components/marketing/pricing-table';
import { Button } from '@/components/ui/button';
import { compare as comparePage } from '@/routes';
import { register } from '@/routes';

type Rival = {
    slug: string;
    name: string;
    price: string;
    positioning: string;
    strengths: string[];
    ours: string[];
};

/**
 * Step GTM-7.3 — one comparison page per competitor, generated from `config/competitors.php`.
 *
 * Two rules the page keeps, because a comparison page that breaks them gets quoted back at you:
 * every figure carries the one dated "last checked", and the rival's **strengths come first**. We
 * never claim a competitor lacks something — only what they charge and what they say they include.
 */
export default function Compare({
    rival,
    others,
    lastChecked,
    pricing,
}: {
    rival: Rival;
    others: { slug: string; name: string }[];
    lastChecked: string;
    pricing: PricingData;
}) {
    return (
        <MarketingShell title={`Alima vs ${rival.name}`}>
            <MarketingHero
                eyebrow="Honest comparison"
                title={
                    <>
                        Alima vs{' '}
                        <span className="text-signal">{rival.name}</span>
                    </>
                }
                description={`${rival.positioning} Here is where each of us is the better answer — checked against ${rival.name}'s own pricing page, not a roundup.`}
            />

            <div className="mx-auto grid w-full max-w-4xl gap-6 md:grid-cols-2">
                <section className="border-border bg-card flex flex-col gap-4 rounded-2xl border p-6">
                    <header className="flex flex-col gap-1">
                        <h2 className="font-mono text-xs tracking-widest uppercase">
                            Pick {rival.name} if…
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            {rival.price}
                        </p>
                    </header>
                    <ul className="flex flex-col gap-3">
                        {rival.strengths.map((point) => (
                            <li
                                key={point}
                                className="flex items-start gap-2.5 text-sm"
                            >
                                <Check className="text-primary mt-0.5 size-4 shrink-0" />
                                <span className="text-foreground/90">
                                    {point}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="border-primary ring-primary/20 bg-card flex flex-col gap-4 rounded-2xl border p-6 shadow-md ring-1">
                    <header className="flex flex-col gap-1">
                        <h2 className="font-mono text-xs tracking-widest uppercase">
                            Pick Alima if…
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            Free for one brand · £15/month Pro · £49/month for
                            agencies
                        </p>
                    </header>
                    <ul className="flex flex-col gap-3">
                        {rival.ours.map((point) => (
                            <li
                                key={point}
                                className="flex items-start gap-2.5 text-sm"
                            >
                                <Check className="text-primary mt-0.5 size-4 shrink-0" />
                                <span className="text-foreground/90">
                                    {point}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <Button asChild variant="spark" className="mt-auto w-full">
                        <Link href={register()}>Start free</Link>
                    </Button>
                </section>
            </div>

            <p className="text-muted-foreground mx-auto max-w-xl text-center text-sm">
                {rival.name}&rsquo;s prices and inclusions were last read from
                their own pricing page on{' '}
                <strong>
                    {new Date(lastChecked).toLocaleDateString(undefined, {
                        day: 'numeric',
                        month: 'long',
                        year: 'numeric',
                    })}
                </strong>
                . Prices change — check theirs before deciding, and tell us if
                anything here is out of date.
            </p>

            {others.length > 0 && (
                <nav className="flex flex-wrap items-center justify-center gap-2">
                    <span className="text-muted-foreground font-mono text-[11px] tracking-widest uppercase">
                        <Minus className="mr-1 inline size-3" />
                        Also compared
                    </span>
                    {others.map((other) => (
                        <Button
                            key={other.slug}
                            asChild
                            variant="outline"
                            size="sm"
                        >
                            <Link href={comparePage(other.slug)}>
                                vs {other.name}
                            </Link>
                        </Button>
                    ))}
                </nav>
            )}

            <PricingTable pricing={pricing} />
        </MarketingShell>
    );
}
