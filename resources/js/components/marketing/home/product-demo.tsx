import { useEffect, useMemo, useRef, useState } from 'react';

import { BrandPattern } from '@/components/brand-poster';

/**
 * The live product demo on the homepage — a self-contained, clickable mock of the planner the
 * marketing page lets visitors drive. Seven screens, an auto-tour, approve/request-changes with a
 * confetti finish, a post drawer and in-app toasts. Ported from the design canvas; brand colours
 * are referenced as tokens (`var(--…)`) so it still obeys the palette even though the styling is
 * computed per row.
 */

// Brand tokens as values usable inside computed inline styles.
const C = {
    teal: 'var(--teal)',
    ink: 'var(--ink)',
    cream: 'var(--cream)',
    lime: 'var(--lime)',
    signal: 'var(--signal)',
    lilac: 'var(--lilac)',
    electric: 'var(--electric)',
    card: 'var(--card)',
    canvas: 'var(--background)',
    secondary: 'var(--secondary)',
    border: 'var(--border)',
    muted: 'var(--muted-foreground)',
    success: 'var(--success)',
    danger: 'var(--destructive)',
    info: 'var(--info)',
} as const;

const SCREEN_ORDER = [
    'home',
    'calendar',
    'posts',
    'campaigns',
    'analytics',
    'invoices',
    'clients',
] as const;
type Screen = (typeof SCREEN_ORDER)[number];

// status -> [text colour, tint background]
const STATUS: Record<string, [string, string]> = {
    Published: [C.success, '#E3EFE6'],
    Scheduled: [C.teal, '#DCE6E2'],
    'Internal review': [C.info, '#E2ECF7'],
    'Waiting on client': ['#6E3F86', '#F1E8F5'],
    Draft: [C.muted, '#ECE8DB'],
    Archived: [C.muted, '#F0ECE1'],
    Approved: [C.ink, C.lime],
    'Changes requested': [C.danger, '#F8E3E0'],
};
const NEED = ['Internal review', 'Waiting on client', 'Draft'];
const DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const dayLabel = (d: number) => `${DOW[(d - 1) % 7]} ${d} Nov`;
type Post = {
    id: number;
    day: number;
    time: string;
    client: string;
    account: string;
    text: string;
    status: string;
    platform: string;
};

const BASE: Post[] = [
    {
        id: 1,
        day: 2,
        time: '10:00',
        client: 'Northwind',
        account: '@northwind',
        text: 'Spring drop teaser — almost here',
        status: 'Published',
        platform: 'INSTAGRAM',
    },
    {
        id: 2,
        day: 3,
        time: '12:15',
        client: 'Oro Coffee',
        account: 'orocoffee.ae',
        text: 'Every bag starts on a hillside',
        status: 'Published',
        platform: 'INSTAGRAM',
    },
    {
        id: 3,
        day: 4,
        time: '17:30',
        client: 'Fable & Co',
        account: 'fable.co',
        text: 'Five rooms, one palette',
        status: 'Published',
        platform: 'LINKEDIN',
    },
    {
        id: 4,
        day: 5,
        time: '19:00',
        client: 'Lumen Studio',
        account: '@lumen.studio',
        text: 'Behind the lens: golden hour',
        status: 'Archived',
        platform: 'TIKTOK',
    },
    {
        id: 5,
        day: 6,
        time: '14:30',
        client: 'Northwind',
        account: '@northwind',
        text: 'Meet the makers',
        status: 'Published',
        platform: 'FACEBOOK',
    },
    {
        id: 6,
        day: 8,
        time: '11:30',
        client: 'Oro Coffee',
        account: 'orocoffee.ae',
        text: 'Behind the roast',
        status: 'Scheduled',
        platform: 'REEL',
    },
    {
        id: 7,
        day: 9,
        time: '10:00',
        client: 'Fable & Co',
        account: 'fable.co',
        text: 'Where shall we go next?',
        status: 'Waiting on client',
        platform: 'CAROUSEL',
    },
    {
        id: 8,
        day: 9,
        time: '16:30',
        client: 'Kite Bakery',
        account: '@kitebakery',
        text: 'Croissant Friday is back',
        status: 'Internal review',
        platform: 'STORY',
    },
    {
        id: 9,
        day: 10,
        time: '20:15',
        client: 'Lumen Studio',
        account: '@lumen.studio',
        text: 'Studio tour, part two',
        status: 'Internal review',
        platform: 'TIKTOK',
    },
    {
        id: 10,
        day: 11,
        time: '12:00',
        client: 'Northwind',
        account: '@northwind',
        text: 'Launch day — it’s here',
        status: 'Waiting on client',
        platform: 'REEL',
    },
    {
        id: 11,
        day: 12,
        time: '09:30',
        client: 'Oro Coffee',
        account: 'Google Business',
        text: 'Cupping notes: Ethiopia',
        status: 'Draft',
        platform: 'UPDATE',
    },
    {
        id: 12,
        day: 13,
        time: '18:00',
        client: 'Kite Bakery',
        account: '@kitebakery',
        text: 'Weekend specials',
        status: 'Scheduled',
        platform: 'POST',
    },
    {
        id: 13,
        day: 16,
        time: '13:15',
        client: 'Fable & Co',
        account: 'fable.co',
        text: 'Client story: the Marina flat',
        status: 'Draft',
        platform: 'LINKEDIN',
    },
    {
        id: 14,
        day: 18,
        time: '17:00',
        client: 'Northwind',
        account: '@northwind',
        text: 'Sustain: customer roundup',
        status: 'Internal review',
        platform: 'CAROUSEL',
    },
    {
        id: 15,
        day: 20,
        time: '12:30',
        client: 'Lumen Studio',
        account: '@lumen.studio',
        text: 'Portrait week',
        status: 'Waiting on client',
        platform: 'REEL',
    },
    {
        id: 16,
        day: 24,
        time: '10:00',
        client: 'Oro Coffee',
        account: 'orocoffee.ae',
        text: 'Black Friday bundles',
        status: 'Internal review',
        platform: 'POST',
    },
];

const HEALTH: Record<string, [string, string]> = {
    'On track': [C.success, '#E3EFE6'],
    Watch: ['#8A5A00', '#F6EBD3'],
    'At risk': [C.danger, '#F8E3E0'],
};
const CLIENTS = [
    {
        name: 'Northwind',
        initials: 'NW',
        brands: '2 brands',
        retainer: '£2,400',
        posts: '14 posts',
        next: 'Wed 11 · 12:00',
        health: 'On track',
        avatar: { background: C.teal, color: C.cream },
    },
    {
        name: 'Oro Coffee',
        initials: 'OC',
        brands: '1 brand',
        retainer: 'AED 9,500',
        posts: '12 posts',
        next: 'Today · 11:30',
        health: 'On track',
        avatar: { background: C.lime, color: C.ink },
    },
    {
        name: 'Fable & Co',
        initials: 'FC',
        brands: '3 brands',
        retainer: '£1,800',
        posts: '8 posts',
        next: 'Mon 9 · 10:00',
        health: 'Watch',
        avatar: { background: C.lilac, color: C.ink },
    },
    {
        name: 'Lumen Studio',
        initials: 'LS',
        brands: '2 brands',
        retainer: '£1,350',
        posts: '6 posts',
        next: 'Tue 10 · 20:15',
        health: 'At risk',
        avatar: { background: C.electric, color: C.ink },
    },
    {
        name: 'Kite Bakery',
        initials: 'KB',
        brands: '1 brand',
        retainer: '£950',
        posts: '9 posts',
        next: 'Mon 9 · 16:30',
        health: 'On track',
        avatar: { background: C.ink, color: C.cream },
    },
];

const CAMPS = [
    {
        code: 'SPR26',
        client: 'NORTHWIND',
        name: 'Spring launch',
        phase: 'Launch',
        pIdx: 1,
        state: 'Live · 12 days left',
        st: 'Published',
        ready: 86,
        kpis: [
            ['READINESS', '86%', 'briefed and planned'],
            ['DELIVERABLES', '18/24', '6 in production'],
            ['REACH VS TARGET', '112%', 'ahead of plan'],
        ],
        next: [
            'Approve launch-day reel',
            'Book creator collab for Sustain',
            'Draft mid-campaign client update',
        ],
    },
    {
        code: 'OROBF',
        client: 'ORO COFFEE',
        name: 'Black Friday bundles',
        phase: 'Teaser',
        pIdx: 0,
        state: 'Starts 20 Nov',
        st: 'Scheduled',
        ready: 54,
        kpis: [
            ['READINESS', '54%', 'plan in progress'],
            ['DELIVERABLES', '7/16', 'AI drafts ready'],
            ['BUDGET', 'AED 6K', 'boosting set'],
        ],
        next: [
            'Sign off the brief',
            'Shoot bundle flat-lays',
            'Schedule teaser countdown',
        ],
    },
    {
        code: 'FBLWN',
        client: 'FABLE & CO',
        name: 'Winter lookbook',
        phase: 'Brief',
        pIdx: -1,
        state: 'Waiting on client',
        st: 'Waiting on client',
        ready: 20,
        kpis: [
            ['READINESS', '20%', 'brief with client'],
            ['DELIVERABLES', '0/12', 'not started'],
            ['LAUNCH', '1 Dec', 'on schedule'],
        ],
        next: [
            'Chase brief sign-off',
            'Moodboard review',
            'Assign photographer',
        ],
    },
] as const;

