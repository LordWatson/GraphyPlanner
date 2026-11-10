import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { Check } from 'lucide-react';

import { Eyebrow } from '@/components/page';
import { register } from '@/routes';

type Audience = {
    label: string;
    eyebrow: string;
    title: string;
    body: string;
    cta: string;
    points: string[];
    panel: string;
    panelText: string;
    eyebrowClass: string;
    bodyClass: string;
};

const AUDS: Audience[] = [
    {
        label: 'Agencies',
        eyebrow: 'FOR AGENCIES',
        title: 'Run every client from one calm roster.',
        body: 'Clients, brands, campaigns and retainers side by side — with a branded portal, team roles and invoices per currency.',
        cta: 'Book an agency demo',
        points: [
            'Branded client portal and approvals',
            'Client health for every account',
            'Team roles and a “Mine” view',
            'Invoices in GBP, AED and more',
        ],
        panel: 'var(--teal)',
        panelText: 'var(--cream)',
        eyebrowClass: 'text-lime',
        bodyClass: 'text-cream/90',
    },
    {
        label: 'Businesses',
        eyebrow: 'FOR BUSINESSES',
        title: 'Your AI marketing partner, always on-brand.',
        body: 'Plan a week in minutes with AI that knows your voice, publish to nine networks and get insights in plain words.',
        cta: 'Start planning',
        points: [
            'Brand Brain for your voice',
            'Plan a week in minutes',
            'Best time to post',
            'Nine networks, one tap',
        ],
        panel: 'var(--ink)',
        panelText: 'var(--cream)',
        eyebrowClass: 'text-lime',
        bodyClass: 'text-cream/80',
    },
    {
        label: 'Creators',
        eyebrow: 'FOR CREATORS',
        title: 'Grow your brand — and get paid for it.',
        body: 'Schedule everywhere, sell from your bio at a 0% fee and land brand deals with a media kit that looks the part.',
        cta: 'Start creating',
        points: [
            'Link in bio built from real data',
            'Downloads, bookings and Q&As',
            'Media kit with sponsor preview',
            'Deals tracker with disclosure',
        ],
        panel: 'var(--lilac)',
        panelText: 'var(--ink)',
        eyebrowClass: 'text-ink',
        bodyClass: 'text-ink/80',
    },
];

/** Who it's for — a tabbed panel that swaps the audience pitch and its proof points. */
export function Audiences() {
    const [active, setActive] = useState(0);
    const aud = AUDS[active];

    return (
        <section className="bg-card border-y">
            <div className="mx-auto flex max-w-[1280px] flex-col gap-8 px-5 py-16 md:px-10 md:py-24 lg:px-20 lg:py-28">
                <div className="hm-reveal flex flex-wrap items-end justify-between gap-6">
                    <div className="flex max-w-[760px] flex-col gap-5">
                        <Eyebrow dash className="text-primary">
                            WHO IT’S FOR
                        </Eyebrow>
                        <h2 className="font-display text-[clamp(40px,5.6vw,76px)] leading-none font-bold tracking-tight">
                            Built for how you grow
                            <span className="text-signal">.</span>
                        </h2>
                    </div>
                    <div
                        role="tablist"
                        aria-label="Audience"
                        className="bg-secondary inline-flex flex-wrap gap-1 rounded-full p-1"
                    >
                        {AUDS.map((a, i) => (
                            <button
                                key={a.label}
                                type="button"
                                role="tab"
                                aria-selected={active === i}
                                onClick={() => setActive(i)}
                                className="h-11 cursor-pointer rounded-full px-5 text-[15px] font-semibold"
                                style={{
                                    background:
                                        active === i
                                            ? 'var(--ink)'
                                            : 'transparent',
                                    color:
                                        active === i
                                            ? 'var(--cream)'
                                            : 'var(--ink)',
                                }}
                            >
                                {a.label}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="hm-screen flex flex-wrap gap-3">
                    <div
                        className="flex flex-[3_1_440px] flex-col gap-4.5 rounded-[28px] p-7 md:p-13"
                        style={{ background: aud.panel, color: aud.panelText }}
                    >
                        <span
                            className={`font-mono text-xs tracking-[0.2em] ${aud.eyebrowClass}`}
                        >
                            {aud.eyebrow}
                        </span>
                        <h3 className="text-[clamp(30px,3.6vw,52px)] leading-[1.02] font-bold tracking-tight">
                            {aud.title}
                        </h3>
                        <p className={`text-[17px] ${aud.bodyClass}`}>
                            {aud.body}
                        </p>
                        <Link
                            href={register()}
                            className="bg-lime text-ink inline-flex h-12 items-center self-start rounded-full px-6 text-base font-semibold"
                        >
                            {aud.cta} →
                        </Link>
                    </div>
                    <div className="grid flex-[2_1_280px] grid-cols-1 gap-2">
                        {aud.points.map((p) => (
                            <div
                                key={p}
                                className="hm-tile bg-background flex items-center gap-3.5 rounded-[20px] px-[18px] py-4"
                            >
                                <span className="bg-teal flex size-8 shrink-0 items-center justify-center rounded-full">
                                    <Check
                                        className="text-cream size-3.5"
                                        strokeWidth={3}
                                    />
                                </span>
                                <span className="text-base leading-snug font-medium">
                                    {p}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </section>
    );
}
