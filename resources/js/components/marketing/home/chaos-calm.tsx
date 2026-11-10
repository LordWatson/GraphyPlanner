import { useState } from 'react';

import { Eyebrow } from '@/components/page';

/**
 * The before/after drag slider — "from chaos to calm". A messy spread of sticky notes and emails
 * under a clean Alima week, revealed by a range input that drives a clip-path on the "after" layer.
 */
export function ChaosCalm() {
    const [slide, setSlide] = useState(50);

    return (
        <section className="mx-auto flex max-w-[1280px] flex-col gap-9 px-5 py-16 md:px-10 md:py-24 lg:px-20 lg:py-28">
            <div className="hm-reveal flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div className="flex max-w-[820px] flex-col gap-5 lg:flex-1">
                    <Eyebrow dash className="text-primary">
                        BEFORE / AFTER
                    </Eyebrow>
                    <h2 className="font-display text-[clamp(40px,5.6vw,76px)] leading-none font-bold tracking-tight">
                        Drag from chaos to calm
                        <span className="text-signal">.</span>
                    </h2>
                </div>
                <p className="text-muted-foreground text-[17px] lg:w-[360px] lg:shrink-0">
                    Seven tools, forty-seven unread messages and
                    “final_v3_FINAL.png”. Slide to see the same week in Alima.
                </p>
            </div>

            <div className="hm-reveal relative h-[460px] overflow-hidden rounded-[32px] select-none sm:h-[520px]">
                {/* BEFORE */}
                <div aria-hidden className="bg-secondary absolute inset-0">
                    <span className="eyebrow bg-ink text-cream absolute top-[22px] left-6 rounded-full px-3 py-1.5 tracking-[0.2em]">
                        BEFORE
                    </span>
                    <div className="absolute top-[18%] left-[6%] w-[270px] -rotate-4 rounded-tl-[18px] rounded-tr-[18px] rounded-br-[18px] rounded-bl-[4px] bg-white p-3 text-sm leading-snug shadow-[0_8px_20px_-10px_rgb(24_23_35/0.35)]">
                        <b className="text-destructive block text-xs font-semibold">
                            Client · 23:47
                        </b>
                        any update on the reel?? also can we change the font
                    </div>
                    <div className="absolute top-[8%] left-[30%] w-[230px] rotate-3 rounded-tl-[18px] rounded-tr-[18px] rounded-br-[4px] rounded-bl-[18px] bg-white p-3 text-sm leading-snug shadow-[0_8px_20px_-10px_rgb(24_23_35/0.35)]">
                        <b className="text-info block text-xs font-semibold">
                            Account manager
                        </b>
                        which version is final? v3 or v3_FINAL?
                    </div>
                    <div className="absolute top-[52%] left-[4%] w-[300px] rotate-2 rounded-xl bg-white p-2.5 shadow-[0_8px_20px_-10px_rgb(24_23_35/0.35)]">
                        <div className="grid grid-cols-3 gap-0.5 font-mono text-[10px]">
                            <span className="bg-secondary p-1">POST</span>
                            <span className="bg-secondary p-1">STATUS</span>
                            <span className="bg-secondary p-1">OWNER</span>
                            <span className="p-1">Teaser 2</span>
                            <span className="text-destructive p-1">??</span>
                            <span className="p-1">tbc</span>
                            <span className="p-1">Launch</span>
                            <span className="p-1">see email</span>
                            <span className="p-1">Sam?</span>
                            <span className="p-1">UGC</span>
                            <span className="text-destructive p-1">#REF!</span>
                            <span className="p-1">—</span>
                        </div>
                    </div>
                    <div className="bg-lime absolute top-[46%] left-[34%] h-[150px] w-[190px] -rotate-6 p-4 text-[15px] leading-tight font-semibold shadow-[0_8px_20px_-10px_rgb(24_23_35/0.35)]">
                        CHASE OUTSTANDING INVOICE!!! (Fable — 3rd time)
                    </div>
                    <div className="absolute bottom-[6%] left-[22%] -rotate-2 rounded-xl bg-white px-3.5 py-2.5 text-[13px] shadow-[0_8px_20px_-10px_rgb(24_23_35/0.35)]">
                        <b className="font-semibold">
                            RE: RE: FW: final_v3_FINAL.png
                        </b>
                        <br />
                        <span className="text-muted-foreground">
                            “See attached… again”
                        </span>
                    </div>
                    <span className="bg-destructive text-cream absolute top-[12%] left-[44%] flex size-[52px] rotate-[8deg] items-center justify-center rounded-full text-lg font-bold">
                        47
                    </span>
                </div>
                {/* AFTER */}
                <div
                    aria-hidden
                    className="bg-teal absolute inset-0"
                    style={{ clipPath: `inset(0 0 0 ${slide}%)` }}
                >
                    <span className="eyebrow bg-lime text-ink absolute top-[22px] right-6 rounded-full px-3 py-1.5 tracking-[0.2em]">
                        AFTER
                    </span>
                    <div className="bg-card absolute top-[14%] right-[6%] flex w-[min(440px,80%)] flex-col gap-3 rounded-[22px] p-5 shadow-[0_20px_40px_-20px_rgb(24_23_35/0.6)]">
                        <span className="flex items-center justify-between">
                            <b className="text-base font-semibold">
                                This week · Northwind
                            </b>
                            <span className="bg-lime rounded-full px-2.5 py-[3px] text-[11px] font-semibold">
                                All approved
                            </span>
                        </span>
                        <span className="border-secondary flex justify-between border-t py-2 text-sm">
                            <span>Teaser 2 · Reel</span>
                            <span className="text-success font-semibold">
                                Published
                            </span>
                        </span>
                        <span className="border-secondary flex justify-between border-t py-2 text-sm">
                            <span>Launch day · Carousel</span>
                            <span className="text-primary font-semibold">
                                Scheduled 12:00
                            </span>
                        </span>
                        <span className="border-secondary flex justify-between border-t py-2 text-sm">
                            <span>Customer roundup · Story</span>
                            <span className="text-primary font-semibold">
                                Scheduled 17:00
                            </span>
                        </span>
                        <span className="border-secondary flex justify-between border-t py-2 text-sm">
                            <span>INV-0144 · Fable &amp; Co</span>
                            <span className="text-success font-semibold">
                                Paid
                            </span>
                        </span>
                    </div>
                    <div className="bg-ink text-cream absolute right-[6%] bottom-[10%] flex items-center gap-2.5 rounded-full px-4 py-2.5 text-sm font-semibold">
                        <span className="text-lime text-[22px] font-bold">
                            0
                        </span>
                        unread messages
                    </div>
                </div>
                {/* handle */}
                <div
                    aria-hidden
                    className="bg-ink pointer-events-none absolute inset-y-0 -ml-0.5 w-1"
                    style={{ left: `${slide}%` }}
                >
                    <span
                        className="bg-lime text-ink absolute top-1/2 left-1/2 flex size-14 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-4 text-lg font-bold"
                        style={{ borderColor: 'var(--ink)' }}
                    >
                        ⇆
                    </span>
                </div>
                <label className="absolute inset-0 cursor-ew-resize">
                    <span className="sr-only">
                        Compare before and after Alima
                    </span>
                    <input
                        type="range"
                        min={0}
                        max={100}
                        value={slide}
                        onChange={(e) => setSlide(Number(e.target.value))}
                        className="absolute inset-0 m-0 size-full cursor-ew-resize opacity-0"
                    />
                </label>
            </div>
        </section>
    );
}