const INV_STATUS: Record<string, [string, string]> = {
    Paid: [C.success, '#E3EFE6'],
    Sent: [C.info, '#E2ECF7'],
    Overdue: [C.danger, '#F8E3E0'],
    Draft: [C.muted, '#ECE8DB'],
};
const INVOICES = [
    {
        no: 'INV-0142',
        client: 'Northwind',
        amount: '£2,400',
        gbp: 2400,
        status: 'Paid',
    },
    {
        no: 'INV-0143',
        client: 'Oro Coffee',
        amount: 'AED 9,500',
        gbp: 0,
        status: 'Sent',
    },
    {
        no: 'INV-0144',
        client: 'Fable & Co',
        amount: '£1,800',
        gbp: 1800,
        status: 'Overdue',
    },
    {
        no: 'INV-0145',
        client: 'Lumen Studio',
        amount: '£1,350',
        gbp: 1350,
        status: 'Paid',
    },
    {
        no: 'INV-0146',
        client: 'Kite Bakery',
        amount: '£950',
        gbp: 950,
        status: 'Draft',
    },
];

const NAV: [Screen, string, string][] = [
    ['home', 'Home', '01'],
    ['calendar', 'Calendar', '02'],
    ['posts', 'Posts', '03'],
    ['campaigns', 'Campaigns', '··'],
    ['clients', 'Clients', '05'],
    ['invoices', 'Invoices', '06'],
    ['analytics', 'Analytics', '07'],
];
const LABELS: Record<Screen, string> = {
    home: 'Home',
    calendar: 'Calendar',
    posts: 'Posts',
    campaigns: 'Campaigns',
    clients: 'Clients',
    invoices: 'Invoices',
    analytics: 'Analytics',
};
const CAPTIONS: Record<Screen, string> = {
    home: 'What’s blocked, what’s going out and how every client is tracking — the moment you log in.',
    calendar:
        'Every client and network on one calendar. Click any post to open it, then approve it right there.',
    posts: 'The decision queue. Approve, send back — or hit “Approve all” and enjoy the moment.',
    campaigns:
        'Pick a campaign to see its phases, numbers and next steps. Tick a task off.',
    analytics:
        'Revenue, reliability and insights in plain words. Click the legend to toggle lines.',
    invoices:
        'Bill in GBP or AED beside the work. Send the draft or nudge the overdue one.',
    clients:
        'Your whole roster with retainers, next posts and a health signal for each.',
};

const Check = ({
    size = 14,
    color = C.cream,
    w = 2,
}: {
    size?: number;
    color?: string;
    w?: number;
}) => (
    <svg
        width={size}
        height={size}
        viewBox="0 0 24 24"
        fill="none"
        stroke={color}
        strokeWidth={w}
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
    >
        <path d="M20 6 9 17l-5-5" />
    </svg>
);

