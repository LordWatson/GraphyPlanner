import { useEffect, useRef, useState } from 'react';
import { Sparkles } from 'lucide-react';

import { Eyebrow } from '@/components/page';

const PILLARS = ['Origin stories', 'Brewing rituals', 'Community'];
const NETS = ['Instagram', 'LinkedIn', 'TikTok'];
const COPY = [
    [
        'Every bag starts on a hillside 2,000 metres up. Every cup ends with you. This week we’re telling the story in between.',
        'Meet Abebe — third-generation farmer, first-class coffee. Swipe for the hillside where your morning cup begins.',
        'Altitude, patience and a lot of hand-sorting. That’s what’s in your cup this morning.',
    ],
    [
        'Slow pour, slow morning. Four minutes, one kettle, zero rush — here’s our favourite way to start the day.',
        'The 1:16 ratio, explained by our head roaster in under a minute. Save this for your next brew.',
        'Bloom for 30 seconds. Breathe for 30 more. Coffee tastes better when you do.',
    ],
    [
        'You brought the stories, we brought the coffee. Thank you, Al Quoz — Saturday’s cupping was one for the books.',
        'Our regulars, in their own words. A small thank-you to the people who make Oro feel like home.',
        'Same table, same order, every Friday. We see you, Samira — this one’s on us.',
    ],
];
const TAGS = [
    '#OroOrigins #SlowCoffee',
    '#CoffeeIndustry #Sourcing #UAEBusiness',
    '#coffeetok #brewguide #dubaicafe',
];

/**
 * The Brand Brain generator — pick a content pillar and a network, and a short "thinking" shimmer
 * resolves into an on-brand AI caption. A human always chooses: "Use caption" is the final step.
 */
export function BrandBrain() {
    const [pillar, setPillar] = useState(0);
    const [net, setNet] = useState(0);
    const [variant, setVariant] = useState(0);
    const [gen, setGen] = useState(false);
    const [used, setUsed] = useState(false);
    const genTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(
        () => () => {
            if (genTimer.current) clearTimeout(genTimer.current);
        },
        [],
    );

    const regen = () => {
        if (genTimer.current) clearTimeout(genTimer.current);
        setGen(true);
        setUsed(false);
        genTimer.current = setTimeout(() => {
            setGen(false);
            setVariant((v) => v + 1);
        }, 950);
    };

    const chip = (on: boolean) =>
        `h-10 cursor-pointer rounded-full px-4 text-sm font-semibold ${on ? 'bg-lime text-ink border-lime' : 'text-cream border-muted-foreground'} border-[1.5px]`;

    const draftText = COPY[pillar][variant % 3];
    const draftTags = TAGS[net];

    return (
        <section id="ai" className="bg-ink text-cream scroll-mt-24">
            <div className="mx-auto flex max-w-[1280px] flex-wrap items-center gap-9 px-5 py-16 md:px-10 md:py-24 lg:gap-20 lg:px-20 lg:py-28">
                <div className="hm-reveal flex flex-1 basis-[400px] flex-col gap-5">
                    <Eyebrow dash className="text-lime">
                        BRAND BRAIN
                    </Eyebrow>
                    <h2 className="font-display text-[clamp(40px,5.6vw,76px)] leading-none font-bold tracking-tight">
                        AI that sounds like{' '}
                        <span className="text-lilac">them</span>. Not like AI
                        <span className="text-signal">.</span>
                    </h2>
                    <p className="max-w-[500px]" style={{ color: '#C9C6C1' }}>
                        Every brand gets its own Brain — voice, pillars,
                        never-say rules and references. Pick a pillar and a
                        network, then hit generate.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {[
                            'Voice: warm, witty',
                            'Never: “cheap”',
                            'Never: “best in town”',
                        ].map((t) => (
                            <span
                                key={t}
                                className="rounded-full border px-3.5 py-[7px] text-[13px]"
                                style={{ borderColor: '#44434A' }}
                            >
                                {t}
                            </span>
                        ))}
                    </div>
                </div>

                <div
                    className="hm-reveal flex flex-1 basis-[480px] flex-col gap-4 rounded-[28px] p-5 md:p-[30px]"
                    style={{ background: '#2E2D3A' }}
                >
                    <span className="flex items-center gap-3 font-semibold">
                        <span className="bg-lime text-ink flex size-9 items-center justify-center rounded-[10px] font-mono text-xs">
                            OC
                        </span>
                        Oro Coffee · Brand Brain
                    </span>
                    <div className="flex flex-col gap-2">
                        <span
                            className="font-mono text-[10px] tracking-[0.16em]"
                            style={{ color: '#9D9A99' }}
                        >
                            PILLAR
                        </span>
                        <div className="flex flex-wrap gap-1.5">
                            {PILLARS.map((l, i) => (
                                <button
                                    key={l}
                                    type="button"
                                    onClick={() => {
                                        setPillar(i);
                                        regen();
                                    }}
                                    aria-pressed={pillar === i}
                                    className={chip(pillar === i)}
                                >
                                    {l}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="flex flex-col gap-2">
                        <span
                            className="font-mono text-[10px] tracking-[0.16em]"
                            style={{ color: '#9D9A99' }}
                        >
                            NETWORK
                        </span>
                        <div className="flex flex-wrap gap-1.5">
                            {NETS.map((l, i) => (
                                <button
                                    key={l}
                                    type="button"
                                    onClick={() => {
                                        setNet(i);
                                        regen();
                                    }}
                                    aria-pressed={net === i}
                                    className={chip(net === i)}
                                >
                                    {l}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="bg-background text-foreground flex min-h-[210px] flex-col gap-3 rounded-[20px] px-[22px] py-5">
                        <span className="flex items-center justify-between gap-2.5">
                            <span className="text-primary font-mono text-[10px] tracking-[0.16em]">
                                AI DRAFT · {NETS[net].toUpperCase()}
                            </span>
                            {!gen ? (
                                <span className="hm-screen bg-lime flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold">
                                    <Sparkles className="size-3" />
                                    On-brand
                                </span>
                            ) : (
                                <span className="text-primary font-mono text-[11px]">
                                    Thinking<span className="hm-dot-1">.</span>
                                    <span className="hm-dot-2">.</span>
                                    <span className="hm-dot-3">.</span>
                                </span>
                            )}
                        </span>
                        {gen ? (
                            <div className="flex flex-col gap-2.5 py-1.5">
                                <span className="hm-shimmer h-3.5 w-full" />
                                <span className="hm-shimmer h-3.5 w-[92%]" />
                                <span className="hm-shimmer h-3.5 w-[70%]" />
                                <span className="hm-shimmer mt-1.5 h-3 w-2/5" />
                            </div>
                        ) : (
                            <div className="hm-screen flex flex-col gap-2.5">
                                <p className="text-[17px] leading-relaxed">
                                    {draftText}
                                </p>
                                <span className="text-primary font-mono text-xs">
                                    {draftTags}
                                </span>
                            </div>
                        )}
                        <span className="mt-auto flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={regen}
                                className="bg-lime text-ink flex h-11 cursor-pointer items-center gap-2 rounded-full px-[18px] text-sm font-semibold"
                            >
                                <Sparkles className="size-3.5" />
                                Generate another
                            </button>
                            <button
                                type="button"
                                onClick={() => setUsed(true)}
                                className="bg-ink text-cream h-11 cursor-pointer rounded-full px-[18px] text-sm font-semibold"
                            >
                                {used ? 'Added to calendar ✓' : 'Use caption'}
                            </button>
                        </span>
                    </div>
                </div>
            </div>
        </section>
    );
}
