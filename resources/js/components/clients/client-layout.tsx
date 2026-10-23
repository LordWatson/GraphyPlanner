import type { InertiaLinkProps } from '@inertiajs/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Archive,
    ArrowRight,
    Building2,
    CalendarDays,
    ChevronDown,
    Clapperboard,
    FileImage,
    Globe,
    LayoutGrid,
    Languages,
    Lightbulb,
    LineChart as LineChartIcon,
    Mail,
    MoreHorizontal,
    NotebookPen,
    Pencil,
    Receipt,
    Send,
    Share2,
    UserPlus,
    Wallet,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { usePlatforms } from '@/lib/platforms';
import { useState } from 'react';
import { HealthReasons } from '@/components/clients/health-reasons';
import type {
    HealthReason,
    HealthTrendPoint,
} from '@/components/clients/health-reasons';
import { PageHeader } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { CurrencyTotal } from '@/lib/currency';
import { formatCurrency, formatCurrencyTotals } from '@/lib/currency';
import { formatDate } from '@/lib/dates';
import { cn } from '@/lib/utils';
import {
    analytics as clientAnalytics,
    billing as clientBilling,
    briefs as clientBriefs,
    destroy as destroyClient,
    edit,
    index,
    media as clientMedia,
    portalAccess as clientPortalAccess,
    show,
} from '@/routes/clients';
import { index as clientAi } from '@/routes/clients/ai';
import { edit as editBrandBrain } from '@/routes/clients/brand-brain';
import { index as clientCampaigns } from '@/routes/clients/campaigns';
import { index as clientGrids } from '@/routes/clients/grids';
import { index as clientPosts } from '@/routes/clients/posts';

export type ClientData = {
    id: number;
    name: string;
    legal_name: string | null;
    website: string | null;
    industry: string | null;
    countries: string[] | null;
    status: string;
    start_date: string | null;
    retainer_amount: string | null;
    billing_cycle: string | null;
    tags: string[] | null;
    default_language: string | null;
    notes_internal: string | null;
    approval_email: string | null;
    billing_email: string | null;
    billing_address: string | null;
    health: string;
    health_reason: string;
    /** Step M-3.4: weighted reasons (viewer-filtered) and the 90-day trend. */
    health_reasons?: HealthReason[];
    health_trend?: HealthTrendPoint[];
    target_posts_per_week?: number | null;
};

export type ClientTab =
    | 'accounts'
    | 'campaigns'
    | 'posts'
    | 'grids'
    | 'media'
    | 'billing'
    | 'portal-access'
    | 'ai'
    | 'briefs';

/** Step I-2.4 — what `BuildClientPageHeaderAction` shares with every client tab page. */
export type ClientPageProps = {
    client: ClientData;
    tab: ClientTab;
    can: {
        update: boolean;
        delete: boolean;
        viewBilling: boolean;
        viewInvitations: boolean;
        viewSocialAccounts: boolean;
        viewCampaigns: boolean;
        viewAssets: boolean;
        viewPosts: boolean;
        useAi: boolean;
        createInvoice: boolean;
        createSocialAccount: boolean;
        createCampaign: boolean;
        createAsset: boolean;
        createPost: boolean;
        createInvitation: boolean;
        viewGrids: boolean;
        createGrid: boolean;
    };
    stats: {
        socialAccounts: number;
        campaigns: number;
        posts: number;
        assets: number;
        grids: number;
        billing: { invoices: number; outstanding: CurrencyTotal[] } | null;
        portalAccess: { invitations: number; active: number } | null;
        ai: { trends: number; ideas: number; briefs: number } | null;
    };
};

/**
 * Step M-2.1: platform choices come from the capability registry (`config/platforms.php`). Pass
 * `enabledOnly` for anything that creates something new (an account), so a network that isn't
 * switched on for publishing yet isn't offered; filters keep every platform so old data still
 * matches.
 */
export function usePlatformOptions(
    enabledOnly = false,
): { value: string; label: string }[] {
    const registry = usePlatforms();

    return Object.values(registry)
        .filter((platform) => !enabledOnly || platform.enabled)
        .map((platform) => ({ value: platform.value, label: platform.label }));
}

export function TabHeading({
    icon: Icon,
    title,
    description,
    actions,
}: {
    icon: LucideIcon;
    title: string;
    description: string;
    actions?: ReactNode;
}) {
    return (
        <div className="relative flex items-center gap-3 overflow-hidden">
            <span className="border-border bg-background text-foreground/70 flex size-10 shrink-0 items-center justify-center rounded-xl border">
                <Icon className="size-4" />
            </span>
            <div className="flex flex-col">
                <h2 className="text-[15px] font-semibold">{title}</h2>
                <p className="text-muted-foreground text-xs">{description}</p>
            </div>
            {actions && <div className="ml-auto shrink-0">{actions}</div>}
        </div>
    );
}

