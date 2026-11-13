import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, CirclePlay, Menu, Star, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { ArtworkSvg, BrandMark } from '@/components/brand-mark';
import { markArtwork } from '@/components/brand/artwork';
import { Audiences } from '@/components/marketing/home/audiences';
import { BrandBrain } from '@/components/marketing/home/brand-brain';
import { ChaosCalm } from '@/components/marketing/home/chaos-calm';
import { Faq } from '@/components/marketing/home/faq';
import { FeatureBento } from '@/components/marketing/home/feature-bento';
import { Pricing } from '@/components/marketing/home/pricing';
import { ProductDemo } from '@/components/marketing/home/product-demo';
import { TimeCalculator } from '@/components/marketing/home/time-calculator';
import type { PricingData } from '@/components/marketing/pricing-table';
import { Button } from '@/components/ui/button';
import type { Auth } from '@/types';
import { dashboard, login, register } from '@/routes';

const NAV_LINKS: [string, string][] = [
    ['#tour', 'Product tour'],
    ['#features', 'Features'],
    ['#ai', 'AI'],
    ['#pricing', 'Pricing'],
];

const WORDS = ['post', 'client', 'campaign', 'approval', 'invoice'];

/**
 * The public homepage (served at `/` for the app host). A long, expressive marketing page that runs
 * from a live product demo through the Brand Brain, a time calculator, the feature bento, audiences,
 * pricing and FAQ. Interactivity is client-side React state; motion lives in `hm-*` utilities.
 */