export function ProductDemo() {
    const [screen, setScreen] = useState<Screen>('home');
    const [autoplay, setAutoplay] = useState(true);
    const [statuses, setStatuses] = useState<Record<number, string>>({});
    const [sel, setSel] = useState<number | null>(null);
    const [filter, setFilter] = useState('all');
    const [camp, setCamp] = useState(0);
    const [ticks, setTicks] = useState<Record<string, boolean>>({});
    const [invState, setInvState] = useState<Record<string, string>>({});
    const [seriesOff, setSeriesOff] = useState<Record<string, boolean>>({});
    const [toast, setToast] = useState<string | null>(null);
    const toastTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    // refs so the auto-tour interval reads live values without re-subscribing
    const autoplayRef = useRef(autoplay);
    const selRef = useRef(sel);
    autoplayRef.current = autoplay;
    selRef.current = sel;

    useEffect(() => {
        const t = setInterval(() => {
            setScreen((s) => {
                // stop auto-advancing once the viewer takes over
                if (!autoplayRef.current || selRef.current !== null) return s;
                const i = SCREEN_ORDER.indexOf(s);
                return SCREEN_ORDER[(i + 1) % SCREEN_ORDER.length];
            });
        }, 5500);
        return () => clearInterval(t);
    }, []);

    // keep the active tab centred in the mobile tab strip (the auto-tour moves it off-screen);
    // scrolls the strip only, never the page
    const mobileNavRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const nav = mobileNavRef.current;
        const tab = nav?.querySelector<HTMLElement>('[aria-current="true"]');
        if (nav && tab) {
            nav.scrollTo({
                left: tab.offsetLeft - (nav.clientWidth - tab.offsetWidth) / 2,
                behavior: 'smooth',
            });
        }
    }, [screen]);

    useEffect(
        () => () => {
            if (toastTimer.current) clearTimeout(toastTimer.current);
        },
        [],
    );

    const flash = (msg: string) => {
        if (toastTimer.current) clearTimeout(toastTimer.current);
        setToast(msg);
        toastTimer.current = setTimeout(() => setToast(null), 2600);
    };
    const go = (k: Screen) => () => {
        setScreen(k);
        setAutoplay(false);
        setSel(null);
    };
    const stop = () => setAutoplay(false);

    const events = useMemo(
        () =>
            BASE.map((e) => {
                const status = statuses[e.id] ?? e.status;
                return {
                    ...e,
                    status,
                    dayLabel: dayLabel(e.day),
                    canAct: NEED.includes(status),
                };
            }),
        [statuses],
    );
    const needsList = events.filter((e) => NEED.includes(e.status));
    const needs = needsList.length;

    const setStatus = (id: number, st: string, client: string) => {
        setStatuses((prev) => ({ ...prev, [id]: st }));
        setAutoplay(false);
        flash(
            st === 'Approved'
                ? `Approved · ${client} notified`
                : 'Sent back with your notes',
        );
    };

    const now = new Date().getHours();
    const greeting =
        now < 12
            ? 'Good morning'
            : now < 18
              ? 'Good afternoon'
              : 'Good evening';
    const scheduledCount =
        18 + Object.values(statuses).filter((s) => s === 'Approved').length;
    const stats = [
        { label: 'NEXT 24 HOURS', value: '3', note: 'POSTS GOING OUT' },
        {
            label: 'UNAPPROVED',
            value: String(needs),
            note: 'DRAFTS AWAITING SIGN-OFF',
        },
        { label: 'ACTIVE CLIENTS', value: '5', note: '9 BRANDS ON THE ROSTER' },
        {
            label: 'SCHEDULED',
            value: String(scheduledCount),
            note: 'POSTS IN THE PIPELINE',
        },
    ];

    const calCells = Array.from({ length: 35 }, (_, i) => {
        const inMonth = i < 30;
        const d = inMonth ? i + 1 : i - 29;
        const isToday = inMonth && d === 8;
        return {
            d,
            inMonth,
            isToday,
            events: inMonth ? events.filter((e) => e.day === d) : [],
        };
    });
    const agenda = events.filter((e) => e.day >= 8 && e.day <= 14);

    const filterDefs: [string, string][] = [
        ['all', 'All'],
        ['Internal review', 'Internal review'],
        ['Waiting on client', 'With client'],
        ['Draft', 'Drafts'],
    ];
    const touched = (e: Post) => statuses[e.id] !== undefined;
    const queue = events.filter(
        (e) =>
            (NEED.includes(e.status) || touched(e)) &&
            (filter === 'all' || e.status === filter),
    );
    const confettiColors = [
        C.signal,
        C.lime,
        C.lilac,
        C.electric,
        C.teal,
        C.ink,
    ];

    const cc = CAMPS[camp];
    const paid = INVOICES.map((v) => ({
        ...v,
        status: invState[v.no] ?? v.status,
    }))
        .filter((v) => v.status === 'Paid')
        .reduce((a, v) => a + v.gbp, 0);
    const selE = events.find((e) => e.id === sel) ?? null;

    const screenPath = screen === 'home' ? 'dashboard' : screen;

    return (
        <section
            id="tour"
            aria-label="Interactive product demo"
            className="relative scroll-mt-24 px-3 pb-12 sm:px-6 md:px-12 md:pb-20"
        >
            {/* squiggle wash behind the vignette — the Alima squiggle (ink on teal), bottom half */}
            <BrandPattern
                variant="squiggle"
                pair="ink-teal"
                className="absolute inset-x-0 bottom-0 h-[60%]"
            />
            <div className="relative mx-auto max-w-[1344px]">
                <div
                    className="overflow-hidden rounded-[28px] border"
                    style={{
                        background: C.card,
                        borderColor: 'rgb(24 23 35 / 0.08)',
                        boxShadow: '0 30px 80px -30px rgb(24 23 35 / 0.55)',
                    }}
                >
                    {/* browser chrome */}
                    <div
                        className="hidden h-12 items-center justify-between border-b px-[18px] md:flex"
                        style={{
                            borderColor: C.secondary,
                            background: C.canvas,
                        }}
                    >
                        <span aria-hidden className="flex gap-[7px]">
                            {[0, 1, 2].map((i) => (
                                <span
                                    key={i}
                                    className="size-[11px] rounded-full"
                                    style={{ background: '#D9D4C4' }}
                                />
                            ))}
                        </span>
                        <span
                            className="rounded-full px-[18px] py-[5px] font-mono text-xs"
                            style={{ color: C.muted, background: C.secondary }}
                        >
                            planner.alima.digital/{screenPath}
                        </span>
                        <button
                            type="button"
                            onClick={() => setAutoplay((a) => !a)}
                            aria-pressed={autoplay}
                            className="flex min-h-8 cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5 font-mono text-[11px] tracking-[0.16em]"
                            style={{
                                color: C.ink,
                                borderColor: '#D9D4C4',
                                background: 'transparent',
                            }}
                        >
                            <span
                                className="hm-pulse size-2 rounded-full"
                                style={{
                                    background: autoplay
                                        ? '#5BBD7F'
                                        : '#A9A6A0',
                                }}
                            />
                            {autoplay ? 'AUTO-TOUR ON' : 'AUTO-TOUR OFF'}
                        </button>
                    </div>

                    <div className="flex" style={{ background: C.canvas }}>
                        {/* sidebar */}
                        <aside
                            className="hidden w-[232px] shrink-0 flex-col gap-[3px] overflow-hidden px-[14px] pt-[22px] pb-4 md:flex"
                            style={{ background: C.teal, color: C.cream }}
                        >
                            <div className="flex flex-col gap-1.5 px-2.5 pb-[18px]">
                                <span
                                    className="font-display text-lg font-semibold"
                                    style={{ color: C.cream }}
                                >
                                    Alima
                                </span>
                                <span
                                    className="font-mono text-[10px] tracking-[0.2em]"
                                    style={{ color: '#CAD3CB' }}
                                >
                                    PLANNER
                                </span>
                            </div>
                            <span
                                className="px-3 pt-2 pb-1.5 font-mono text-[10px] tracking-[0.2em]"
                                style={{ color: '#A0B3AE' }}
                            >
                                STUDIO
                            </span>
                            {NAV.map(([k, label, num]) => {
                                const on = screen === k;
                                const sub = k === 'posts' || k === 'campaigns';
                                return (
                                    <button
                                        key={k}
                                        type="button"
                                        onClick={go(k)}
                                        className="flex w-full cursor-pointer items-center justify-between rounded-full text-left text-sm"
                                        style={{
                                            padding: sub
                                                ? '8px 12px 8px 30px'
                                                : '9px 12px',
                                            background: on
                                                ? C.lime
                                                : 'transparent',
                                            color: on ? C.ink : C.cream,
                                            fontWeight: on ? 600 : 400,
                                        }}
                                    >
                                        <span>{label}</span>
                                        <span className="font-mono text-[10px] opacity-70">
                                            {num}
                                        </span>
                                    </button>
                                );
                            })}
                            <span
                                className="px-3 pt-4 pb-1.5 font-mono text-[10px] tracking-[0.2em]"
                                style={{ color: '#A0B3AE' }}
                            >
                                TEAM
                            </span>
                            <span
                                className="flex justify-between px-3 py-2 text-sm"
                                style={{ color: '#E4E9E1' }}
                            >
                                Staff
                                <span className="font-mono text-[10px] opacity-70">
                                    08
                                </span>
                            </span>
                            <span
                                className="flex justify-between px-3 py-2 text-sm"
                                style={{ color: '#E4E9E1' }}
                            >
                                Settings
                                <span className="font-mono text-[10px] opacity-70">
                                    09
                                </span>
                            </span>
                            <div className="mt-auto flex flex-col gap-3 px-2.5 pt-5">
                                <span
                                    className="font-mono text-[9px] leading-[1.6] tracking-[0.16em]"
                                    style={{ color: '#A0B3AE' }}
                                >
                                    MARKETING BRIGHTER
                                    <br />
                                    TOGETHER.
                                </span>
                                <span
                                    className="flex items-center gap-2.5 rounded-full px-2.5 py-2 text-[13px] font-medium"
                                    style={{ background: '#1A4547' }}
                                >
                                    <span
                                        className="flex size-[26px] items-center justify-center rounded-full font-mono text-[10px]"
                                        style={{
                                            background: C.cream,
                                            color: C.teal,
                                        }}
                                    >
                                        MK
                                    </span>
                                    Maya Karim
                                </span>
                            </div>
                        </aside>

                        {/* main column */}
                        <div
                            className="relative flex min-w-0 flex-1 flex-col"
                            style={{ background: C.canvas }}
                        >
                            {/* top bar */}
                            <div
                                className="flex h-[60px] shrink-0 items-center justify-between gap-3 border-b px-[22px]"
                                style={{ borderColor: C.secondary }}
                            >
                                <span className="flex items-center gap-3.5 text-sm font-medium">
                                    <svg
                                        width="18"
                                        height="18"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke={C.muted}
                                        strokeWidth="2"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        aria-hidden="true"
                                    >
                                        <rect
                                            x="3"
                                            y="3"
                                            width="18"
                                            height="18"
                                            rx="2"
                                        />
                                        <path d="M9 3v18" />
                                    </svg>
                                    <span
                                        className="h-[18px] w-px"
                                        style={{ background: C.secondary }}
                                    />
                                    {LABELS[screen]}
                                </span>
                                <span className="flex items-center gap-2.5">
                                    <button
                                        type="button"
                                        onClick={go('posts')}
                                        className="flex h-[34px] cursor-pointer items-center gap-1.5 rounded-full px-3.5 text-[13px] font-semibold"
                                        style={{
                                            background: C.teal,
                                            color: C.cream,
                                        }}
                                    >
                                        + New
                                    </button>
                                    <span
                                        className="hidden h-[34px] w-[210px] items-center gap-2 rounded-full border pr-2 pl-3 text-[13px] lg:flex"
                                        style={{
                                            borderColor: C.secondary,
                                            background: C.card,
                                            color: C.muted,
                                        }}
                                    >
                                        <svg
                                            width="15"
                                            height="15"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth="2"
                                            strokeLinecap="round"
                                            aria-hidden="true"
                                        >
                                            <circle cx="11" cy="11" r="7" />
                                            <path d="m21 21-4.3-4.3" />
                                        </svg>
                                        Search or jump to…
                                        <span
                                            className="ml-auto rounded-md border px-1.5 py-0.5 font-mono text-[10px]"
                                            style={{ borderColor: C.secondary }}
                                        >
                                            Ctrl K
                                        </span>
                                    </span>
                                    <span
                                        className="relative flex size-[34px] items-center justify-center rounded-full border"
                                        style={{
                                            borderColor: C.secondary,
                                            background: C.card,
                                        }}
                                    >
                                        <svg
                                            width="16"
                                            height="16"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke={C.ink}
                                            strokeWidth="2"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            aria-hidden="true"
                                        >
                                            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
                                            <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
                                        </svg>
                                        <span
                                            className="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full px-[3px] font-mono text-[9px]"
                                            style={{
                                                background: C.teal,
                                                color: C.cream,
                                            }}
                                        >
                                            {needs}
                                        </span>
                                    </span>
                                </span>
                            </div>

                            {/* mobile nav */}
                            <div
                                ref={mobileNavRef}
                                className="relative flex gap-1.5 overflow-x-auto border-b px-3.5 py-2.5 md:hidden"
                                style={{
                                    borderColor: C.secondary,
                                    background: C.teal,
                                    scrollbarWidth: 'none',
                                }}
                            >
                                {NAV.map(([k, label]) => (
                                    <button
                                        key={k}
                                        type="button"
                                        onClick={go(k)}
                                        aria-current={screen === k}
                                        className="h-9 shrink-0 cursor-pointer rounded-full px-3.5 text-[13px] font-semibold"
                                        style={{
                                            background:
                                                screen === k
                                                    ? C.lime
                                                    : 'rgb(245 242 232 / 0.12)',
                                            color:
                                                screen === k ? C.ink : C.cream,
                                        }}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>

                            {/* screen body */}
                            <div className="relative h-[min(560px,70svh)] overflow-y-auto md:h-[690px]">
                                {screen === 'home' && (
                                    <HomeScreen
                                        greeting={greeting}
                                        needs={needs}
                                        stats={stats}
                                        queuePreview={needsList.slice(0, 4)}
                                        healthPreview={CLIENTS.slice(0, 4)}
                                        goPosts={go('posts')}
                                        goCalendar={go('calendar')}
                                    />
                                )}
                                {screen === 'calendar' && (
                                    <CalendarScreen
                                        calCells={calCells}
                                        agenda={agenda}
                                        onOpen={(id) => {
                                            setSel(id);
                                            setAutoplay(false);
                                        }}
                                    />
                                )}
                                {screen === 'posts' && (
                                    <PostsScreen
                                        queue={queue}
                                        filter={filter}
                                        filterDefs={filterDefs}
                                        needs={needs}
                                        confettiColors={confettiColors}
                                        onFilter={(k) => {
                                            setFilter(k);
                                            stop();
                                        }}
                                        onApprove={(e) =>
                                            setStatus(
                                                e.id,
                                                'Approved',
                                                e.client,
                                            )
                                        }
                                        onChanges={(e) =>
                                            setStatus(
                                                e.id,
                                                'Changes requested',
                                                e.client,
                                            )
                                        }
                                        onApproveAll={() => {
                                            setStatuses((prev) => {
                                                const st = { ...prev };
                                                needsList.forEach((e) => {
                                                    st[e.id] = 'Approved';
                                                });
                                                return st;
                                            });
                                            setAutoplay(false);
                                            setFilter('all');
                                            flash(
                                                `${needsList.length} posts approved`,
                                            );
                                        }}
                                        onReset={() => {
                                            setStatuses({});
                                            setFilter('all');
                                            setScreen('home');
                                        }}
                                    />
                                )}
                                {screen === 'campaigns' && (
                                    <CampaignsScreen
                                        camp={camp}
                                        cc={cc}
                                        ticks={ticks}
                                        onPick={(i) => {
                                            setCamp(i);
                                            stop();
                                        }}
                                        onToggle={(key) => {
                                            setTicks((prev) => ({
                                                ...prev,
                                                [key]: !prev[key],
                                            }));
                                            stop();
                                        }}
                                    />
                                )}
                                {screen === 'clients' && <ClientsScreen />}
                                {screen === 'invoices' && (
                                    <InvoicesScreen
                                        invState={invState}
                                        paid={paid}
                                        onAct={(no, next, client, label) => {
                                            setInvState((prev) => ({
                                                ...prev,
                                                [no]: next,
                                            }));
                                            stop();
                                            flash(
                                                label === 'Send'
                                                    ? `Invoice sent to ${client}`
                                                    : 'Friendly reminder sent',
                                            );
                                        }}
                                    />
                                )}
                                {screen === 'analytics' && (
                                    <AnalyticsScreen
                                        seriesOff={seriesOff}
                                        onToggle={(k) => {
                                            setSeriesOff((p) => ({
                                                ...p,
                                                [k]: !p[k],
                                            }));
                                            stop();
                                        }}
                                    />
                                )}

                                {/* post drawer */}
                                {selE && (
                                    <div
                                        className="hm-drawer absolute inset-y-3 right-3 left-3 z-[5] flex sm:left-auto sm:w-[340px] flex-col gap-3.5 overflow-y-auto rounded-[22px] p-5"
                                        style={{
                                            background: C.card,
                                            boxShadow:
                                                '0 20px 50px -10px rgb(24 23 35 / 0.4)',
                                        }}
                                    >
                                        <div className="flex items-center justify-between">
                                            <span
                                                className="font-mono text-[10px] tracking-[0.16em]"
                                                style={{ color: C.muted }}
                                            >
                                                {selE.dayLabel} · {selE.time}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() => setSel(null)}
                                                aria-label="Close post"
                                                className="flex size-8 items-center justify-center rounded-full border"
                                                style={{
                                                    borderColor: C.secondary,
                                                }}
                                            >
                                                <svg
                                                    width="14"
                                                    height="14"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    strokeWidth="2"
                                                    strokeLinecap="round"
                                                    aria-hidden="true"
                                                >
                                                    <path d="M18 6 6 18" />
                                                    <path d="m6 6 12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                        <div
                                            className="flex h-[150px] flex-col justify-between rounded-2xl p-3.5"
                                            style={{
                                                background: C.teal,
                                                color: C.cream,
                                            }}
                                        >
                                            <span
                                                className="self-end rounded-md px-2 py-[3px] font-mono text-[10px]"
                                                style={{ background: C.ink }}
                                            >
                                                {selE.platform}
                                            </span>
                                            <b className="text-lg leading-tight font-bold">
                                                {selE.text}
                                            </b>
                                        </div>
                                        <span className="flex items-center justify-between">
                                            <b className="text-base font-semibold">
                                                {selE.client}
                                            </b>
                                            <Pill status={selE.status} />
                                        </span>
                                        <span
                                            className="text-[13px]"
                                            style={{ color: '#4A4857' }}
                                        >
                                            {selE.account} · {selE.dayLabel} at{' '}
                                            {selE.time}
                                        </span>
                                        {selE.canAct && (
                                            <span className="mt-auto flex gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setStatus(
                                                            selE.id,
                                                            'Approved',
                                                            selE.client,
                                                        )
                                                    }
                                                    className="h-11 flex-1 cursor-pointer rounded-full text-sm font-semibold"
                                                    style={{
                                                        background: C.lime,
                                                        color: C.ink,
                                                    }}
                                                >
                                                    Approve
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setStatus(
                                                            selE.id,
                                                            'Changes requested',
                                                            selE.client,
                                                        )
                                                    }
                                                    className="h-11 flex-1 cursor-pointer rounded-full border text-sm font-semibold"
                                                    style={{
                                                        borderWidth: 1.5,
                                                        borderColor: C.ink,
                                                    }}
                                                >
                                                    Request changes
                                                </button>
                                            </span>
                                        )}
                                    </div>
                                )}

                                {/* in-app toast */}
                                {toast && (
                                    <div
                                        className="hm-app-toast absolute bottom-[18px] left-1/2 z-[8] flex -translate-x-1/2 items-center gap-2.5 rounded-full px-[18px] py-2.5 text-[13px] font-semibold whitespace-nowrap"
                                        role="status"
                                        style={{
                                            background: C.ink,
                                            color: C.cream,
                                            boxShadow:
                                                '0 10px 24px -8px rgb(24 23 35 / 0.5)',
                                        }}
                                    >
                                        <span
                                            className="flex size-5 items-center justify-center rounded-full"
                                            style={{ background: C.lime }}
                                        >
                                            <Check
                                                size={12}
                                                color={C.ink}
                                                w={3}
                                            />
                                        </span>
                                        {toast}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                {/* tour chips */}
                <div className="relative mt-[18px] hidden flex-wrap justify-center gap-2 md:flex">
                    {SCREEN_ORDER.map((k, i) => {
                        const on = screen === k;
                        return (
                            <button
                                key={k}
                                type="button"
                                onClick={go(k)}
                                className="relative flex h-11 cursor-pointer items-center gap-2 rounded-full px-4 text-sm font-semibold"
                                style={{
                                    background: on
                                        ? C.cream
                                        : 'rgb(24 23 35 / 0.35)',
                                    color: on ? C.ink : C.cream,
                                    border: `1.5px solid ${on ? C.cream : 'rgb(245 242 232 / 0.35)'}`,
                                }}
                            >
                                <span className="font-mono text-[10px] tracking-[0.14em] opacity-75">
                                    {String(i + 1).padStart(2, '0')}
                                </span>
                                {LABELS[k]}
                                {autoplay && on && (
                                    <span
                                        className="hm-tourbar absolute right-3.5 bottom-1.5 left-3.5 h-0.5 rounded-sm"
                                        style={{ background: C.lime }}
                                    />
                                )}
                            </button>
                        );
                    })}
                </div>
                <p
                    className="relative mx-auto mt-3.5 max-w-[680px] text-center text-base"
                    style={{ color: C.cream }}
                >
                    <b className="font-semibold">{LABELS[screen]}.</b>{' '}
                    {CAPTIONS[screen]}
                </p>
            </div>
        </section>
    );
}