/** The card every tab's content sits in. */
export function TabPanel({ children }: { children: ReactNode }) {
    return (
        <section className="border-border bg-card animate-rise grid gap-4 rounded-2xl border p-5 shadow-sm">
            {children}
        </section>
    );
}

/** One box in the client page's section row: a headline figure that opens its section. */
type SectionCard = {
    key: string;
    label: string;
    icon: LucideIcon;
    value?: ReactNode;
    fullValue?: string;
    hint: string;
    href: NonNullable<InertiaLinkProps['href']>;
    active: boolean;
};

const sectionCardClass =
    'group relative flex min-w-[9.5rem] flex-1 cursor-pointer flex-col gap-1.5 overflow-hidden rounded-2xl border p-3 text-left shadow-sm transition-colors outline-none focus-visible:ring-ring/40 focus-visible:ring-[3px]';

// No hover-lift on cards (brand §5): the hover is a teal-tinted border and surface instead.
const sectionCardStateClass = (active: boolean) =>
    active
        ? 'border-primary bg-primary/5'
        : 'border-border bg-card hover:border-primary/40 hover:bg-accent/40 focus-visible:border-primary/40 data-[state=open]:border-primary/40 data-[state=open]:bg-accent/40';

function SectionCardBody({
    icon: Icon,
    label,
    value,
    fullValue,
    hint,
    menu = false,
}: {
    icon: LucideIcon;
    label: string;
    value?: ReactNode;
    fullValue?: string;
    hint: string;
    menu?: boolean;
}) {
    return (
        <>
            <div className="text-muted-foreground group-hover:text-foreground flex items-center gap-2 transition-colors">
                <span className="border-border bg-background text-foreground/70 group-hover:border-primary group-hover:bg-primary group-hover:text-primary-foreground group-data-[state=open]:border-primary group-data-[state=open]:bg-primary group-data-[state=open]:text-primary-foreground flex size-7 shrink-0 items-center justify-center rounded-lg border transition-colors">
                    <Icon className="size-3.5" />
                </span>
                <span className="truncate text-xs">{label}</span>
                {menu ? (
                    <ChevronDown className="ml-auto size-3.5 shrink-0 transition-transform group-data-[state=open]:rotate-180" />
                ) : (
                    <ArrowRight
                        aria-hidden
                        className="ml-auto size-3.5 shrink-0 opacity-0 transition-[opacity,translate] group-hover:translate-x-0 group-hover:opacity-100 group-focus-visible:translate-x-0 group-focus-visible:opacity-100 motion-safe:-translate-x-1"
                    />
                )}
            </div>
            <span
                className="font-display truncate text-3xl leading-none font-bold tabular-nums"
                title={fullValue}
            >
                {value ?? '—'}
            </span>
            <span className="text-muted-foreground truncate text-xs">
                {hint}
            </span>
        </>
    );
}

/**
 * Step I-2.4 — the client page chrome shared by every tab: header, internal notes, archive
 * dialog and the section boxes. Sections are real routes, so each box is a link and the
 * browser's back button walks between them. The boxes are the only section navigation.
 */
