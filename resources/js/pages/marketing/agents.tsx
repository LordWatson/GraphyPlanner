import {
    MarketingHero,
    MarketingShell,
} from '@/components/marketing/marketing-shell';
import {
    PricingTable,
    type PricingData,
} from '@/components/marketing/pricing-table';

const depth = [
    {
        title: 'Scoped to one workspace',
        body: 'A connection sees one organization and nothing else. Not "filtered in the UI" — the same org scope the rest of the app enforces, applied to every tool call.',
    },
    {
        title: 'Capabilities, not all-or-nothing',
        body: 'A token carries scopes: read insights without being able to draft, draft without being able to publish. The assistant gets what you granted it and no more.',
    },
    {
        title: 'Rate-limited per connection',
        body: 'One noisy assistant cannot spend your whole AI allowance or hammer a network on your behalf.',
    },
    {
        title: 'Every call is on the record',
        body: 'An audit trail of which connection did what, when. When somebody asks "did an AI post that", there is an answer rather than a theory.',
    },
    {
        title: 'Two switches that stop it',
        body: 'Off per organization, and off platform-wide. Both take effect on the next call, not the next deploy.',
    },
    {
        title: 'A security review you can read',
        body: 'Checked into the repository alongside the code, not a PDF written for a procurement form.',
    },
];

/**
 * Step GTM-7.4 — agent access, claimed on **depth** rather than existence.
 *
 * The November 2026 competitor review found Buffer, Metricool, Planable and Hootsuite all shipping
 * MCP servers, several on free plans, so "we have agent access and they don't" is no longer true
 * and was never on this site anyway. What is claimed here is the part a buyer's security review
 * asks about and a single-user connector cannot answer.
 */
export default function Agents({ pricing }: { pricing: PricingData }) {
    return (
        <MarketingShell title="Agent access">
            <MarketingHero
                eyebrow="Agent access"
                title={
                    <>
                        Let an assistant work here{' '}
                        <span className="text-signal">on your terms.</span>
                    </>
                }
                description="Alima speaks MCP, so Claude and other assistants can plan, draft and read your numbers. So does nearly every tool in this category now — the question worth asking is what happens when one of them has access to your clients' accounts."
            />

            <section className="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-3">
                {depth.map(({ title, body }) => (
                    <div
                        key={title}
                        className="border-foreground flex flex-col gap-3 border-t-2 pt-5"
                    >
                        <h2 className="font-display text-xl">{title}</h2>
                        <p className="text-muted-foreground text-sm">{body}</p>
                    </div>
                ))}
            </section>

            <p className="text-muted-foreground mx-auto max-w-xl text-center text-sm">
                An assistant never publishes on its own here: it drafts, and a
                person sends. That is a product decision, not a setting.
            </p>

            <PricingTable pricing={pricing} />
        </MarketingShell>
    );
}