/* ---- individual screens ---- */

function Pill({ status }: { status: string }) {
    const [fg, bg] = STATUS[status];
    return (
        <span
            className="flex-shrink-0 rounded-full px-2.5 py-[3px] text-[11px] font-semibold whitespace-nowrap"
            style={{ color: fg, background: bg }}
        >
            ● {status}
        </span>
    );
}

function HomeScreen({
    greeting,
    needs,
    stats,
    queuePreview,
    healthPreview,
    goPosts,
    goCalendar,
}: {
    greeting: string;
    needs: number;
    stats: { label: string; value: string; note: string }[];
    queuePreview: (Post & { dayLabel: string })[];
    healthPreview: typeof CLIENTS;
    goPosts: () => void;
    goCalendar: () => void;
}) {
    return (
        <div className="hm-screen flex flex-col gap-[18px] p-3.5 md:p-[22px]">
            <div
                className="relative flex flex-col justify-between gap-5 overflow-hidden rounded-3xl p-5 sm:flex-row md:min-h-[290px] md:gap-6 md:p-[30px]"
                style={{ background: C.ink, color: C.cream }}
            >
                <div className="relative z-10 flex max-w-[560px] flex-col gap-4">
                    <span className="flex items-center gap-2.5 font-mono text-[11px] tracking-[0.16em]">
                        <span
                            className="size-2"
                            style={{ background: C.lime }}
                        />
                        01 — HOME
                    </span>
                    <span className="font-display text-[clamp(34px,4vw,58px)] leading-none font-bold tracking-tight">
                        {greeting}, <span style={{ color: C.lime }}>Maya.</span>
                    </span>
                    <span
                        className="h-[3px] w-10 rounded-sm"
                        style={{ background: C.signal }}
                    />
                    <span
                        className="text-[15px] leading-relaxed"
                        style={{ color: '#C9C6C1' }}
                    >
                        Here’s the studio today — what’s blocked, what’s going
                        out, and how every client is tracking.
                    </span>
                    <span className="mt-1 flex flex-wrap gap-2.5">
                        <button
                            type="button"
                            onClick={goPosts}
                            className="h-11 cursor-pointer rounded-full px-5 text-sm font-semibold"
                            style={{ background: C.lime, color: C.ink }}
                        >
                            Review the queue →
                        </button>
                        <button
                            type="button"
                            onClick={goCalendar}
                            className="flex h-11 cursor-pointer items-center gap-2 rounded-full border px-[18px] text-sm font-semibold"
                            style={{
                                color: C.cream,
                                borderWidth: 1.5,
                                borderColor: C.muted,
                            }}
                        >
                            <svg
                                width="15"
                                height="15"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2"
                                strokeLinecap="round"
                                aria-hidden="true"
                            >
                                <rect
                                    x="3"
                                    y="4"
                                    width="18"
                                    height="18"
                                    rx="2"
                                />
                                <path d="M16 2v4M8 2v4M3 10h18" />
                            </svg>
                            Calendar
                        </button>
                    </span>
                </div>
                <div className="relative z-10 flex flex-col items-start gap-1 text-left sm:items-end sm:text-right">
                    <span
                        className="font-mono text-[11px] tracking-[0.16em]"
                        style={{ color: C.lime }}
                    >
                        NEEDS ATTENTION
                    </span>
                    <span
                        className="text-[72px] leading-[0.95] md:text-[108px] font-bold tracking-tight"
                        style={{ color: C.lime }}
                    >
                        {String(needs).padStart(2, '0')}
                    </span>
                    <button
                        type="button"
                        onClick={goPosts}
                        className="cursor-pointer py-1.5 text-sm"
                        style={{ color: C.cream }}
                    >
                        Decisions waiting on you ↗
                    </button>
                </div>
            </div>

            <div
                className="grid grid-cols-2 border-t-[1.5px] md:grid-cols-4"
                style={{ borderColor: C.ink }}
            >
                {stats.map((s) => (
                    <div
                        key={s.label}
                        className="flex flex-col gap-1.5 border-r p-4"
                        style={{ borderColor: C.border }}
                    >
                        <span
                            className="font-mono text-[10px] tracking-[0.16em]"
                            style={{ color: C.muted }}
                        >
                            {s.label}
                        </span>
                        <span className="text-[32px] leading-none font-bold tracking-tight md:text-[44px]">
                            {s.value}
                        </span>
                        <span
                            className="font-mono text-[10px] tracking-[0.12em]"
                            style={{ color: C.muted }}
                        >
                            {s.note}
                        </span>
                    </div>
                ))}
            </div>

            <div className="grid grid-cols-1 gap-3.5 md:grid-cols-2">
                <div
                    className="rounded-[20px] p-5"
                    style={{
                        background: C.card,
                        boxShadow: '0 1px 2px rgb(24 23 35 / 0.06)',
                    }}
                >
                    <div
                        className="flex items-end justify-between border-b pb-2.5"
                        style={{ borderColor: C.secondary }}
                    >
                        <span className="flex flex-col">
                            <span
                                className="font-mono text-[10px] tracking-[0.16em]"
                                style={{ color: C.muted }}
                            >
                                QUEUE
                            </span>
                            <b className="text-[15px] font-semibold">
                                Needs attention
                            </b>
                        </span>
                        <button
                            type="button"
                            onClick={goPosts}
                            className="cursor-pointer text-[13px] font-semibold"
                        >
                            View all →
                        </button>
                    </div>
                    {queuePreview.map((q) => (
                        <div
                            key={q.id}
                            className="flex items-center justify-between gap-2.5 border-b py-2.5"
                            style={{ borderColor: '#F0ECE1' }}
                        >
                            <span className="flex min-w-0 flex-col leading-snug">
                                <b className="truncate text-[13px] font-semibold">
                                    {q.text}
                                </b>
                                <span
                                    className="text-[11px]"
                                    style={{ color: C.muted }}
                                >
                                    {q.client}
                                </span>
                            </span>
                            <Pill status={q.status} />
                        </div>
                    ))}
                </div>
                <div
                    className="rounded-[20px] p-5"
                    style={{
                        background: C.card,
                        boxShadow: '0 1px 2px rgb(24 23 35 / 0.06)',
                    }}
                >
                    <div
                        className="flex flex-col border-b pb-2.5"
                        style={{ borderColor: C.secondary }}
                    >
                        <span
                            className="font-mono text-[10px] tracking-[0.16em]"
                            style={{ color: C.muted }}
                        >
                            RETAINERS
                        </span>
                        <b className="text-[15px] font-semibold">
                            Client health
                        </b>
                    </div>
                    {healthPreview.map((c) => {
                        const [fg, bg] = HEALTH[c.health];
                        return (
                            <div
                                key={c.name}
                                className="flex items-center justify-between border-b py-2.5 text-[13px]"
                                style={{ borderColor: '#F0ECE1' }}
                            >
                                <span className="flex items-center gap-2.5">
                                    <span
                                        className="flex size-[26px] items-center justify-center rounded-full font-mono text-[9px]"
                                        style={c.avatar}
                                    >
                                        {c.initials}
                                    </span>
                                    <b className="font-semibold">{c.name}</b>
                                </span>
                                <span
                                    className="rounded-full px-2.5 py-[3px] text-[11px] font-semibold"
                                    style={{ color: fg, background: bg }}
                                >
                                    {c.health}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

function CalendarScreen({
    calCells,
    agenda,
    onOpen,
}: {
    calCells: {
        d: number;
        inMonth: boolean;
        isToday: boolean;
        events: (Post & { dayLabel: string })[];
    }[];
    agenda: (Post & { dayLabel: string })[];
    onOpen: (id: number) => void;
}) {
    return (
        <div className="hm-screen p-3.5 md:p-[22px]">
            <div
                className="rounded-3xl p-4 md:p-5"
                style={{
                    background: C.card,
                    boxShadow: '0 1px 2px rgb(24 23 35 / 0.06)',
                }}
            >
                <div className="flex items-center justify-between gap-3 pb-3.5">
                    <span className="text-[22px] font-bold tracking-tight md:text-[28px]">
                        November{' '}
                        <span
                            className="font-semibold"
                            style={{ color: C.muted }}
                        >
                            2026
                        </span>
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span
                            className="hidden h-8 items-center rounded-full border px-3.5 text-[13px] font-semibold sm:flex"
                            style={{ borderWidth: 1.5, borderColor: C.ink }}
                        >
                            Today
                        </span>
                        {['‹', '›'].map((a) => (
                            <span
                                key={a}
                                className="flex size-8 items-center justify-center rounded-full border"
                                style={{ borderWidth: 1.5, borderColor: C.ink }}
                            >
                                {a}
                            </span>
                        ))}
                    </span>
                </div>
                {/* month grid (desktop) */}
                <div className="hidden md:block">
                    <div
                        className="grid grid-cols-7 pb-2 font-mono text-[10px] tracking-[0.14em]"
                        style={{ color: C.muted }}
                    >
                        {['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'].map(
                            (d) => (
                                <span key={d} className="pl-2">
                                    {d}
                                </span>
                            ),
                        )}
                    </div>
                    <div
                        className="grid grid-cols-7 overflow-hidden rounded-[14px] border"
                        style={{ borderColor: C.border }}
                    >
                        {calCells.map((cell, idx) => (
                            <div
                                key={idx}
                                className="min-h-[96px] min-w-0 border-r border-b p-1.5"
                                style={{
                                    borderColor: C.border,
                                    background: cell.inMonth
                                        ? cell.d < 8
                                            ? '#F7F4EC'
                                            : C.card
                                        : '#EFEBDF',
                                }}
                            >
                                <span
                                    className="inline-flex items-center justify-center text-[11px]"
                                    style={
                                        cell.isToday
                                            ? {
                                                  width: 22,
                                                  height: 22,
                                                  borderRadius: '50%',
                                                  background: C.ink,
                                                  color: C.cream,
                                              }
                                            : {
                                                  color: cell.inMonth
                                                      ? '#4A4857'
                                                      : '#A9A6A0',
                                              }
                                    }
                                >
                                    {cell.d}
                                </span>
                                {cell.events.map((e) => (
                                    <button
                                        key={e.id}
                                        type="button"
                                        onClick={() => onOpen(e.id)}
                                        className="mt-1 block w-full cursor-pointer rounded-md border-l-[3px] px-1.5 py-[5px] text-left leading-snug"
                                        style={{
                                            background: '#FFFFFF',
                                            borderLeftColor: C.teal,
                                            boxShadow:
                                                '0 1px 2px rgb(24 23 35 / 0.08)',
                                        }}
                                    >
                                        <span className="flex justify-between gap-1 text-[10px]">
                                            <span
                                                className="font-mono"
                                                style={{ color: C.ink }}
                                            >
                                                {e.time}
                                            </span>
                                            <span
                                                className="font-semibold"
                                                style={{
                                                    color: STATUS[e.status][0],
                                                }}
                                            >
                                                {e.status}
                                            </span>
                                        </span>
                                        <b className="block text-[11px] font-semibold">
                                            {e.client}
                                        </b>
                                        <span
                                            className="block truncate text-[10px]"
                                            style={{ color: C.muted }}
                                        >
                                            {e.account} · {e.text}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        ))}
                    </div>
                </div>
                {/* agenda (mobile) */}
                <div className="flex flex-col gap-2 md:hidden">
                    <span
                        className="font-mono text-[10px] tracking-[0.16em]"
                        style={{ color: C.muted }}
                    >
                        THIS WEEK · TAP A POST
                    </span>
                    {agenda.map((e) => (
                        <button
                            key={e.id}
                            type="button"
                            onClick={() => onOpen(e.id)}
                            className="flex w-full cursor-pointer items-center gap-2.5 rounded-[10px] border-l-[3px] px-3 py-2.5 text-left"
                            style={{
                                background: '#FFFFFF',
                                borderLeftColor: C.teal,
                                boxShadow: '0 1px 2px rgb(24 23 35 / 0.08)',
                            }}
                        >
                            <span
                                className="w-[52px] shrink-0 font-mono text-[10px] leading-tight"
                                style={{ color: C.muted }}
                            >
                                {e.dayLabel}
                                <br />
                                {e.time}
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col leading-tight">
                                <b className="text-[13px] font-semibold">
                                    {e.client}
                                </b>
                                <span
                                    className="truncate text-[11px]"
                                    style={{ color: C.muted }}
                                >
                                    {e.text}
                                </span>
                                <span className="mt-1.5 self-start">
                                    <Pill status={e.status} />
                                </span>
                            </span>
                        </button>
                    ))}
                </div>
            </div>
        </div>
    );
}

function PostsScreen({
    queue,
    filter,
    filterDefs,
    needs,
    confettiColors,
    onFilter,
    onApprove,
    onChanges,
    onApproveAll,
    onReset,
}: {
    queue: (Post & { canAct: boolean; dayLabel: string })[];
    filter: string;
    filterDefs: [string, string][];
    needs: number;
    confettiColors: string[];
    onFilter: (k: string) => void;
    onApprove: (e: Post) => void;
    onChanges: (e: Post) => void;
    onApproveAll: () => void;
    onReset: () => void;
}) {
    const clear = needs === 0;
    return (
        <div className="hm-screen relative flex flex-col gap-3.5 p-3.5 md:p-[22px]">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <span className="flex flex-col">
                    <span
                        className="font-mono text-[10px] tracking-[0.16em]"
                        style={{ color: C.muted }}
                    >
                        IN REVIEW · {needs} WAITING
                    </span>
                    <span className="text-[28px] font-bold tracking-tight">
                        The decision queue
                    </span>
                </span>
                <span className="flex flex-wrap gap-1.5">
                    {filterDefs.map(([k, l]) => {
                        const on = filter === k;
                        return (
                            <button
                                key={k}
                                type="button"
                                onClick={() => onFilter(k)}
                                aria-pressed={on}
                                className="h-[34px] cursor-pointer rounded-full px-3.5 text-xs font-semibold"
                                style={{
                                    background: on ? C.ink : 'transparent',
                                    color: on ? C.cream : C.ink,
                                    border: `1.5px solid ${on ? C.ink : '#D9D4C4'}`,
                                }}
                            >
                                {l}
                            </button>
                        );
                    })}
                    {needs > 0 && (
                        <button
                            type="button"
                            onClick={onApproveAll}
                            className="h-[34px] cursor-pointer rounded-full px-3.5 text-xs font-semibold"
                            style={{ background: C.lime }}
                        >
                            Approve all ✓
                        </button>
                    )}
                </span>
            </div>
            <div
                className="rounded-[20px] px-[18px] py-1.5"
                style={{
                    background: C.card,
                    boxShadow: '0 1px 2px rgb(24 23 35 / 0.06)',
                }}
            >
                {queue.map((q) => (
                    <div
                        key={q.id}
                        className="flex flex-wrap items-center gap-3.5 border-b px-1 py-3.5"
                        style={{ borderColor: '#F0ECE1' }}
                    >
                        <span
                            className="w-16 font-mono text-[10px] leading-snug"
                            style={{ color: C.muted }}
                        >
                            {q.dayLabel}
                            <br />
                            {q.time}
                        </span>
                        <span className="flex min-w-0 flex-1 basis-[220px] flex-col leading-snug">
                            <b className="truncate text-sm font-semibold">
                                {q.text}
                            </b>
                            <span
                                className="text-xs"
                                style={{ color: C.muted }}
                            >
                                {q.client} · {q.platform}
                            </span>
                        </span>
                        <Pill status={q.status} />
                        {q.canAct && (
                            <span className="flex gap-1.5">
                                <button
                                    type="button"
                                    onClick={() => onApprove(q)}
                                    className="h-[34px] cursor-pointer rounded-full px-3.5 text-xs font-semibold"
                                    style={{ background: C.lime }}
                                >
                                    Approve
                                </button>
                                <button
                                    type="button"
                                    onClick={() => onChanges(q)}
                                    className="h-[34px] cursor-pointer rounded-full border px-3 text-xs font-semibold"
                                    style={{
                                        borderWidth: 1.5,
                                        borderColor: C.ink,
                                    }}
                                >
                                    Changes
                                </button>
                            </span>
                        )}
                    </div>
                ))}
                {clear && (
                    <div className="hm-screen flex flex-col items-center gap-2 py-9 text-center">
                        <b className="text-[30px] font-bold tracking-tight">
                            Inbox zero<span style={{ color: C.signal }}>.</span>
                        </b>
                        <span className="text-sm" style={{ color: C.muted }}>
                            Every decision made. Your clients have been
                            notified.
                        </span>
                        <button
                            type="button"
                            onClick={onReset}
                            className="mt-2 h-10 cursor-pointer rounded-full px-[18px] text-[13px] font-semibold"
                            style={{ background: C.ink, color: C.cream }}
                        >
                            Reset the demo ↺
                        </button>
                    </div>
                )}
            </div>
            {clear && (
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-0 overflow-hidden"
                >
                    {Array.from({ length: 28 }, (_, i) => (
                        <span
                            key={i}
                            className="hm-confetti"
                            style={{
                                left: `${(i * 37) % 100}%`,
                                background: confettiColors[i % 6],
                                animationDelay: `${(i % 7) * 0.09}s`,
                                animationDuration: `${1.6 + (i % 5) * 0.25}s`,
                                transform: `rotate(${i * 25}deg)`,
                            }}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

function CampaignsScreen({
    camp,
    cc,
    ticks,
    onPick,
    onToggle,
}: {
    camp: number;
    cc: (typeof CAMPS)[number];
    ticks: Record<string, boolean>;
    onPick: (i: number) => void;
    onToggle: (key: string) => void;
}) {
    const phases = ['Teaser', 'Launch', 'Sustain', 'Wrap'];
    return (
        <div className="hm-screen grid grid-cols-1 items-start gap-3.5 p-3.5 md:p-[22px] md:grid-cols-[290px_minmax(0,1fr)]">
            <div className="flex flex-col gap-2">
                <span
                    className="font-mono text-[10px] tracking-[0.16em]"
                    style={{ color: C.muted }}
                >
                    CAMPAIGNS · 3 ACTIVE
                </span>
                {CAMPS.map((c, i) => {
                    const on = i === camp;
                    return (
                        <button
                            key={c.code}
                            type="button"
                            onClick={() => onPick(i)}
                            className="flex cursor-pointer flex-col gap-2 rounded-[18px] p-3.5 text-left"
                            style={{
                                background: on ? C.ink : C.card,
                                color: on ? C.cream : C.ink,
                                boxShadow: on
                                    ? undefined
                                    : '0 1px 2px rgb(24 23 35 / 0.06)',
                            }}
                        >
                            <span className="flex justify-between gap-2">
                                <span className="font-mono text-[10px] tracking-[0.12em]">
                                    {c.code}
                                </span>
                                <span className="text-[11px] font-semibold">
                                    {c.phase}
                                </span>
                            </span>
                            <b className="text-[15px] leading-snug font-semibold">
                                {c.name}
                            </b>
                            <span
                                className="block h-[5px] overflow-hidden rounded-[3px]"
                                style={{
                                    background: 'rgb(128 128 128 / 0.25)',
                                }}
                            >
                                <span
                                    className="block h-full"
                                    style={{
                                        width: `${c.ready}%`,
                                        background: on ? C.lime : C.teal,
                                    }}
                                />
                            </span>
                        </button>
                    );
                })}
            </div>
            <div
                className="hm-screen flex flex-col gap-4 rounded-3xl p-4 md:gap-[18px] md:p-6"
                style={{
                    background: C.card,
                    boxShadow: '0 1px 2px rgb(24 23 35 / 0.06)',
                }}
            >
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <span className="flex flex-col">
                        <span
                            className="font-mono text-[10px] tracking-[0.16em]"
                            style={{ color: C.muted }}
                        >
                            {cc.code} · {cc.client}
                        </span>
                        <span className="text-[26px] leading-tight font-bold tracking-tight">
                            {cc.name}
                        </span>
                    </span>
                    <Pill status={cc.st} />
                </div>
                <div className="grid grid-cols-4 gap-1">
                    {phases.map((n, i) => (
                        <span
                            key={n}
                            className="flex min-w-0 flex-col gap-0.5 rounded-xl px-2 py-2.5 text-xs font-semibold md:px-3 md:text-[13px]"
                            style={{
                                background:
                                    i === cc.pIdx
                                        ? C.teal
                                        : i < cc.pIdx
                                          ? '#CAD3CB'
                                          : C.secondary,
                                color:
                                    i === cc.pIdx
                                        ? C.cream
                                        : i < cc.pIdx
                                          ? C.ink
                                          : C.muted,
                            }}
                        >
                            <span className="font-mono text-[9px] tracking-[0.14em] opacity-80">
                                {String(i + 1).padStart(2, '0')}
                            </span>
                            {n}
                        </span>
                    ))}
                </div>
                <div className="grid grid-cols-3 gap-1.5 md:gap-2.5">
                    {cc.kpis.map(([label, value, note]) => (
                        <div
                            key={label}
                            className="flex min-w-0 flex-col gap-1 rounded-2xl p-2.5 md:p-3.5"
                            style={{ background: C.canvas }}
                        >
                            <span
                                className="font-mono text-[9px] tracking-[0.14em]"
                                style={{ color: C.muted }}
                            >
                                {label}
                            </span>
                            <span className="text-xl leading-tight font-bold tracking-tight md:text-[26px]">
                                {value}
                            </span>
                            <span
                                className="text-[11px]"
                                style={{ color: C.muted }}
                            >
                                {note}
                            </span>
                        </div>
                    ))}
                </div>
                <div className="flex flex-col gap-2">
                    <span
                        className="font-mono text-[10px] tracking-[0.16em]"
                        style={{ color: C.muted }}
                    >
                        NEXT UP · TICK THEM OFF
                    </span>
                    {cc.next.map((label, j) => {
                        const key = `${camp}-${j}`;
                        const on = !!ticks[key];
                        return (
                            <button
                                key={key}
                                type="button"
                                onClick={() => onToggle(key)}
                                className="flex cursor-pointer items-center gap-2.5 py-1 text-left text-sm"
                                style={{ color: C.ink }}
                            >
                                <span
                                    className="flex size-5 items-center justify-center rounded-md text-[13px] font-bold"
                                    style={{
                                        background: on ? C.lime : 'transparent',
                                        border: `1.5px solid ${on ? C.ink : C.teal}`,
                                    }}
                                >
                                    {on ? '✓' : ''}
                                </span>
                                <span
                                    style={
                                        on
                                            ? {
                                                  textDecoration:
                                                      'line-through',
                                                  color: C.muted,
                                              }
                                            : undefined
                                    }
                                >
                                    {label}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

function ClientsScreen() {
    return (
        <div className="hm-screen flex flex-col gap-3.5 p-3.5 md:p-[22px]">
            <span className="flex flex-col">
                <span
                    className="font-mono text-[10px] tracking-[0.16em]"
                    style={{ color: C.muted }}
                >
                    ROSTER · 5 CLIENTS · 9 BRANDS
                </span>
                <span className="text-[28px] font-bold tracking-tight">
                    Clients
                </span>
            </span>
            <div
                className="rounded-[20px] px-[18px] py-1"
                style={{
                    background: C.card,
                    boxShadow: '0 1px 2px rgb(24 23 35 / 0.06)',
                }}
            >
                <div
                    className="hidden grid-cols-[2fr_1fr_1fr_1.4fr_1fr] gap-3 border-b py-3 font-mono text-[10px] tracking-[0.14em] md:grid"
                    style={{ color: C.muted, borderColor: C.secondary }}
                >
                    <span>CLIENT</span>
                    <span>RETAINER</span>
                    <span>THIS MONTH</span>
                    <span>NEXT POST</span>
                    <span>HEALTH</span>
                </div>
                {CLIENTS.map((c) => {
                    const [fg, bg] = HEALTH[c.health];
                    return (
                        <div
                            key={c.name}
                            className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 border-b py-3.5 text-[13px] md:grid-cols-[2fr_1fr_1fr_1.4fr_1fr]"
                            style={{ borderColor: '#F0ECE1' }}
                        >
                            <span className="flex min-w-0 items-center gap-2.5">
                                <span
                                    className="flex size-8 shrink-0 items-center justify-center rounded-full font-mono text-[10px]"
                                    style={c.avatar}
                                >
                                    {c.initials}
                                </span>
                                <span className="flex min-w-0 flex-col leading-tight">
                                    <b className="font-semibold">{c.name}</b>
                                    <span
                                        className="text-[11px]"
                                        style={{ color: C.muted }}
                                    >
                                        {c.brands}
                                        <span className="md:hidden">
                                            {' '}
                                            · {c.posts}
                                        </span>
                                    </span>
                                </span>
                            </span>
                            <span className="hidden font-mono text-xs md:block">
                                {c.retainer}
                            </span>
                            <span
                                className="hidden text-xs md:block"
                                style={{ color: '#4A4857' }}
                            >
                                {c.posts}
                            </span>
                            <span
                                className="hidden text-xs md:block"
                                style={{ color: '#4A4857' }}
                            >
                                {c.next}
                            </span>
                            <span
                                className="justify-self-end rounded-full whitespace-nowrap md:justify-self-start px-2.5 py-[3px] text-[11px] font-semibold"
                                style={{ color: fg, background: bg }}
                            >
                                {c.health}
                            </span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function InvoicesScreen({
    invState,
    paid,
    onAct,
}: {
    invState: Record<string, string>;
    paid: number;
    onAct: (no: string, next: string, client: string, label: string) => void;
}) {
    return (
        <div className="hm-screen flex flex-col gap-3.5 p-3.5 md:p-[22px]">
            <span className="flex flex-col">
                <span
                    className="font-mono text-[10px] tracking-[0.16em]"
                    style={{ color: C.muted }}
                >
                    LEDGER · NOVEMBER
                </span>
                <span className="text-[28px] font-bold tracking-tight">
                    Invoices
                </span>
            </span>
            <div className="grid grid-cols-2 gap-2.5 md:grid-cols-3">
                <div
                    className="col-span-2 rounded-[18px] p-4 md:col-span-1"
                    style={{ background: C.ink, color: C.cream }}
                >
                    <span
                        className="block font-mono text-[10px] tracking-[0.14em]"
                        style={{ color: '#C9C6C1' }}
                    >
                        INVOICED (GBP)
                    </span>
                    <span
                        className="text-[30px] font-bold"
                        style={{ color: C.lime }}
                    >
                        £8.4K
                    </span>
                </div>
                <div
                    className="rounded-[18px] p-4"
                    style={{
                        background: C.card,
                        boxShadow: '0 1px 2px rgb(24 23 35 / 0.06)',
                    }}
                >
                    <span
                        className="block font-mono text-[10px] tracking-[0.14em]"
                        style={{ color: C.muted }}
                    >
                        PAID
                    </span>
                    <span className="text-[30px] font-bold">
                        £{(paid / 1000).toFixed(1)}K
                    </span>
                </div>
                <div
                    className="rounded-[18px] p-4"
                    style={{
                        background: C.card,
                        boxShadow: '0 1px 2px rgb(24 23 35 / 0.06)',
                    }}
                >
                    <span
                        className="block font-mono text-[10px] tracking-[0.14em]"
                        style={{ color: C.muted }}
                    >
                        INVOICED (AED)
                    </span>
                    <span className="text-[26px] font-bold whitespace-nowrap md:text-[30px]">
                        AED 9.5K
                    </span>
                </div>
            </div>
            <div
                className="rounded-[20px] px-[18px] py-1"
                style={{
                    background: C.card,
                    boxShadow: '0 1px 2px rgb(24 23 35 / 0.06)',
                }}
            >
                {INVOICES.map((v) => {
                    const status = invState[v.no] ?? v.status;
                    const next =
                        status === 'Draft'
                            ? (['Send', 'Sent'] as const)
                            : status === 'Overdue'
                              ? (['Nudge', 'Sent'] as const)
                              : null;
                    const [fg, bg] = INV_STATUS[status];
                    return (
                        <div
                            key={v.no}
                            className="flex flex-wrap items-center gap-3.5 border-b px-1 py-3.5"
                            style={{ borderColor: '#F0ECE1' }}
                        >
                            <span
                                className="w-[72px] font-mono text-[11px]"
                                style={{ color: C.muted }}
                            >
                                {v.no}
                            </span>
                            <b className="flex-1 basis-[120px] text-sm font-semibold">
                                {v.client}
                            </b>
                            <span className="w-24 font-mono text-[13px]">
                                {v.amount}
                            </span>
                            <span
                                className="rounded-full px-2.5 py-[3px] text-[11px] font-semibold"
                                style={{ color: fg, background: bg }}
                            >
                                {status}
                            </span>
                            {next && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        onAct(v.no, next[1], v.client, next[0])
                                    }
                                    className="h-8 cursor-pointer rounded-full px-3.5 text-xs font-semibold"
                                    style={{
                                        background: C.ink,
                                        color: C.cream,
                                    }}
                                >
                                    {next[0]}
                                </button>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function AnalyticsScreen({
    seriesOff,
    onToggle,
}: {
    seriesOff: Record<string, boolean>;
    onToggle: (k: string) => void;
}) {
    const showInv = !seriesOff.inv;
    const showPaid = !seriesOff.paid;
    return (
        <div className="hm-screen grid grid-cols-1 gap-3.5 p-3.5 md:p-[22px] md:grid-cols-[1.4fr_1fr]">
            <div
                className="flex flex-col gap-2.5 rounded-3xl p-5"
                style={{
                    background: C.card,
                    boxShadow: '0 1px 2px rgb(24 23 35 / 0.06)',
                }}
            >
                <div
                    className="flex justify-between gap-2.5 border-b pb-2.5"
                    style={{ borderColor: C.secondary }}
                >
                    <span className="flex flex-col">
                        <span
                            className="font-mono text-[10px] tracking-[0.14em]"
                            style={{ color: C.muted }}
                        >
                            REVENUE (GBP) — LAST 6 MONTHS
                        </span>
                        <b className="text-[15px] font-semibold">
                            Invoiced vs. paid
                        </b>
                    </span>
                    <span className="flex flex-col text-right leading-tight">
                        <b className="text-2xl font-bold">£6.1K</b>
                        <span
                            className="text-[11px]"
                            style={{ color: C.muted }}
                        >
                            of £8.4K invoiced
                        </span>
                    </span>
                </div>
                <span className="flex justify-end gap-1.5 text-[11px]">
                    {(
                        [
                            ['inv', 'Invoiced', C.teal],
                            ['paid', 'Paid', C.info],
                        ] as const
                    ).map(([k, l, col]) => {
                        const on = !seriesOff[k];
                        return (
                            <button
                                key={k}
                                type="button"
                                onClick={() => onToggle(k)}
                                aria-pressed={on}
                                className="rounded-full border px-2.5 py-[3px] text-[11px]"
                                style={{
                                    background: on ? C.canvas : 'transparent',
                                    borderColor: C.border,
                                    color: on ? col : '#A9A6A0',
                                }}
                            >
                                ● {l}
                            </button>
                        );
                    })}
                </span>
                <svg
                    viewBox="0 0 560 200"
                    width="100%"
                    role="img"
                    aria-label="Line chart: invoiced and paid revenue rising from June to November"
                >
                    <g stroke={C.secondary} strokeWidth="1">
                        {[20, 60, 100, 140, 180].map((y) => (
                            <line key={y} x1="20" y1={y} x2="540" y2={y} />
                        ))}
                    </g>
                    {showInv && (
                        <g>
                            <polygon
                                points="20,180 20,146 124,126 228,116 332,97 436,54 540,46 540,180"
                                fill={C.teal}
                                fillOpacity=".08"
                            />
                            <polyline
                                points="20,146 124,126 228,116 332,97 436,54 540,46"
                                fill="none"
                                stroke={C.teal}
                                strokeWidth="2.5"
                                strokeLinejoin="round"
                                strokeLinecap="round"
                            />
                            <circle cx="540" cy="46" r="4.5" fill={C.teal} />
                        </g>
                    )}
                    {showPaid && (
                        <g>
                            <polyline
                                points="20,151 124,132 228,118 332,106 436,71 540,82"
                                fill="none"
                                stroke={C.info}
                                strokeWidth="2.5"
                                strokeLinejoin="round"
                                strokeLinecap="round"
                            />
                            <circle cx="540" cy="82" r="4.5" fill={C.info} />
                        </g>
                    )}
                    <g
                        fontFamily="DM Mono, monospace"
                        fontSize="10"
                        fill={C.muted}
                    >
                        <text x="20" y="197">
                            Jun
                        </text>
                        <text x="116" y="197">
                            Jul
                        </text>
                        <text x="218" y="197">
                            Aug
                        </text>
                        <text x="322" y="197">
                            Sep
                        </text>
                        <text x="426" y="197">
                            Oct
                        </text>
                        <text x="522" y="197">
                            Nov
                        </text>
                    </g>
                </svg>
            </div>
            <div className="flex flex-col gap-3.5">
                <div
                    className="flex flex-col gap-1.5 rounded-3xl p-5"
                    style={{ background: C.ink, color: C.cream }}
                >
                    <span
                        className="font-mono text-[10px] tracking-[0.14em]"
                        style={{ color: C.lime }}
                    >
                        RELIABILITY — LAST 6 MONTHS
                    </span>
                    <span className="flex items-baseline gap-2">
                        <b
                            className="text-[44px] font-bold tracking-tight"
                            style={{ color: C.lime }}
                        >
                            1.13
                        </b>
                        <span className="text-xs" style={{ color: '#C9C6C1' }}>
                            avg. attempts to publish
                        </span>
                    </span>
                    <svg
                        viewBox="0 0 560 140"
                        width="100%"
                        role="img"
                        aria-label="Published posts rising each month with very few failures"
                    >
                        <polyline
                            points="20,110 124,96 228,85 332,75 436,53 540,46"
                            fill="none"
                            stroke="#5BBD7F"
                            strokeWidth="3"
                            strokeLinejoin="round"
                            strokeLinecap="round"
                        />
                        <polyline
                            points="20,128 124,126 228,128 332,124 436,128 540,130"
                            fill="none"
                            stroke={C.danger}
                            strokeWidth="2.5"
                            strokeDasharray="4 6"
                            strokeLinecap="round"
                        />
                    </svg>
                </div>
                <div
                    className="flex flex-col gap-2 rounded-3xl p-5"
                    style={{
                        background: C.card,
                        boxShadow: '0 1px 2px rgb(24 23 35 / 0.06)',
                    }}
                >
                    <span
                        className="font-mono text-[10px] tracking-[0.14em]"
                        style={{ color: C.muted }}
                    >
                        IN PLAIN WORDS
                    </span>
                    <span className="text-[15px] leading-relaxed">
                        Reels posted <b className="font-semibold">6–8pm</b> get
                        twice the saves. Move Thursday’s carousel to{' '}
                        <b className="font-semibold">7pm</b>.
                    </span>
                </div>
            </div>
        </div>
    );
}
