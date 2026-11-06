import {
    MarketingHero,
    MarketingShell,
} from '@/components/marketing/marketing-shell';
import {
    PricingTable,
    type PricingData,
} from '@/components/marketing/pricing-table';

/**
 * Step CG-2.7 — the public pricing page. The table is generated from config/plans.php, so the prices
 * shown here are exactly the ones the app enforces.
 */
export default function Pricing({ pricing }: { pricing: PricingData }) {
    return (
        <MarketingShell title="Pricing">
            <MarketingHero
                eyebrow="Pricing"
                title={
                    <>
                        Simple plans,{' '}
                        <span className="text-signal">no surprises.</span>
                    </>
                }
                description="Start free. Upgrade when you're ready for the full AI partner, unlimited scheduling and the creator tools. Cancel anytime."
            />

            <PricingTable pricing={pricing} />

            <p className="text-muted-foreground mx-auto max-w-xl text-center text-sm">
                Every paid plan includes a 14-day trial. Annual billing saves
                you two months. On Creator Pro you keep 100% of what you sell —
                Alima never takes a cut.
            </p>
        </MarketingShell>
    );
}