export default function Welcome({ pricing }: { pricing: PricingData }) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [menuOpen, setMenuOpen] = useState(false);
    const [word, setWord] = useState(0);

    useEffect(() => {
        const t = setInterval(
            () => setWord((w) => (w + 1) % WORDS.length),
            2200,
        );
        return () => clearInterval(t);
    }, []);

    const heroCtaRef = useRef<HTMLDivElement>(null);
    const [heroCtaVisible, setHeroCtaVisible] = useState(true);

    useEffect(() => {
        const el = heroCtaRef.current;
        if (!el) return;
        const io = new IntersectionObserver(([entry]) =>
            setHeroCtaVisible(entry.isIntersecting),
        );
        io.observe(el);
        return () => io.disconnect();
    }, []);

    const scrollTo = (id: string) => () =>
        document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });

    return (
        <>
            <Head title="Alima Planner — one calm planner for every post" />
            <div
                id="top"
                className="bg-background text-foreground relative overflow-x-clip"
            >
                {/* ===== HEADER ===== */}
                <header className="bg-background/90 sticky top-0 z-40 border-b backdrop-blur-xl">
                    <div className="mx-auto flex h-14 max-w-[1280px] items-center justify-between gap-6 px-5 md:h-20 md:px-10 lg:px-20">
                        <Link
                            href="/"
                            aria-label="Alima Planner home"
                            className="flex items-center gap-3.5"
                        >
                            <BrandMark size="md" wordmark />
                            <span className="eyebrow text-primary hidden sm:inline">
                                PLANNER
                            </span>
                        </Link>
                        <nav
                            aria-label="Main"
                            className="hidden items-center gap-9 lg:flex"
                        >
                            {NAV_LINKS.map(([href, label]) => (
                                <a
                                    key={href}
                                    href={href}
                                    className="hover:text-primary text-base transition-colors"
                                >
                                    {label}
                                </a>
                            ))}
                        </nav>
                        <div className="flex items-center gap-2">
                            {auth.user ? (
                                <Button
                                    asChild
                                    className="hidden lg:inline-flex"
                                >
                                    <Link href={dashboard()}>
                                        Open planner
                                        <ArrowRight />
                                    </Link>
                                </Button>
                            ) : (
                                <>
                                    <Button
                                        variant="ghost"
                                        asChild
                                        className="hidden lg:inline-flex"
                                    >
                                        <Link href={login()}>Log in</Link>
                                    </Button>
                                    <Button
                                        asChild
                                        className="hidden lg:inline-flex"
                                    >
                                        <Link href={register()}>
                                            Let&apos;s talk
                                        </Link>
                                    </Button>
                                </>
                            )}
                            <button
                                type="button"
                                onClick={() => setMenuOpen((o) => !o)}
                                aria-label={
                                    menuOpen ? 'Close menu' : 'Open menu'
                                }
                                aria-expanded={menuOpen}
                                className="bg-ink text-cream flex size-11 items-center justify-center rounded-full lg:hidden"
                            >
                                {menuOpen ? (
                                    <X className="size-5" />
                                ) : (
                                    <Menu className="size-5" />
                                )}
                            </button>
                        </div>
                    </div>
                    {menuOpen && (
                        <nav
                            aria-label="Mobile"
                            className="hm-screen border-border flex flex-col border-t px-5 pt-2 pb-6 lg:hidden"
                        >
                            {NAV_LINKS.map(([href, label]) => (
                                <a
                                    key={href}
                                    href={href}
                                    onClick={() => setMenuOpen(false)}
                                    className="border-border border-b py-3 text-[28px] font-bold tracking-tight"
                                >
                                    {label}
                                </a>
                            ))}
                            <div className="mt-5 flex gap-2">
                                {auth.user ? (
                                    <Button asChild className="flex-1">
                                        <Link href={dashboard()}>
                                            Open planner
                                            <ArrowRight />
                                        </Link>
                                    </Button>
                                ) : (
                                    <>
                                        <Button
                                            variant="outline"
                                            asChild
                                            className="flex-1"
                                        >
                                            <Link href={login()}>Log in</Link>
                                        </Button>
                                        <Button asChild className="flex-1">
                                            <Link href={register()}>
                                                Let&apos;s talk
                                            </Link>
                                        </Button>
                                    </>
                                )}
                            </div>
                        </nav>
                    )}
                </header>

                {/* ===== HERO ===== */}
                <section className="mx-auto flex max-w-[1280px] flex-col gap-6 px-5 pt-12 pb-10 md:gap-8 md:px-10 md:pt-20 lg:px-20">
                    <span className="eyebrow text-primary flex items-center gap-3.5 tracking-[0.24em]">
                        <span aria-hidden className="bg-signal h-0.5 w-7" />
                        BOLD IDEAS. CALM PLANNING.
                    </span>
                    <h1
                        className="font-display text-[clamp(48px,8.4vw,120px)] leading-[1.04] font-bold tracking-tight"
                        aria-label="One calm planner for every post, client, campaign, approval and invoice."
                    >
                        <span aria-hidden>
                            One calm planner
                            <br />
                            for every{' '}
                            <span className="inline-grid text-left">
                                <span
                                    aria-hidden
                                    className="invisible col-start-1 row-start-1 px-[0.06em] whitespace-nowrap"
                                >
                                    campaign.
                                </span>
                                <span
                                    key={word}
                                    className="hm-word col-start-1 row-start-1 justify-self-start whitespace-nowrap"
                                >
                                    <span className="text-primary bg-[linear-gradient(transparent_56%,var(--lime)_56%,var(--lime)_90%,transparent_90%)] px-[0.06em]">
                                        {WORDS[word]}
                                    </span>
                                    <span className="text-signal">.</span>
                                </span>
                            </span>
                        </span>
                    </h1>
                    <div className="flex flex-wrap items-end justify-between gap-8">
                        <div className="flex max-w-[600px] flex-1 basis-[460px] flex-col gap-6.5">
                            <p
                                className="text-[clamp(18px,1.5vw,21px)] leading-relaxed"
                                style={{ color: '#4A4857' }}
                            >
                                Briefs, calendars, client approvals, publishing
                                to nine networks, reports and invoices — run
                                from one workspace, with AI that knows every
                                brand you manage.
                            </p>
                            <div
                                ref={heroCtaRef}
                                className="flex flex-wrap gap-2.5"
                            >
                                <Button size="xl" variant="spark" asChild>
                                    <Link href={register()}>
                                        Let&apos;s grow
                                        <ArrowRight />
                                    </Link>
                                </Button>
                                <Button
                                    size="xl"
                                    variant="outline"
                                    onClick={scrollTo('tour')}
                                >
                                    <CirclePlay />
                                    Play with the demo
                                </Button>
                            </div>
                        </div>
                        {/* floating live notifications */}
                        <div
                            aria-hidden
                            className="relative hidden h-[230px] flex-[0_0_470px] lg:block"
                        >
                            <div className="hm-toast hm-toast-1 bg-card absolute top-0 left-0 flex items-center gap-3 rounded-[18px] px-4 py-3 text-sm leading-snug shadow-[0_12px_30px_-12px_rgb(24_23_35/0.35)]">
                                <span className="bg-lime flex size-9 items-center justify-center rounded-full">
                                    <svg
                                        width="18"
                                        height="18"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="var(--ink)"
                                        strokeWidth="2.6"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                    >
                                        <path d="M20 6 9 17l-5-5" />
                                    </svg>
                                </span>
                                <span className="flex flex-col">
                                    <b className="font-semibold">
                                        Oro Coffee approved 3 posts
                                    </b>
                                    <span className="text-muted-foreground text-xs">
                                        Client portal · just now
                                    </span>
                                </span>
                            </div>
                            <div className="hm-toast hm-toast-2 bg-ink text-cream absolute top-14 right-0 flex items-center gap-3 rounded-[18px] px-4 py-3 text-sm leading-snug shadow-[0_12px_30px_-12px_rgb(24_23_35/0.5)]">
                                <span className="text-lime font-mono text-xl font-medium">
                                    £2,400
                                </span>
                                <span className="flex flex-col">
                                    <b className="font-semibold">
                                        Invoice paid
                                    </b>
                                    <span
                                        className="text-xs"
                                        style={{ color: '#C9C6C1' }}
                                    >
                                        Northwind · INV-0142
                                    </span>
                                </span>
                            </div>
                            <div className="hm-toast hm-toast-3 bg-card absolute top-[118px] left-10 flex items-center gap-3 rounded-[18px] px-4 py-3 text-sm leading-snug shadow-[0_12px_30px_-12px_rgb(24_23_35/0.35)]">
                                <span className="bg-teal flex size-9 items-center justify-center rounded-full">
                                    <svg
                                        width="16"
                                        height="16"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="var(--cream)"
                                        strokeWidth="2"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                    >
                                        <path d="m22 2-7 20-4-9-9-4Z" />
                                    </svg>
                                </span>
                                <span className="flex flex-col">
                                    <b className="font-semibold">
                                        Reel published to 3 networks
                                    </b>
                                    <span className="text-muted-foreground text-xs">
                                        Kite Bakery · 18:00
                                    </span>
                                </span>
                            </div>
                            <div className="hm-toast hm-toast-4 bg-lilac absolute top-[172px] right-[18px] flex items-center gap-2.5 rounded-[18px] px-3.5 py-2.5 text-sm font-semibold shadow-[0_12px_30px_-12px_rgb(24_23_35/0.35)]">
                                <Star className="size-4" />
                                Brand Brain: 5 ideas ready
                            </div>
                        </div>
                    </div>
                </section>

                <ProductDemo />

                {/* ===== NETWORKS MARQUEE ===== */}
                <section
                    aria-label="Networks Alima publishes to"
                    className="bg-ink text-cream overflow-hidden py-6"
                >
                    <div className="hm-marquee flex w-max gap-14 text-[clamp(22px,2.6vw,34px)] font-bold tracking-tight whitespace-nowrap">
                        {Array.from({ length: 2 }).flatMap((_, rep) =>
                            [
                                'Instagram',
                                'TikTok',
                                'Facebook',
                                'LinkedIn',
                                'YouTube',
                                'Threads',
                                'Pinterest',
                                'X',
                                'Google Business',
                            ].flatMap((name, i) => [
                                <span
                                    key={`${rep}-n-${i}`}
                                    aria-hidden={rep > 0}
                                >
                                    {name}
                                </span>,
                                <span
                                    key={`${rep}-s-${i}`}
                                    aria-hidden
                                    className={
                                        [
                                            'text-lime',
                                            'text-signal',
                                            'text-lilac',
                                        ][i % 3]
                                    }
                                >
                                    ✦
                                </span>,
                            ]),
                        )}
                    </div>
                </section>

                <ChaosCalm />
                <BrandBrain />
                <TimeCalculator />
                <FeatureBento />
                <Audiences />

                {/* ===== PROOF =====
                    PLACEHOLDER (replace with real data): the 4th stat tile and the testimonial below
                    are illustrative examples, not verified figures or a real customer quote. Swap
                    them for real numbers and a genuine, attributable testimonial before relying on
                    them in production. The other three stats are factual and can stay. */}
                <section className="bg-teal text-cream">
                    <div className="mx-auto flex max-w-[1280px] flex-col gap-12 px-5 py-14 md:px-10 md:py-20 lg:px-20 lg:py-24">
                        <div className="grid grid-cols-2 gap-6 lg:grid-cols-4">
                            {(
                                [
                                    ['9', 'networks from one calendar'],
                                    ['0%', 'fee on creator sales'],
                                    ['UK·UAE', 'built for both markets'],
                                    ['6 hrs', 'a week back on approvals'],
                                ] as const
                            ).map(([value, label]) => (
                                <div
                                    key={label}
                                    className="flex min-w-0 flex-col gap-1.5 border-t-2 pt-4"
                                    style={{ borderColor: 'var(--cream)' }}
                                >
                                    <span className="text-lime text-[clamp(40px,5vw,72px)] leading-[0.95] font-bold tracking-tight">
                                        {value}
                                    </span>
                                    <span
                                        className="text-base"
                                        style={{ color: '#E4E9E1' }}
                                    >
                                        {label}
                                    </span>
                                </div>
                            ))}
                        </div>
                        <figure className="flex max-w-[980px] flex-col gap-5.5">
                            <blockquote className="text-[clamp(28px,3.6vw,46px)] leading-tight font-light tracking-tight">
                                “We used to chase sign-off across four group
                                chats. Now every client approves from one link —
                                and we got hours back every week.”
                            </blockquote>
                            <figcaption className="flex items-center gap-3.5 text-base">
                                <span className="bg-lilac size-12 rounded-full" />
                                <span className="flex flex-col leading-snug">
                                    <b className="font-semibold">Maya Karim</b>
                                    <span style={{ color: '#CAD3CB' }}>
                                        Founder, Studio Kite
                                    </span>
                                </span>
                            </figcaption>
                        </figure>
                    </div>
                </section>

                <Pricing pricing={pricing} onTalk={scrollTo('talk')} />
                <Faq onTalk={scrollTo('talk')} />

                {/* ===== FINAL CTA ===== */}
                <section
                    id="talk"
                    className="scroll-mt-24 px-3 pt-6 pb-10 md:px-12 md:pt-12 md:pb-16"
                >
                    <div className="hm-cta-box bg-ink text-cream relative mx-auto flex max-w-[1344px] flex-col gap-6 overflow-hidden rounded-[36px] px-6 pt-10 pb-44 md:px-16 md:pt-16 md:pb-52 lg:px-24 lg:pt-24 lg:pb-56">
                        <span className="eyebrow text-lime flex items-center gap-3.5 tracking-[0.24em]">
                            <span aria-hidden className="bg-signal h-0.5 w-7" />
                            READY WHEN YOU ARE
                        </span>
                        <h2 className="font-display max-w-[900px] text-[clamp(52px,8vw,120px)] leading-[0.95] font-bold tracking-tight">
                            Marketing brighter together
                            <span className="text-signal">.</span>
                        </h2>
                        <div className="relative z-10 flex flex-wrap gap-2.5">
                            <Button size="xl" variant="spark" asChild>
                                <Link href={register()}>
                                    Let&apos;s talk
                                    <ArrowRight />
                                </Link>
                            </Button>
                            {!auth.user && (
                                <Button size="xl" variant="on-ink" asChild>
                                    <Link href={login()}>Log in</Link>
                                </Button>
                            )}
                        </div>
                        {/* The peek: the panda bleeding up over the bottom edge, eyes in frame.
                            The wrapper does the bleed (translate-y); the artwork carries the
                            hover-rise (hm-peek) so the two transforms don't collide. */}
                        <div
                            aria-hidden
                            className="pointer-events-none absolute right-2 bottom-0 w-[clamp(260px,38vw,520px)] translate-y-[32%] md:right-10"
                        >
                            <ArtworkSvg
                                artwork={markArtwork}
                                className="hm-peek text-signal block h-auto w-full"
                            />
                        </div>
                    </div>
                </section>

                {/* ===== FOOTER ===== */}
                <footer className="bg-ink text-cream">
                    <div aria-hidden className="bg-spectrum h-1.5" />
                    <div className="mx-auto flex max-w-[1280px] flex-col gap-10 px-5 pt-13 pb-28 md:px-10 lg:px-20 lg:pb-8">
                        <div className="flex flex-wrap justify-between gap-10">
                            <div className="flex max-w-[360px] flex-1 basis-[260px] flex-col gap-4">
                                <BrandMark
                                    size="lg"
                                    wordmark
                                    className="text-cream"
                                />
                                <span
                                    className="text-[15px]"
                                    style={{ color: '#C9C6C1' }}
                                >
                                    Clients, campaigns, content, approvals,
                                    reports and invoices — in one calm planner.
                                </span>
                            </div>
                            <div className="flex flex-wrap gap-8 text-[15px] md:gap-16 lg:gap-20">
                                <div className="flex flex-col gap-2.5">
                                    <span
                                        className="font-mono text-[11px] tracking-[0.2em]"
                                        style={{ color: '#9D9A99' }}
                                    >
                                        PRODUCT
                                    </span>
                                    <a
                                        href="#tour"
                                        className="text-cream/90 hover:text-cream"
                                    >
                                        Product tour
                                    </a>
                                    <a
                                        href="#features"
                                        className="text-cream/90 hover:text-cream"
                                    >
                                        Features
                                    </a>
                                    <a
                                        href="#pricing"
                                        className="text-cream/90 hover:text-cream"
                                    >
                                        Pricing
                                    </a>
                                </div>
                                <div className="flex flex-col gap-2.5">
                                    <span
                                        className="font-mono text-[11px] tracking-[0.2em]"
                                        style={{ color: '#9D9A99' }}
                                    >
                                        ALIMA
                                    </span>
                                    <a
                                        href="https://prospects.alima.digital"
                                        className="text-cream/90 hover:text-cream"
                                    >
                                        Alima Prospects
                                    </a>
                                    <a
                                        href="#talk"
                                        className="text-cream/90 hover:text-cream"
                                    >
                                        Let&apos;s talk
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div
                            className="eyebrow border-cream/15 flex flex-wrap justify-between gap-3 border-t pt-5 tracking-[0.2em]"
                            style={{ color: '#9D9A99' }}
                        >
                            <span>BOLD IDEAS. REAL IMPACT.</span>
                            <span>© {new Date().getFullYear()} ALIMA</span>
                        </div>
                    </div>
                </footer>

                {/* ===== MOBILE STICKY CTA =====
                    Slides in once the hero CTAs have scrolled away, so the two never double up. */}
                <div
                    aria-hidden={heroCtaVisible}
                    inert={heroCtaVisible}
                    className={`bg-background/95 border-border fixed inset-x-0 bottom-0 z-[35] flex gap-2 border-t px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur-md transition-transform duration-300 lg:hidden ${heroCtaVisible ? 'translate-y-full' : 'translate-y-0'}`}
                >
                    {auth.user ? (
                        <Button variant="spark" className="flex-1" asChild>
                            <Link href={dashboard()}>
                                Open planner
                                <ArrowRight />
                            </Link>
                        </Button>
                    ) : (
                        <>
                            <Button variant="outline" asChild>
                                <Link href={login()}>Log in</Link>
                            </Button>
                            <Button variant="spark" className="flex-1" asChild>
                                <Link href={register()}>
                                    Let&apos;s grow
                                    <ArrowRight />
                                </Link>
                            </Button>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}