export function ClientLayout({
    client,
    tab,
    can,
    stats,
    children,
}: ClientPageProps & { children: ReactNode }) {
    const { currency, auth } = usePage<{
        currency: string;
        auth: { can?: { viewAnalytics?: boolean } };
    }>().props;
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const handleDeleteClient = () => {
        setDeleting(true);
        router.delete(destroyClient.url(client.id), {
            preserveScroll: true,
            onFinish: () => setDeleting(false),
            onError: () => setDeleting(false),
        });
    };

    const metaChips: { icon: LucideIcon; label: string }[] = [
        client.website ? { icon: Globe, label: client.website } : null,
        client.industry ? { icon: Building2, label: client.industry } : null,
        client.countries && client.countries.length > 0
            ? { icon: Globe, label: client.countries.join(', ') }
            : null,
        client.default_language
            ? { icon: Languages, label: client.default_language }
            : null,
        client.start_date
            ? {
                  icon: CalendarDays,
                  label: `Since ${formatDate(client.start_date)}`,
              }
            : null,
        client.approval_email
            ? { icon: Mail, label: client.approval_email }
            : null,
        client.retainer_amount !== null
            ? {
                  icon: Wallet,
                  label: `${formatCurrency(client.retainer_amount, currency)}${client.billing_cycle ? ` / ${client.billing_cycle}` : ''}`,
              }
            : null,
    ].filter(
        (chip): chip is { icon: LucideIcon; label: string } => chip !== null,
    );

    // The org's own currency first, then alphabetical — never summed across currencies.
    const outstanding = [...(stats.billing?.outstanding ?? [])].sort((a, b) =>
        a.currency === currency
            ? -1
            : b.currency === currency
              ? 1
              : a.currency.localeCompare(b.currency),
    );

    const plural = (count: number, word: string) =>
        `${count} ${word}${count === 1 ? '' : 's'}`;

    const primaryCards: SectionCard[] = [
        {
            key: 'accounts',
            label: 'Social accounts',
            icon: Share2,
            value: stats.socialAccounts,
            hint: 'Connected platforms',
            href: show(client.id),
            active: tab === 'accounts',
        },
        {
            key: 'campaigns',
            label: 'Campaigns',
            icon: Clapperboard,
            value: stats.campaigns,
            hint: 'Total campaigns',
            href: clientCampaigns(client.id),
            active: tab === 'campaigns',
        },
        {
            key: 'posts',
            label: 'Posts',
            icon: Send,
            value: stats.posts,
            hint: 'Scheduled & published',
            href: clientPosts(client.id),
            active: tab === 'posts',
        },
        {
            key: 'media',
            label: 'Assets',
            icon: FileImage,
            value: stats.assets,
            hint: 'Uploads & links on file',
            href: clientMedia(client.id),
            active: tab === 'media',
        },
    ];

    // The rest share one "More" box so the row stays on a single line (four primary boxes +
    // More = five). Each is gated by the same permission as its route (the stats are null
    // when the viewer can't open them).
    const moreCards: SectionCard[] = [
        // Phase 18: the Instagram grid planner (only when the client has an Instagram account).
        ...(can.viewGrids
            ? [
                  {
                      key: 'grids',
                      label: 'Grid',
                      icon: LayoutGrid,
                      value: stats.grids,
                      hint: 'Instagram grid plans',
                      href: clientGrids(client.id),
                      active: tab === 'grids',
                  },
              ]
            : []),
        ...(stats.billing
            ? [
                  {
                      key: 'billing',
                      label: 'Billing',
                      icon: Receipt,
                      value: formatCurrencyTotals(outstanding, currency, {
                          compact: true,
                      }),
                      fullValue: formatCurrencyTotals(outstanding, currency),
                      hint: `Outstanding · ${plural(stats.billing.invoices, 'invoice')}`,
                      href: clientBilling(client.id),
                      active: tab === 'billing',
                  },
              ]
            : []),
        ...(stats.portalAccess
            ? [
                  {
                      key: 'portal-access',
                      label: 'Portal access',
                      icon: UserPlus,
                      value: stats.portalAccess.active,
                      hint: `Active · ${plural(stats.portalAccess.invitations, 'invitation')}`,
                      href: clientPortalAccess(client.id),
                      active: tab === 'portal-access',
                  },
              ]
            : []),
        ...(stats.ai
            ? [
                  {
                      key: 'ai',
                      label: 'Trends & Ideas',
                      icon: Lightbulb,
                      value: stats.ai.trends + stats.ai.ideas,
                      hint: `${plural(stats.ai.trends, 'trend')}, ${plural(stats.ai.ideas, 'idea')}`,
                      href: clientAi(client.id),
                      active: tab === 'ai',
                  },
                  {
                      key: 'briefs',
                      label: 'Daily Briefs',
                      icon: CalendarDays,
                      value: stats.ai.briefs,
                      hint: 'Generated briefs on file',
                      href: clientBriefs(client.id),
                      active: tab === 'briefs',
                  },
              ]
            : []),
        // Step 17.5 — the client's analytics is its own page (outside this layout), so it never
        // shows as active here and has no headline figure.
        ...(auth.can?.viewAnalytics
            ? [
                  {
                      key: 'analytics',
                      label: 'Analytics',
                      icon: LineChartIcon,
                      hint: 'Performance & reports',
                      href: clientAnalytics(client.id),
                      active: false,
                  },
              ]
            : []),
    ];

    const activeMoreCard = moreCards.find((card) => card.active);

    return (
        <>
            <Head title={client.name} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-8 px-4 py-6 md:px-8 md:py-9">
                <PageHeader
                    eyebrow={
                        client.legal_name
                            ? `Client — ${client.legal_name}`
                            : 'Client'
                    }
                    title={
                        <span className="flex items-center gap-4 sm:gap-5">
                            <span
                                aria-hidden
                                className="bg-teal text-cream flex size-14 shrink-0 items-center justify-center rounded-2xl text-[1.75rem] leading-none sm:size-[4.5rem] sm:text-[2.25rem]"
                            >
                                {client.name.trim().charAt(0).toUpperCase()}
                            </span>
                            <span className="min-w-0">{client.name}</span>
                        </span>
                    }
                    description={
                        <span className="flex flex-wrap items-center gap-2">
                            <StatusPill kind="client" value={client.status} />
                            <StatusPill
                                kind="health"
                                value={client.health}
                                title={client.health_reason}
                            />
                            <span className="text-[13px]">
                                {client.health_reason}
                            </span>
                            <HealthReasons
                                reasons={client.health_reasons ?? []}
                                trend={client.health_trend ?? []}
                            />
                        </span>
                    }
                    meta={
                        metaChips.length > 0 &&
                        metaChips.map(({ icon: Icon, label }, i) => (
                            <span
                                key={`${label}-${i}`}
                                className="border-border bg-card text-muted-foreground inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                            >
                                <Icon className="size-3.5" />
                                {label}
                            </span>
                        ))
                    }
                    actions={
                        <>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={editBrandBrain(client.id)}>
                                    <NotebookPen />
                                    Brand brain
                                </Link>
                            </Button>
                            {can.update && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(client.id)}>
                                        <Pencil />
                                        Edit
                                    </Link>
                                </Button>
                            )}
                            {can.delete && (
                                <Button
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    onClick={() => setDeleteDialogOpen(true)}
                                >
                                    <Archive />
                                    Archive
                                </Button>
                            )}
                        </>
                    }
                />

                {client.notes_internal && (
                    <aside className="border-warning/25 bg-warning/8 flex gap-4 rounded-2xl border p-4">
                        <span className="eyebrow text-muted-foreground shrink-0 pt-0.5">
                            Internal notes
                        </span>
                        <p className="text-sm whitespace-pre-wrap">
                            {client.notes_internal}
                        </p>
                    </aside>
                )}

                <Dialog
                    open={deleteDialogOpen}
                    onOpenChange={setDeleteDialogOpen}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Archive {client.name}?</DialogTitle>
                            <DialogDescription>
                                It leaves every list, its publishing and
                                reminders pause, and its portal and review links
                                stop working. Posts, invoices and history are
                                all kept — restore it any time from Clients →
                                Archived.
                            </DialogDescription>
                        </DialogHeader>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setDeleteDialogOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                disabled={deleting}
                                onClick={handleDeleteClient}
                            >
                                <Archive />
                                Archive client
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <div className="flex flex-col gap-4">
                    <nav
                        aria-label="Client sections"
                        className="flex items-stretch gap-3 overflow-x-auto pb-1"
                    >
                        {primaryCards.map((card) => (
                            <Link
                                key={card.key}
                                href={card.href}
                                preserveScroll
                                aria-current={card.active ? 'page' : undefined}
                                className={cn(
                                    sectionCardClass,
                                    sectionCardStateClass(card.active),
                                )}
                            >
                                <SectionCardBody {...card} />
                            </Link>
                        ))}

                        {moreCards.length > 0 && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <button
                                        type="button"
                                        aria-current={
                                            activeMoreCard ? 'page' : undefined
                                        }
                                        className={cn(
                                            sectionCardClass,
                                            sectionCardStateClass(
                                                activeMoreCard !== undefined,
                                            ),
                                        )}
                                    >
                                        {activeMoreCard ? (
                                            <SectionCardBody
                                                {...activeMoreCard}
                                                menu
                                            />
                                        ) : (
                                            <SectionCardBody
                                                icon={MoreHorizontal}
                                                label="More"
                                                value={`+${moreCards.length}`}
                                                hint={moreCards
                                                    .map((card) => card.label)
                                                    .join(', ')}
                                                menu
                                            />
                                        )}
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    className="w-64"
                                >
                                    {moreCards.map((card) => (
                                        <DropdownMenuItem
                                            key={card.key}
                                            asChild
                                            className={cn(
                                                'flex items-center gap-2.5 py-2',
                                                card.active && 'bg-primary/5',
                                            )}
                                        >
                                            <Link
                                                href={card.href}
                                                preserveScroll
                                                aria-current={
                                                    card.active
                                                        ? 'page'
                                                        : undefined
                                                }
                                            >
                                                <span className="border-border bg-background text-foreground/70 flex size-7 shrink-0 items-center justify-center rounded-md border">
                                                    <card.icon className="size-3.5" />
                                                </span>
                                                <span className="flex min-w-0 flex-1 flex-col">
                                                    <span className="truncate text-xs font-medium">
                                                        {card.label}
                                                    </span>
                                                    <span className="text-muted-foreground truncate text-xs">
                                                        {card.hint}
                                                    </span>
                                                </span>
                                                {card.value !== undefined && (
                                                    <span
                                                        className="shrink-0 text-base font-bold tabular-nums"
                                                        title={card.fullValue}
                                                    >
                                                        {card.value}
                                                    </span>
                                                )}
                                            </Link>
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </nav>

                    {children}
                </div>
            </div>
        </>
    );
}

/** Breadcrumbs for every client tab page: Clients → this client. */
export function clientBreadcrumbs(props: { client: ClientData }) {
    return [
        { title: 'Clients', href: index() },
        { title: props.client.name, href: show(props.client.id) },
    ];
}
