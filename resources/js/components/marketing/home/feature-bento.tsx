import { type CSSProperties, useState } from 'react';

import { Eyebrow } from '@/components/page';

const CURRENCIES: [string, string][] = [
    ['GBP', '£2,400'],
    ['AED', 'AED 11,150'],
    ['USD', '$3,040'],
];

/**
 * The feature bento — eight tiles that each hint at one part of the planner. Three are interactive:
 * the client-portal phone (approve / request changes), the currency toggle on the invoices tile,
 * and the typing command-palette animation.
 */
export function FeatureBento() {
    const [portal, setPortal] = useState<'pending' | 'approved' | 'changes'>(
        'pending',
    );
    const [cur, setCur] = useState(0);
    const done = portal !== 'pending';

    return (
        <section
            id="features"
            className="mx-auto flex max-w-[1280px] scroll-mt-24 flex-col gap-9 px-5 py-16 md:px-10 md:py-24 lg:px-20 lg:py-28"
        >
            <div className="hm-reveal flex flex-wrap items-end justify-between gap-6">
                <div className="flex max-w-[820px] flex-col gap-5">
                    <Eyebrow dash className="text-primary">
                        EVERYTHING IN ONE PLACE
                    </Eyebrow>
                    <h2 className="font-display text-[clamp(40px,5.6vw,76px)] leading-none font-bold tracking-tight">
                        Seven tools, replaced by one
                        <span className="text-signal">.</span>
                    </h2>
                </div>
                <span className="eyebrow text-muted-foreground">
                    <span className="hidden md:inline">HOVER THE TILES ↓</span>
                    <span className="md:hidden">SWIPE →</span>
                </span>
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {/* Campaigns */}
                <article className="hm-tile hm-tile-camp bg-teal text-cream flex flex-col gap-3.5 rounded-[28px] p-7 sm:col-span-2">
                    <span className="text-lime font-mono text-[11px] tracking-[0.2em]">
                        CAMPAIGNS
                    </span>
                    <h3 className="text-[30px] leading-tight font-bold tracking-tight">
                        Brief to wrap report, with proof.
                    </h3>
                    <div className="mt-auto flex flex-col gap-2.5">
                        {(
                            [
                                ['Spring launch', 86, 'var(--lime)'],
                                ['Black Friday bundles', 54, 'var(--lilac)'],
                                ['Winter lookbook', 20, 'var(--cream)'],
                            ] as const
                        ).map(([name, pct, col]) => (
                            <span
                                key={name}
                                className="flex flex-col gap-1.5 text-[13px]"
                            >
                                <span className="flex justify-between">
                                    <span>{name}</span>
                                    <span className="font-mono">{pct}%</span>
                                </span>
                                <span
                                    className="h-2 overflow-hidden rounded-full"
                                    style={{
                                        background: 'rgb(245 242 232 / 0.15)',
                                    }}
                                >
                                    <span
                                        className="hm-bar"
                                        style={
                                            {
                                                '--w': `${pct}%`,
                                                background: col,
                                            } as CSSProperties
                                        }
                                    />
                                </span>
                            </span>
                        ))}
                    </div>
                </article>

                {/* Client portal (interactive) */}
                <article className="hm-tile bg-lilac text-ink flex flex-wrap items-center gap-6 rounded-[28px] p-7 sm:col-span-2 sm:row-span-2">
                    <div className="flex flex-1 basis-[200px] flex-col gap-3.5">
                        <span className="font-mono text-[11px] tracking-[0.2em]">
                            CLIENT PORTAL · TRY IT
                        </span>
                        <h3 className="text-[34px] leading-[1.05] font-bold tracking-tight">
                            You’re the client. Approve it.
                        </h3>
                        <p className="text-[15px] leading-relaxed">
                            One branded link to approve posts, sign off briefs,
                            read reports and pay invoices. No logins to forget.
                        </p>
                    </div>
                    <div className="bg-ink mx-auto flex-[0_0_248px] rounded-[34px] p-[9px] shadow-[0_24px_48px_-20px_rgb(24_23_35/0.55)]">
                        <div className="bg-background flex h-[430px] flex-col gap-2.5 rounded-[26px] px-3.5 py-4">
                            <span className="flex items-center gap-2 text-[13px] font-semibold">
                                <span className="bg-teal text-cream flex size-6 items-center justify-center rounded-full font-mono text-[9px]">
                                    NW
                                </span>
                                Northwind
                                <span className="text-muted-foreground ml-auto font-mono text-[8px] tracking-[0.14em]">
                                    BY STUDIO KITE
                                </span>
                            </span>
                            <div className="bg-teal text-cream flex h-[190px] flex-col justify-between rounded-2xl p-3">
                                <span className="bg-ink self-end rounded-md px-[7px] py-[3px] font-mono text-[9px]">
                                    REEL · 0:24
                                </span>
                                <b className="text-[26px] leading-none font-bold tracking-tight">
                                    Spring is
                                    <br />
                                    here.
                                </b>
                            </div>
                            <span className="flex flex-col leading-snug">
                                <b className="text-sm font-semibold">
                                    Launch day — it’s here
                                </b>
                                <span className="text-muted-foreground text-[11px]">
                                    Wed 11 Nov · 12:00
                                </span>
                            </span>
                            {!done ? (
                                <span className="mt-auto flex flex-col gap-1.5">
                                    <button
                                        type="button"
                                        onClick={() => setPortal('approved')}
                                        className="bg-lime h-11 cursor-pointer rounded-full text-[15px] font-semibold"
                                    >
                                        Approve
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setPortal('changes')}
                                        className="border-ink h-11 cursor-pointer rounded-full border-[1.5px] text-[15px] font-semibold"
                                    >
                                        Request changes
                                    </button>
                                </span>
                            ) : (
                                <div
                                    className="hm-screen mt-auto flex flex-col gap-1 rounded-2xl p-3"
                                    style={{
                                        background:
                                            portal === 'approved'
                                                ? 'var(--lime)'
                                                : '#F1E8F5',
                                    }}
                                >
                                    <b className="text-base font-bold">
                                        {portal === 'approved'
                                            ? 'Approved. Thank you!'
                                            : 'Changes requested.'}
                                    </b>
                                    <span className="text-xs leading-snug">
                                        {portal === 'approved'
                                            ? 'Studio Kite has been told. It goes live Wed 11 Nov at 12:00.'
                                            : 'Your note has gone to Studio Kite. The new version will land here.'}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => setPortal('pending')}
                                        className="text-ink cursor-pointer self-start py-1 text-xs font-semibold underline"
                                    >
                                        Try again
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                </article>

                {/* Grid planner */}
                <article className="hm-tile hm-tile-grid bg-card flex flex-col gap-3 rounded-[28px] p-6 shadow-[0_1px_2px_rgb(24_23_35/0.06)]">
                    <span className="text-primary font-mono text-[11px] tracking-[0.2em]">
                        GRIDS
                    </span>
                    <div
                        aria-hidden
                        className="grid w-[170px] grid-cols-3 gap-1 self-center overflow-hidden rounded-xl"
                    >
                        {[
                            'var(--teal)',
                            'var(--lime)',
                            'var(--lilac)',
                            'var(--signal)',
                            'var(--ink)',
                            'var(--secondary)',
                            'var(--electric)',
                            'var(--teal)',
                            'var(--lime)',
                        ].map((c, i) => (
                            <span
                                key={i}
                                className="hm-sq aspect-square"
                                style={{ background: c }}
                            />
                        ))}
                    </div>
                    <h3 className="text-xl leading-tight font-bold">
                        Plan the feed before it’s live.
                    </h3>
                </article>

                {/* AI Image Studio */}
                <article className="hm-tile hm-tile-img bg-ink text-cream flex flex-col gap-3 overflow-hidden rounded-[28px] p-6">
                    <span className="text-lime font-mono text-[11px] tracking-[0.2em]">
                        AI IMAGE STUDIO
                    </span>
                    <div
                        aria-hidden
                        className="relative flex h-[130px] items-center justify-center"
                    >
                        <span
                            className="hm-card hm-c1 absolute h-28 w-[86px] rounded-xl"
                            style={{ background: 'var(--lilac)' }}
                        />
                        <span
                            className="hm-card hm-c3 absolute h-28 w-[86px] rounded-xl"
                            style={{ background: 'var(--electric)' }}
                        />
                        <span className="hm-card hm-c2 bg-lime text-ink absolute flex h-28 w-[86px] items-end rounded-xl p-2 text-[10px] font-semibold">
                            On-brand ✓
                        </span>
                    </div>
                    <h3 className="text-xl leading-tight font-bold">
                        Ideas into images. You choose.
                    </h3>
                </article>

                {/* Inbox */}
                <article className="hm-tile hm-tile-inbox bg-card flex flex-col gap-2.5 rounded-[28px] p-6 shadow-[0_1px_2px_rgb(24_23_35/0.06)]">
                    <span className="text-primary font-mono text-[11px] tracking-[0.2em]">
                        INBOX
                    </span>
                    <span className="bg-secondary self-start rounded-tl-[14px] rounded-tr-[14px] rounded-br-[14px] rounded-bl-[4px] px-3 py-2 text-[13px]">
                        Love this! Where can I buy it?
                    </span>
                    <span className="bg-teal text-cream self-end rounded-tl-[14px] rounded-tr-[14px] rounded-br-[4px] rounded-bl-[14px] px-3 py-2 text-[13px]">
                        Link’s in our bio
                    </span>
                    <span className="hm-msg3 bg-secondary self-start rounded-tl-[14px] rounded-tr-[14px] rounded-br-[14px] rounded-bl-[4px] px-3 py-2 text-[13px]">
                        Ordered. Thank you!
                    </span>
                    <h3 className="mt-auto text-xl leading-tight font-bold">
                        Every comment, one inbox.
                    </h3>
                </article>

                {/* Health */}
                <article className="hm-tile bg-card flex flex-col gap-2.5 rounded-[28px] p-6 shadow-[0_1px_2px_rgb(24_23_35/0.06)]">
                    <span className="text-primary font-mono text-[11px] tracking-[0.2em]">
                        CLIENT HEALTH
                    </span>
                    <span className="border-secondary flex items-center justify-between border-b py-1.5 text-sm">
                        Northwind
                        <span
                            className="size-3 rounded-full"
                            style={{ background: 'var(--success)' }}
                        />
                    </span>
                    <span className="border-secondary flex items-center justify-between border-b py-1.5 text-sm">
                        Fable &amp; Co
                        <span
                            className="size-3 rounded-full"
                            style={{ background: 'var(--warning)' }}
                        />
                    </span>
                    <span className="flex items-center justify-between py-1.5 text-sm">
                        Lumen Studio
                        <span
                            className="hm-pulse-red size-3 rounded-full"
                            style={{ background: 'var(--signal)' }}
                        />
                    </span>
                    <h3 className="mt-auto text-xl leading-tight font-bold">
                        See trouble coming early.
                    </h3>
                </article>

                {/* Invoices (interactive currency) */}
                <article className="hm-tile bg-lime flex flex-col gap-3 rounded-[28px] p-6">
                    <span className="font-mono text-[11px] tracking-[0.2em]">
                        INVOICES
                    </span>
                    <div
                        role="group"
                        aria-label="Currency"
                        className="flex gap-1 self-start rounded-full p-[3px]"
                        style={{ background: 'rgb(24 23 35 / 0.1)' }}
                    >
                        {CURRENCIES.map(([code], i) => (
                            <button
                                key={code}
                                type="button"
                                onClick={() => setCur(i)}
                                aria-pressed={cur === i}
                                className="h-8 cursor-pointer rounded-full px-3 text-xs font-semibold"
                                style={{
                                    background:
                                        cur === i
                                            ? 'var(--ink)'
                                            : 'transparent',
                                    color:
                                        cur === i
                                            ? 'var(--cream)'
                                            : 'var(--ink)',
                                }}
                            >
                                {code}
                            </button>
                        ))}
                    </div>
                    <span className="hm-screen text-[40px] leading-none font-bold tracking-tight">
                        {CURRENCIES[cur][1]}
                    </span>
                    <h3 className="mt-auto text-xl leading-tight font-bold">
                        Bill in their currency.
                    </h3>
                </article>

                {/* Command palette */}
                <article className="hm-tile bg-ink text-cream flex flex-col gap-3.5 rounded-[28px] p-6">
                    <div className="flex flex-col gap-2">
                        <span className="text-lime font-mono text-[11px] tracking-[0.2em]">
                            CTRL K
                        </span>
                        <h3 className="text-xl leading-tight font-bold">
                            Jump anywhere, instantly.
                        </h3>
                    </div>
                    <div
                        className="mt-auto flex flex-col gap-1 rounded-2xl p-2 text-xs"
                        style={{ background: '#2E2D3A' }}
                    >
                        <span className="bg-ink text-cream rounded-[10px] px-3 py-2.5 font-mono text-[13px]">
                            › <span className="hm-typed">invoice oro coff</span>
                            <span className="hm-caret text-lime">▍</span>
                        </span>
                        <span className="bg-background text-foreground flex justify-between gap-1.5 rounded-[10px] px-2.5 py-2 font-semibold">
                            <span>Send invoice · Oro</span>
                            <span className="font-mono">↵</span>
                        </span>
                    </div>
                </article>
            </div>
        </section>
    );
}
