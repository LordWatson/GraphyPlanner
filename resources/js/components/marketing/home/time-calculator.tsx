import { useState } from 'react';

import { Eyebrow } from '@/components/page';

/**
 * "Time back" calculator — four sliders (clients, posts per client, minutes saved per post, value
 * of an hour) feed a live estimate of hours, money and days saved each month.
 */
export function TimeCalculator() {
    const [clients, setClients] = useState(8);
    const [posts, setPosts] = useState(16);
    const [mins, setMins] = useState(12);
    const [rate, setRate] = useState(45);

    const sliders = [
        {
            label: 'Clients you manage',
            min: 1,
            max: 40,
            step: 1,
            value: clients,
            set: setClients,
            display: String(clients),
        },
        {
            label: 'Posts per client, per month',
            min: 4,
            max: 60,
            step: 1,
            value: posts,
            set: setPosts,
            display: String(posts),
        },
        {
            label: 'Minutes saved per post',
            min: 3,
            max: 30,
            step: 1,
            value: mins,
            set: setMins,
            display: `${mins} min`,
        },
        {
            label: 'Value of an hour',
            min: 15,
            max: 150,
            step: 5,
            value: rate,
            set: setRate,
            display: `£${rate}`,
        },
    ];

    const hours = Math.round((clients * posts * mins) / 60);
    const worth = `£${(hours * rate).toLocaleString('en-GB')}`;
    const days = (hours / 8).toFixed(1);

    return (
        <section className="mx-auto flex max-w-[1280px] flex-col gap-9 px-5 py-16 md:px-10 md:py-24 lg:px-20 lg:py-28">
            <div className="hm-reveal flex max-w-[860px] flex-col gap-5">
                <Eyebrow dash className="text-primary">
                    TIME BACK CALCULATOR
                </Eyebrow>
                <h2 className="font-display text-[clamp(40px,5.6vw,76px)] leading-none font-bold tracking-tight">
                    How much time is the chasing costing you
                    <span className="text-signal">?</span>
                </h2>
            </div>

            <div className="hm-reveal grid grid-cols-1 gap-3 md:grid-cols-2">
                <div className="bg-ink text-cream flex flex-col gap-6 rounded-[28px] p-6 md:p-10">
                    {sliders.map((s) => (
                        <label key={s.label} className="flex flex-col gap-3">
                            <span className="flex items-baseline justify-between gap-3">
                                <span className="text-base font-medium">
                                    {s.label}
                                </span>
                                <span className="text-lime text-[26px] font-bold tracking-tight">
                                    {s.display}
                                </span>
                            </span>
                            <input
                                className="hm-range"
                                type="range"
                                min={s.min}
                                max={s.max}
                                step={s.step}
                                value={s.value}
                                onChange={(e) => s.set(Number(e.target.value))}
                                aria-label={s.label}
                            />
                        </label>
                    ))}
                    <span
                        className="text-[13px] leading-relaxed"
                        style={{ color: '#9D9A99' }}
                    >
                        Estimate only. Minutes saved covers planning, chasing
                        approvals and scheduling by hand — adjust it to match
                        your studio.
                    </span>
                </div>
                <div className="bg-lime text-ink relative flex flex-col justify-between gap-6 overflow-hidden rounded-[28px] p-6 md:p-10">
                    <span className="font-mono text-xs tracking-[0.2em]">
                        EVERY MONTH, YOU GET BACK
                    </span>
                    <span className="flex flex-wrap items-baseline gap-3">
                        <span className="text-[clamp(88px,11vw,160px)] leading-[0.85] font-bold tracking-tighter">
                            {hours}
                        </span>
                        <span className="text-[26px] font-bold">hours</span>
                    </span>
                    <div
                        className="grid grid-cols-2 gap-4 border-t-2 pt-4.5"
                        style={{ borderColor: 'var(--ink)' }}
                    >
                        <span className="flex flex-col">
                            <span className="font-mono text-[11px] tracking-[0.16em]">
                                WORTH
                            </span>
                            <b className="text-[30px] font-bold tracking-tight">
                                {worth}
                            </b>
                        </span>
                        <span className="flex flex-col">
                            <span className="font-mono text-[11px] tracking-[0.16em]">
                                THAT’S
                            </span>
                            <b className="text-[30px] font-bold tracking-tight">
                                {days} days
                            </b>
                        </span>
                    </div>
                    <span className="text-[15px] leading-relaxed">
                        Across {clients} clients and {clients * posts} posts a
                        month — time you could spend on the work, not chasing
                        it.
                    </span>
                </div>
            </div>
        </section>
    );
}
