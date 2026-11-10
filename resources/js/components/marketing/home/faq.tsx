import { useState } from 'react';

const FAQS = [
    {
        q: 'Which networks can I publish to?',
        a: 'Instagram, TikTok, Facebook, LinkedIn, YouTube, Threads, Pinterest, X and Google Business — all from one calendar.',
    },
    {
        q: 'How do client approvals work?',
        a: 'Clients review posts, grids and briefs in a branded portal, then approve or request changes. Every post shows its status, so nobody chases.',
    },
    {
        q: 'Does the AI really write in each client’s voice?',
        a: 'Yes. Each brand has its own Brain with voice, pillars and never-say rules. A human always chooses what goes out.',
    },
    {
        q: 'Can I invoice in GBP and AED?',
        a: 'Yes — bill each client in their own currency and see totals per currency.',
    },
    {
        q: 'Do you take a cut of creator sales?',
        a: 'No. Bio-page sales carry a 0% fee. We earn from the plan, never from your sales.',
    },
    {
        q: 'Can I bring my existing posts in?',
        a: 'Yes. Bulk-import posts to fill your calendar fast, then plan, approve and publish from there.',
    },
];

/** The FAQ accordion — one open at a time. */
export function Faq({ onTalk }: { onTalk: () => void }) {
    const [open, setOpen] = useState(0);

    return (
        <section className="bg-card border-t">
            <div className="mx-auto flex max-w-[1280px] flex-col gap-8 px-5 py-16 md:px-10 md:py-24 lg:px-20 lg:py-28">
                <div className="hm-reveal flex flex-wrap items-end justify-between gap-5">
                    <h2 className="font-display text-[clamp(40px,5vw,64px)] leading-none font-bold tracking-tight">
                        Good questions<span className="text-signal">.</span>
                    </h2>
                    <p className="text-muted-foreground">
                        Got another one?{' '}
                        <button
                            type="button"
                            onClick={onTalk}
                            className="text-primary cursor-pointer font-semibold underline-offset-4 hover:underline"
                        >
                            Let’s talk →
                        </button>
                    </p>
                </div>
                <div className="grid grid-cols-1 items-start gap-x-10 md:grid-cols-2">
                    {FAQS.map((f, i) => {
                        const isOpen = open === i;
                        return (
                            <div
                                key={f.q}
                                className="border-foreground border-t"
                            >
                                <button
                                    type="button"
                                    onClick={() => setOpen(isOpen ? -1 : i)}
                                    aria-expanded={isOpen}
                                    className="flex min-h-14 w-full cursor-pointer items-center justify-between gap-5 py-5 text-left text-lg leading-snug font-semibold"
                                >
                                    {f.q}
                                    <span
                                        className={`flex size-[34px] shrink-0 items-center justify-center rounded-full text-xl leading-none transition-transform ${isOpen ? 'bg-ink text-cream rotate-180' : 'bg-secondary text-ink'}`}
                                    >
                                        {isOpen ? '−' : '+'}
                                    </span>
                                </button>
                                {isOpen && (
                                    <p className="hm-screen text-muted-foreground m-0 pr-10 pb-5.5 text-base">
                                        {f.a}
                                    </p>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}
