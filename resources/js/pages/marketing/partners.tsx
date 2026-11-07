import {
    MarketingHero,
    MarketingShell,
} from '@/components/marketing/marketing-shell';
import { Button } from '@/components/ui/button';

/**
 * Step GTM-7.3 — the affiliate programme's public page. Deliberately plain: what the commission
 * is, how attribution works, and how to ask. Nothing self-serve, because the first partners are
 * people we will talk to anyway, and a form would imply an approval process that doesn't exist.
 */
export default function Partners({
    commissionPercent,
    cookieDays,
    contactEmail,
}: {
    commissionPercent: number;
    cookieDays: number;
    contactEmail: string;
}) {
    const steps = [
        {
            title: 'You get a link',
            body: `A code of your own, on any page: alima.com/pricing?ref=yourcode. We validate the code before we store anything, so a mistyped link simply doesn't track.`,
        },
        {
            title: 'It is remembered for ' + cookieDays + ' days',
            body: 'One cookie holding one code. No identifier, no fingerprint, and nothing in our database until somebody actually signs up. First touch wins: whoever introduced them keeps the credit.',
        },
        {
            title: `You are paid ${commissionPercent}% of what they pay`,
            body: 'For as long as they stay. We record every payout against the period it covers, so what is owed and what has been settled are never the same number by accident.',
        },
    ];

    return (
        <MarketingShell title="Partners">
            <MarketingHero
                eyebrow="Partners"
                title={
                    <>
                        Send people here,{' '}
                        <span className="text-signal">get paid for it.</span>
                    </>
                }
                description="If you write about social media tools, run an agency community, or teach people this job, there is a straightforward arrangement here."
            />

            <div className="mx-auto grid w-full max-w-4xl gap-6 md:grid-cols-3">
                {steps.map((step, index) => (
                    <section
                        key={step.title}
                        className="border-border bg-card flex flex-col gap-2 rounded-2xl border p-6"
                    >
                        <span className="font-mono text-xs tracking-widest uppercase opacity-60">
                            {String(index + 1).padStart(2, '0')}
                        </span>
                        <h2 className="font-display text-xl">{step.title}</h2>
                        <p className="text-muted-foreground text-sm">
                            {step.body}
                        </p>
                    </section>
                ))}
            </div>

            <div className="mx-auto flex max-w-xl flex-col items-center gap-4 text-center">
                <p className="text-[15px]">
                    One condition, and it is the whole deal: write what you
                    actually think. If a competitor is the better answer for
                    your audience, say so — we publish our own comparison pages
                    on exactly that basis.
                </p>
                <Button asChild variant="spark" size="lg">
                    <a
                        href={`mailto:${contactEmail}?subject=Partner%20programme`}
                    >
                        Email {contactEmail}
                    </a>
                </Button>
            </div>
        </MarketingShell>
    );
}
