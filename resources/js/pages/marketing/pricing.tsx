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
                description="Start free as one brand, earn from your audience as a creator, or run a roster as an agency. Cancel anytime."
            />

            <PricingTable pricing={pricing} />

            <p className="text-muted-foreground mx-auto max-w-xl text-center text-sm">
                Every paid plan includes a 14-day trial. Annual billing saves
                you two months. On Creator Pro you keep 100% of what you sell —
                Alima never takes a cut.
            </p>

            {/* Step GTM-1.3: said here rather than discovered after the first sale. */}
            <p className="text-muted-foreground mx-auto max-w-xl text-center text-sm">
                Store sales are paid straight into your own Stripe account, so
                you are the seller of record and your own sales tax or VAT is
                yours to handle.
            </p>
        </MarketingShell>
    );
}
