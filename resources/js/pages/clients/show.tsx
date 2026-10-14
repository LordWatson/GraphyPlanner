import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarDays,
    Clapperboard,
    Facebook,
    FileImage,
    Figma,
    Globe,
    Instagram,
    Languages,
    Lightbulb,
    Link2,
    Linkedin,
    Loader2,
    Mail,
    MoreHorizontal,
    Music2,
    NotebookPen,
    Pencil,
    Receipt,
    Send,
    Share2,
    Trash2,
    TrendingUp,
    UploadCloud,
    UserPlus,
    Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { formatCurrency, formatCurrencyCompact } from '@/lib/currency';
import { edit as editBrandBrain } from '@/routes/clients/brand-brain';
import { edit, index, destroy as destroyClient } from '@/routes/clients';
import { store as storeSocialAccount } from '@/routes/clients/social-accounts';
import { destroy as destroySocialAccount, connect as connectSocialAccount } from '@/routes/social-accounts';
import { store as storeCampaign } from '@/routes/clients/campaigns';
import { show as showCampaign, destroy as destroyCampaign } from '@/routes/campaigns';
import { store as storePost } from '@/routes/clients/posts';
import { edit as editPost } from '@/routes/posts';
import { store as storeAsset } from '@/routes/clients/assets';
import { destroy as destroyAsset } from '@/routes/assets';
import { store as storeInvoice } from '@/routes/clients/invoices';
import { show as showInvoice, send as sendInvoice, markPaid as markPaidInvoice } from '@/routes/invoices';
import { store as storeInvitation } from '@/routes/clients/invitations';
import { destroy as destroyInvitation } from '@/routes/invitations';
import aiClients from '@/routes/clients/ai';
import dailyBriefs from '@/routes/clients/daily-briefs';
import { show as showAiTrend } from '@/routes/ai-trends';
import { show as showAiPostIdea } from '@/routes/ai-post-ideas';

type ClientData = {
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
    health: string;
    health_reason: string;
};

type InvoiceData = {
    id: number;
    invoice_number: string;
    status: 'draft' | 'sent' | 'paid';
    amount: string;
    currency: string;
    issue_date: string;
    due_date: string | null;
    description: string | null;
    sent_at: string | null;
    paid_at: string | null;
    can: { send: boolean; mark_paid: boolean; update: boolean; delete: boolean };
};

type SocialAccountData = {
    id: number;
    platform: string;
    handle: string;
    display_name: string | null;
    timezone: string;
    language: string | null;
    country: string | null;
    connection_status: string;
    can: { update: boolean; delete: boolean; connect: boolean };
};

type CampaignData = {
    id: number;
    name: string;
    status: string;
    start_date: string | null;
    end_date: string | null;
    goal: string | null;
    notes: string | null;
    can: { update: boolean; delete: boolean };
};

type AssetData = {
    id: number;
    campaign_id: number | null;
    source: 'upload' | 'figma' | 'url';
    type: string | null;
    url: string | null;
    original_filename: string | null;
    mime_type: string | null;
    size: number | null;
    rights: string | null;
    variant_group_id: string | null;
    can: { delete: boolean };
};

type PostTargetData = {
    id: number;
    social_account_id: number;
    platform: string | null;
    handle: string | null;
    scheduled_local_date: string | null;
    scheduled_local_time: string | null;
    scheduled_at_utc: string | null;
};

type PostData = {
    id: number;
    campaign_id: number | null;
    campaign_name: string | null;
    status: string;
    approval_mode: string;
    master_caption: string | null;
    hashtags: string[] | null;
    targets: PostTargetData[];
    created_at: string | null;
    can: { update: boolean; delete: boolean };
};

type TargetAccountData = {
    id: number;
    platform: string;
    handle: string;
    timezone: string;
};

type InvitationData = {
    id: number;
    email: string;
    expires_at: string;
    accepted_at: string | null;
    revoked_at: string | null;
    is_pending: boolean;
    can: { delete: boolean };
};

const statusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline'> = {
    active: 'success',
    paused: 'warning',
    offboarding: 'secondary',
    archived: 'outline',
};

const healthVariant: Record<string, 'success' | 'warning' | 'destructive'> = {
    green: 'success',
    amber: 'warning',
    red: 'destructive',
};

const invoiceStatusVariant: Record<string, 'secondary' | 'warning' | 'success'> = {
    draft: 'secondary',
    sent: 'warning',
    paid: 'success',
};

const connectionStatusVariant: Record<string, 'success' | 'warning' | 'secondary'> = {
    connected: 'success',
    token_expired: 'warning',
    not_connected: 'secondary',
};

const platformOptions = [
    { value: 'instagram', label: 'Instagram' },
    { value: 'tiktok', label: 'TikTok' },
    { value: 'facebook', label: 'Facebook' },
    { value: 'linkedin', label: 'LinkedIn' },
];

const campaignStatusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline'> = {
    planning: 'outline',
    active: 'success',
    completed: 'secondary',
    archived: 'outline',
};

const assetSourceOptions = [
    { value: 'upload', label: 'Upload file' },
    { value: 'figma', label: 'Figma link' },
    { value: 'url', label: 'External URL' },
];

const platformIcon: Record<string, typeof Instagram> = {
    instagram: Instagram,
    tiktok: Music2,
    facebook: Facebook,
    linkedin: Linkedin,
};

/**
 * Reads Laravel's `XSRF-TOKEN` cookie so the plain `fetch()` call in `handleConnect` below can
 * authenticate as a normal stateful request, mirroring what Inertia's own `<Form>`/`router` calls
 * do automatically via axios.
 */
function getXsrfTokenFromCookie(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function TabHeading({ icon: Icon, title, description }: { icon: typeof Share2; title: string; description: string }) {
    return (
        <div className="relative flex items-center gap-3 overflow-hidden">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-gradient-brand text-white shadow-sm">
                <Icon className="size-4" />
            </span>
            <div className="flex flex-col">
                <h2 className="font-serif text-lg font-semibold tracking-tight italic">{title}</h2>
                <p className="text-xs text-muted-foreground">{description}</p>
            </div>
        </div>
    );
}

const assetSourceIcon: Record<string, typeof UploadCloud> = {
    upload: UploadCloud,
    figma: Figma,
    url: Link2,
};

const postStatusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline' | 'destructive'> = {
    idea: 'outline',
    draft: 'secondary',
    internal_review: 'warning',
    waiting_client: 'warning',
    changes_requested: 'destructive',
    approved: 'success',
    scheduled: 'success',
    publishing: 'warning',
    published: 'success',
    failed: 'destructive',
    archived: 'outline',
};

export default function ClientShow({
    client,
    can,
    invoices,
    socialAccounts,
    campaigns,
    assets,
    posts,
    targetAccounts,
    timezones,
    invitations,
    ai,
}: {
    client: ClientData;
    can: {
        update: boolean;
        delete: boolean;
        viewBilling: boolean;
        viewInvitations: boolean;
        createInvoice: boolean;
        createSocialAccount: boolean;
        createCampaign: boolean;
        createAsset: boolean;
        createPost: boolean;
        createInvitation: boolean;
    };
    invoices: InvoiceData[];
    socialAccounts: SocialAccountData[];
    campaigns: CampaignData[];
    assets: AssetData[];
    timezones: { value: string; label: string }[];
    posts: PostData[];
    targetAccounts: TargetAccountData[];
    invitations: InvitationData[];
    ai?: {
        enabled: boolean;
        canUse: boolean;
        pillars: string[];
        campaigns: { id: number; name: string }[];
        trends: { id: number; title: string; summary: string | null; platforms: string[]; confidence: number }[];
        ideas: { id: number; title: string; description: string | null; platforms: string[]; suggestedCaption: string | null; post_id: number | null }[];
    };
}) {
    const { currency } = usePage().props;
    const [activeTab, setActiveTab] = useState('social-accounts');
    const [assetSource, setAssetSource] = useState<'upload' | 'figma' | 'url'>('upload');
    const [selectedTargetAccounts, setSelectedTargetAccounts] = useState<number[]>([]);
    const [connectingAccountId, setConnectingAccountId] = useState<number | null>(null);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [deleteConfirmName, setDeleteConfirmName] = useState('');
    const [deleting, setDeleting] = useState(false);

    // Step 2.4 — "Trends & Ideas" panel. Owner/Strategist only, gated behind config('ai.enabled').
    const showAiPanel = Boolean(ai?.enabled && ai?.canUse);
    const [aiLoading, setAiLoading] = useState(false);
    const [aiError, setAiError] = useState<string | null>(null);
    const [aiPlatforms, setAiPlatforms] = useState<string[]>(['instagram']);
    const [aiPillarFilter, setAiPillarFilter] = useState<string>('');
    const [aiCampaignFilter, setAiCampaignFilter] = useState<string>('');
    const [aiTypeFilter, setAiTypeFilter] = useState<'all' | 'trend' | 'idea'>('all');
    const [aiListPlatformFilter, setAiListPlatformFilter] = useState<string>('');
    // Trends/ideas are now persisted server-side (see ClientController@show and AiController),
    // so the list is seeded straight from the `ai` prop and survives a page refresh — Refresh
    // just triggers a new AI call, which saves its results, then reloads this prop.
    const trendItems = ai?.trends ?? [];
    const ideaItems = ai?.ideas ?? [];

    const fetchTrendsAndIdeas = async () => {
        setAiError(null);
        setAiLoading(true);

        try {
            const [trendsRes, ideasRes] = await Promise.all([
                fetch(aiClients.trends.url(client.id, { query: { platforms: aiPlatforms } }), {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                }),
                fetch(aiClients.ideas.url(client.id), {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                }),
            ]);
            const trendsData = await trendsRes.json();
            const ideasData = await ideasRes.json();

            if (!trendsRes.ok) throw new Error(typeof trendsData?.message === 'string' ? trendsData.message : 'Unable to fetch trends.');
            if (!ideasRes.ok) throw new Error(typeof ideasData?.message === 'string' ? ideasData.message : 'Unable to fetch ideas.');

            // The endpoints above already persisted the fresh trends/ideas server-side —
            // reload the `ai` prop so the list (with real ids for the detail pages) reflects it.
            router.reload({ only: ['ai'] });
        } catch (e) {
            setAiError(e instanceof Error ? e.message : 'Unable to fetch AI data.');
        } finally {
            setAiLoading(false);
        }
    };

    const filteredIdeas = ideaItems.filter((idea) => {
        const text = `${idea.title} ${idea.description ?? ''}`.toLowerCase();

        if (aiPillarFilter && !text.includes(aiPillarFilter.toLowerCase())) {
            return false;
        }

        if (aiCampaignFilter && !text.includes(aiCampaignFilter.toLowerCase())) {
            return false;
        }

        return true;
    });

    // Merge trends and ideas into a single list, tagged with their type, so they can be
    // shown together (newest first) in one paginated list instead of two side-by-side lists.
    const AI_ITEMS_PER_PAGE = 5;
    const combinedAiItems = [
        ...trendItems.map((trend) => ({ type: 'trend' as const, item: trend })),
        ...filteredIdeas.map((idea) => ({ type: 'idea' as const, item: idea })),
    ].filter(({ type, item }) => {
        if (aiTypeFilter !== 'all' && type !== aiTypeFilter) {
            return false;
        }

        if (aiListPlatformFilter && !item.platforms.includes(aiListPlatformFilter)) {
            return false;
        }

        return true;
    });
    const [aiPage, setAiPage] = useState(1);
    const aiTotalPages = Math.max(1, Math.ceil(combinedAiItems.length / AI_ITEMS_PER_PAGE));
    const aiCurrentPage = Math.min(aiPage, aiTotalPages);
    const paginatedAiItems = combinedAiItems.slice(
        (aiCurrentPage - 1) * AI_ITEMS_PER_PAGE,
        aiCurrentPage * AI_ITEMS_PER_PAGE,
    );

    useEffect(() => {
        setAiPage(1);
    }, [trendItems.length, filteredIdeas.length, aiTypeFilter, aiListPlatformFilter]);

    // Step 2.5 — "Daily Briefs" tab. Same gating as the AI panel above.
    const [briefsLoading, setBriefsLoading] = useState(false);
    const [briefsError, setBriefsError] = useState<string | null>(null);
    const [briefs, setBriefs] = useState<{ id: number; brief_date: string; trends: { title: string; summary: string }[]; ideas: { title: string; description: string }[] }[]>([]);

    const fetchDailyBriefs = async () => {
        setBriefsError(null);
        setBriefsLoading(true);

        try {
            const res = await fetch(dailyBriefs.index.url(client.id), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json();

            if (!res.ok) throw new Error(typeof data?.message === 'string' ? data.message : 'Unable to fetch daily briefs.');

            setBriefs(Array.isArray(data?.briefs) ? data.briefs : []);
        } catch (e) {
            setBriefsError(e instanceof Error ? e.message : 'Unable to fetch daily briefs.');
        } finally {
            setBriefsLoading(false);
        }
    };

    // Keeps the "Daily Briefs" stat card's count accurate without requiring the user to open
    // the tab first.
    useEffect(() => {
        if (showAiPanel) {
            fetchDailyBriefs();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [showAiPanel]);

    const handleDeleteClient = () => {
        setDeleting(true);
        router.delete(destroyClient.url(client.id), {
            preserveScroll: true,
            onFinish: () => setDeleting(false),
            onError: () => setDeleting(false),
        });
    };

    const toggleTargetAccount = (id: number) => {
        setSelectedTargetAccounts((current) =>
            current.includes(id) ? current.filter((accountId) => accountId !== id) : [...current, id],
        );
    };

    // The vendor's hosted connect flow finishes in the popup opened by `handleConnect` below, not
    // in this tab, so we listen for the confirmation `postMessage` it sends (see
    // `resources/views/social-accounts/connected.blade.php`) and refresh the accounts list here.
    useEffect(() => {
        const handleMessage = (event: MessageEvent) => {
            if (event.origin !== window.location.origin) {
                return;
            }

            if (event.data?.type === 'graphy:social-account-connected') {
                router.reload({ only: ['socialAccounts'] });
            }
        };

        window.addEventListener('message', handleMessage);

        return () => window.removeEventListener('message', handleMessage);
    }, []);

    // Opens the vendor's hosted connect page in a separate popup/tab (instead of taking over this
    // one), and refreshes the social accounts list once that popup is closed as a fallback in case
    // the `postMessage` confirmation above didn't arrive.
    const handleConnect = async (accountId: number) => {
        setConnectingAccountId(accountId);

        const popup = window.open('about:blank', `graphy-connect-${accountId}`);

        try {
            const response = await fetch(connectSocialAccount.url(accountId), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': getXsrfTokenFromCookie(),
                },
            });

            const data = await response.json();

            if (!response.ok || !data.url) {
                popup?.close();
                toast.error(data.message ?? 'Unable to start the Upload-Post connect flow.');
                return;
            }

            if (popup) {
                popup.location.href = data.url;

                const interval = window.setInterval(() => {
                    if (popup.closed) {
                        window.clearInterval(interval);
                        router.reload({ only: ['socialAccounts'] });
                    }
                }, 1000);
            } else {
                // Popup was blocked — fall back to a plain new tab.
                window.open(data.url, '_blank');
            }
        } catch {
            popup?.close();
            toast.error('Unable to start the Upload-Post connect flow.');
        } finally {
            setConnectingAccountId(null);
        }
    };

    const metaChips: { icon: typeof Globe; label: string }[] = [
        client.website ? { icon: Globe, label: client.website } : null,
        client.industry ? { icon: Building2, label: client.industry } : null,
        client.countries && client.countries.length > 0
            ? { icon: Globe, label: client.countries.join(', ') }
            : null,
        client.default_language ? { icon: Languages, label: client.default_language } : null,
        client.start_date ? { icon: CalendarDays, label: `Since ${client.start_date}` } : null,
        client.approval_email ? { icon: Mail, label: client.approval_email } : null,
        client.retainer_amount !== null
            ? { icon: Wallet, label: `${formatCurrency(client.retainer_amount, currency)}${client.billing_cycle ? ` / ${client.billing_cycle}` : ''}` }
            : null,
    ].filter((chip): chip is { icon: typeof Globe; label: string } => chip !== null);

    const outstandingInvoiceTotal = invoices
        .filter((invoice) => invoice.status !== 'paid')
        .reduce((total, invoice) => total + Number(invoice.amount), 0);

    // The primary stats are always visible as a single row of cards; everything else (billing,
    // portal access, AI insights) lives behind the "More stats" dropdown below so the header
    // doesn't balloon into a long, messy multi-row grid as more features/cards are added.
    const primaryStatCards = [
        { icon: Share2, label: 'Social accounts', value: socialAccounts.length, hint: 'Connected platforms', tab: 'social-accounts' },
        { icon: Clapperboard, label: 'Campaigns', value: campaigns.length, hint: 'Total campaigns', tab: 'campaigns' },
        { icon: Send, label: 'Posts', value: posts.length, hint: 'Scheduled & published', tab: 'posts' },
        { icon: FileImage, label: 'Assets', value: assets.length, hint: 'Uploads & links on file', tab: 'assets' },
    ];

    const secondaryStatCards = [
        ...(can.viewBilling
            ? [
                  {
                      icon: Receipt,
                      label: 'Outstanding',
                      value: formatCurrencyCompact(outstandingInvoiceTotal, currency),
                      fullValue: formatCurrency(outstandingInvoiceTotal, currency),
                      hint: `${invoices.length} invoice${invoices.length === 1 ? '' : 's'} total`,
                      tab: 'billing',
                  },
              ]
            : []),
        ...(can.viewInvitations
            ? [
                  {
                      icon: UserPlus,
                      label: 'Portal access',
                      value: invitations.filter((invitation) => invitation.accepted_at !== null && invitation.revoked_at === null).length,
                      hint: `${invitations.length} invitation${invitations.length === 1 ? '' : 's'} total`,
                      tab: 'invitations',
                  },
              ]
            : []),
        ...(showAiPanel
            ? [
                  {
                      icon: Lightbulb,
                      label: 'Trends & Ideas',
                      value: trendItems.length + filteredIdeas.length,
                      hint: `${trendItems.length} trend${trendItems.length === 1 ? '' : 's'}, ${filteredIdeas.length} idea${filteredIdeas.length === 1 ? '' : 's'}`,
                      tab: 'ai',
                  },
                  {
                      icon: CalendarDays,
                      label: 'Daily Briefs',
                      value: briefs.length,
                      hint: 'Generated briefs on file',
                      tab: 'daily-briefs',
                  },
              ]
            : []),
    ];

    const isSecondaryTabActive = secondaryStatCards.some((card) => card.tab === activeTab);

    return (
        <>
            <Head title={client.name} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
                <div className="bg-noise relative flex flex-col gap-4 overflow-hidden rounded-2xl bg-gradient-brand-animated p-6 text-primary-foreground shadow-agency sm:p-8">
                    <span
                        aria-hidden
                        className="pointer-events-none absolute -top-16 -right-16 size-56 rounded-full bg-white/10 blur-2xl"
                    />
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 bg-[radial-gradient(white_1px,transparent_1px)] bg-[size:22px_22px] opacity-[0.08]"
                    />
                    <div className="relative flex flex-col gap-4">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex flex-col gap-1">
                                <span className="inline-flex w-fit items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-[10px] font-medium tracking-widest uppercase">
                                    Client
                                </span>
                                <h1 className="font-serif text-3xl font-semibold tracking-tight italic sm:text-4xl">
                                    {client.name}
                                </h1>
                                {client.legal_name && (
                                    <p className="text-sm text-primary-foreground/80">{client.legal_name}</p>
                                )}
                            </div>
                            <div className="flex items-center gap-2">
                                <Button variant="secondary" size="sm" asChild>
                                    <Link href={editBrandBrain(client.id)}>
                                        <NotebookPen />
                                        Brand brain
                                    </Link>
                                </Button>
                                {can.update && (
                                    <Button variant="secondary" size="sm" asChild>
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
                                        onClick={() => {
                                            setDeleteConfirmName('');
                                            setDeleteDialogOpen(true);
                                        }}
                                    >
                                        <Trash2 />
                                        Delete
                                    </Button>
                                )}
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant={statusVariant[client.status] ?? 'outline'} className="bg-white/90">
                                {client.status}
                            </Badge>
                            <Badge variant={healthVariant[client.health] ?? 'outline'} title={client.health_reason} className="bg-white/90">
                                {client.health}
                            </Badge>
                            <span className="text-xs text-primary-foreground/80">{client.health_reason}</span>
                        </div>

                        {metaChips.length > 0 && (
                            <div className="flex flex-wrap items-center gap-3 text-sm">
                                {metaChips.map(({ icon: Icon, label }, index) => (
                                    <span
                                        key={`${label}-${index}`}
                                        className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-1 text-xs"
                                    >
                                        <Icon className="size-3.5" />
                                        {label}
                                    </span>
                                ))}
                            </div>
                        )}

                        {client.notes_internal && (
                            <div className="rounded-xl border border-white/20 bg-white/10 p-3">
                                <span className="text-xs font-medium text-primary-foreground/80">Internal notes</span>
                                <p className="mt-1 text-sm whitespace-pre-wrap">{client.notes_internal}</p>
                            </div>
                        )}
                    </div>
                </div>

                <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Delete {client.name}?</DialogTitle>
                            <DialogDescription>
                                This action cannot be undone. This will permanently delete the client and all of its
                                associated data. To confirm, type <strong>{client.name}</strong> below.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-1">
                            <Label htmlFor="delete-confirm-name">Client name</Label>
                            <Input
                                id="delete-confirm-name"
                                value={deleteConfirmName}
                                onChange={(event) => setDeleteConfirmName(event.target.value)}
                                autoComplete="off"
                                placeholder={client.name}
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDeleteDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                disabled={deleteConfirmName !== client.name || deleting}
                                onClick={handleDeleteClient}
                            >
                                <Trash2 />
                                Delete client
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <div className="flex items-stretch gap-3 overflow-x-auto pb-1">
                    {primaryStatCards.map(({ icon: Icon, label, value, hint, tab }) => (
                        <button
                            type="button"
                            key={label}
                            onClick={() => setActiveTab(tab)}
                            aria-current={activeTab === tab}
                            className={`group relative flex min-w-[9.5rem] flex-1 flex-col gap-1.5 overflow-hidden rounded-2xl border p-3 text-left shadow-sm transition-all ${
                                activeTab === tab ? 'border-primary bg-primary/5 shadow-agency' : 'border-border bg-card hover:-translate-y-0.5 hover:border-primary/30'
                            }`}
                        >
                            <div className="flex items-center gap-2 text-muted-foreground">
                                <span className="flex size-7 items-center justify-center rounded-lg bg-gradient-brand text-white shadow-sm">
                                    <Icon className="size-3.5" />
                                </span>
                                <span className="truncate text-xs">{label}</span>
                            </div>
                            <span className="truncate text-2xl font-bold tabular-nums">{value}</span>
                            <span className="truncate text-xs text-muted-foreground">{hint}</span>
                        </button>
                    ))}

                    {secondaryStatCards.length > 0 && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button
                                    type="button"
                                    aria-current={isSecondaryTabActive}
                                    className={`relative flex min-w-[9.5rem] flex-1 flex-col items-center justify-center gap-1.5 overflow-hidden rounded-2xl border p-3 text-center shadow-sm transition-all ${
                                        isSecondaryTabActive ? 'border-primary bg-primary/5 shadow-agency' : 'border-border bg-card hover:-translate-y-0.5 hover:border-primary/30'
                                    }`}
                                >
                                    <span className="flex size-7 items-center justify-center rounded-lg bg-gradient-brand text-white shadow-sm">
                                        <MoreHorizontal className="size-3.5" />
                                    </span>
                                    <span className="text-xs font-medium">More stats</span>
                                    <span className="truncate text-xs text-muted-foreground">
                                        {secondaryStatCards.length} more card{secondaryStatCards.length === 1 ? '' : 's'}
                                    </span>
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-64">
                                {secondaryStatCards.map(({ icon: Icon, label, value, hint, tab, fullValue }) => (
                                    <DropdownMenuItem
                                        key={label}
                                        onSelect={() => setActiveTab(tab)}
                                        className={`flex items-center gap-2.5 py-2 ${activeTab === tab ? 'bg-primary/5' : ''}`}
                                    >
                                        <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-gradient-brand text-white shadow-sm">
                                            <Icon className="size-3.5" />
                                        </span>
                                        <span className="flex min-w-0 flex-1 flex-col">
                                            <span className="truncate text-xs text-muted-foreground">{label}</span>
                                            <span className="truncate text-xs">{hint}</span>
                                        </span>
                                        <span className="shrink-0 text-base font-bold tabular-nums" title={fullValue ?? undefined}>
                                            {value}
                                        </span>
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>

                <Tabs value={activeTab} onValueChange={setActiveTab} className="gap-4">
                    <TabsList className="h-auto w-full flex-wrap justify-start gap-1 rounded-2xl bg-muted/60 p-1">
                        <TabsTrigger value="social-accounts">
                            <Share2 />
                            Social accounts
                        </TabsTrigger>
                        <TabsTrigger value="campaigns">
                            <Clapperboard />
                            Campaigns
                        </TabsTrigger>
                        <TabsTrigger value="posts">
                            <Send />
                            Posts
                        </TabsTrigger>
                        <TabsTrigger value="assets">
                            <FileImage />
                            Assets
                        </TabsTrigger>

                        {(can.viewBilling || can.viewInvitations) && (
                            <span aria-hidden className="mx-1 hidden h-6 w-px self-center bg-border sm:block" />
                        )}
                        {can.viewBilling && (
                            <TabsTrigger value="billing">
                                <Receipt />
                                Billing
                            </TabsTrigger>
                        )}
                        {can.viewInvitations && (
                            <TabsTrigger value="invitations">
                                <UserPlus />
                                Portal access
                            </TabsTrigger>
                        )}

                        {showAiPanel && (
                            <span aria-hidden className="mx-1 hidden h-6 w-px self-center bg-border sm:block" />
                        )}
                        {showAiPanel && (
                            <TabsTrigger value="ai">
                                <Lightbulb />
                                Trends & Ideas
                            </TabsTrigger>
                        )}
                        {showAiPanel && (
                            <TabsTrigger value="daily-briefs">
                                <CalendarDays />
                                Daily Briefs
                            </TabsTrigger>
                        )}
                    </TabsList>

                    <TabsContent
                        value="social-accounts"
                        className="grid gap-4 rounded-2xl border border-border bg-card p-5 shadow-sm"
                    >
                        <TabHeading icon={Share2} title="Social accounts" description="Platforms this client posts to" />

                        <>
                            <>
                                {can.createSocialAccount && (
                                    <Form
                                        {...storeSocialAccount.form(client.id)}
                                        resetOnSuccess
                                        className="grid grid-cols-2 gap-3 rounded-2xl border border-dashed border-border bg-card/50 p-4 shadow-sm sm:grid-cols-4"
                                    >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-1">
                                            <Label htmlFor="platform">Platform</Label>
                                            <Select name="platform" defaultValue="instagram">
                                                <SelectTrigger id="platform" className="w-full">
                                                    <SelectValue placeholder="Select a platform" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {platformOptions.map((platform) => (
                                                        <SelectItem key={platform.value} value={platform.value}>
                                                            {platform.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={errors.platform} />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="handle">Handle</Label>
                                            <Input id="handle" name="handle" required />
                                            <InputError message={errors.handle} />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="display_name">Display name</Label>
                                            <Input id="display_name" name="display_name" />
                                            <InputError message={errors.display_name} />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="timezone">Timezone (IANA)</Label>
                                            <Select name="timezone">
                                                <SelectTrigger id="timezone" className="w-full">
                                                    <SelectValue placeholder="Select a timezone" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {timezones.map((timezone) => (
                                                        <SelectItem key={timezone.value} value={timezone.value}>
                                                            {timezone.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={errors.timezone} />
                                        </div>
                                        <div className="col-span-full">
                                            <Button type="submit" size="sm" disabled={processing}>
                                                Add social account
                                            </Button>
                                        </div>
                                    </>
                                        )}
                                    </Form>
                                )}

                            {socialAccounts.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">No social accounts yet.</p>
                                ) : (
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {socialAccounts.map((account) => {
                                            const PlatformIcon = platformIcon[account.platform] ?? Share2;
                                            return (
                                                <div
                                                    key={account.id}
                                                    className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-agency"
                                                >
                                                    <div className="flex min-w-0 items-center gap-3">
                                                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                                            <PlatformIcon className="size-4" />
                                                        </span>
                                                        <div className="flex min-w-0 flex-col gap-1">
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <span className="text-sm font-medium">{account.handle}</span>
                                                                <Badge variant={connectionStatusVariant[account.connection_status] ?? 'outline'}>
                                                                    {account.connection_status}
                                                                </Badge>
                                                            </div>
                                                            <span className="text-xs text-muted-foreground">
                                                                {account.display_name ?? account.handle} · {account.timezone}
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div className="flex shrink-0 items-center gap-2">
                                                        {account.can.connect && account.connection_status !== 'connected' && (
                                                            <Button
                                                                type="button"
                                                                size="icon"
                                                                variant="outline"
                                                                disabled={connectingAccountId === account.id}
                                                                title="Connect"
                                                                onClick={() => handleConnect(account.id)}
                                                            >
                                                                <Link2 />
                                                                <span className="sr-only">Connect</span>
                                                            </Button>
                                                        )}
                                                        {account.can.delete && (
                                                            <Form {...destroySocialAccount.form(account.id)}>
                                                                {({ processing }) => (
                                                                    <Button
                                                                        type="submit"
                                                                        size="icon"
                                                                        variant="outline"
                                                                        disabled={processing}
                                                                        title="Remove"
                                                                    >
                                                                        <Trash2 />
                                                                        <span className="sr-only">Remove</span>
                                                                    </Button>
                                                                )}
                                                            </Form>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </>
                        </>
                    </TabsContent>

                    <TabsContent
                        value="campaigns"
                        className="grid gap-4 rounded-2xl border border-border bg-card p-5 shadow-sm"
                    >
                        <TabHeading icon={Clapperboard} title="Campaigns" description="Marketing campaigns for this client" />

                        <>
                            <>
                                {can.createCampaign && (
                                    <Form
                                        {...storeCampaign.form(client.id)}
                                        resetOnSuccess
                                        className="grid grid-cols-2 gap-3 rounded-2xl border border-dashed border-border bg-card/50 p-4 shadow-sm sm:grid-cols-4"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <div className="grid gap-1">
                                                    <Label htmlFor="name">Name</Label>
                                                    <Input id="name" name="name" required />
                                                    <InputError message={errors.name} />
                                                </div>
                                                <div className="grid gap-1">
                                                    <Label htmlFor="start_date">Start date</Label>
                                                    <Input id="start_date" name="start_date" type="date" />
                                                    <InputError message={errors.start_date} />
                                                </div>
                                                <div className="grid gap-1">
                                                    <Label htmlFor="end_date">End date</Label>
                                                    <Input id="end_date" name="end_date" type="date" />
                                                    <InputError message={errors.end_date} />
                                                </div>
                                                <div className="col-span-full grid gap-1">
                                                    <Label htmlFor="goal">Goal</Label>
                                                    <textarea
                                                        id="goal"
                                                        name="goal"
                                                        rows={3}
                                                        className="border-input dark:bg-input/30 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                                                    />
                                                    <InputError message={errors.goal} />
                                                </div>
                                                <div className="col-span-full">
                                                    <Button type="submit" size="sm" disabled={processing}>
                                                        Add campaign
                                                    </Button>
                                                </div>
                                            </>
                                        )}
                                    </Form>
                                )}

                            {campaigns.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">No campaigns yet.</p>
                                ) : (
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {campaigns.map((campaign) => (
                                            <div
                                                key={campaign.id}
                                                className="flex items-center justify-between gap-3 rounded-xl border border-border bg-card p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-agency"
                                            >
                                                <Link
                                                    href={showCampaign(campaign.id)}
                                                    className="flex flex-1 items-center gap-3"
                                                >
                                                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                                        <Clapperboard className="size-4" />
                                                    </span>
                                                    <div className="flex flex-col gap-1">
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-sm font-medium hover:underline">{campaign.name}</span>
                                                            <Badge variant={campaignStatusVariant[campaign.status] ?? 'outline'}>
                                                                {campaign.status}
                                                            </Badge>
                                                        </div>
                                                        <span className="text-xs text-muted-foreground">
                                                            {campaign.start_date ?? '—'}
                                                            {campaign.end_date ? ` – ${campaign.end_date}` : ''}
                                                            {campaign.goal ? ` · ${campaign.goal}` : ''}
                                                        </span>
                                                    </div>
                                                </Link>
                                                <div className="flex items-center gap-2">
                                                    <Button asChild size="sm" variant="outline">
                                                        <Link href={showCampaign(campaign.id)}>View</Link>
                                                    </Button>
                                                    {campaign.can.delete && (
                                                        <Form {...destroyCampaign.form(campaign.id)}>
                                                            {({ processing }) => (
                                                                <Button type="submit" size="sm" variant="outline" disabled={processing}>
                                                                    <Trash2 />
                                                                    Remove
                                                                </Button>
                                                            )}
                                                        </Form>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </>
                        </>
                    </TabsContent>

                    <TabsContent
                        value="posts"
                        className="grid gap-4 rounded-2xl border border-border bg-card p-5 shadow-sm"
                    >
                        <TabHeading icon={Send} title="Posts" description="Scheduled content across social accounts" />

                        <>
                            <>
                                {can.createPost && (
                                    <Form
                                        {...storePost.form(client.id)}
                                        resetOnSuccess
                                        onSuccess={() => setSelectedTargetAccounts([])}
                                        className="grid grid-cols-2 gap-3 rounded-2xl border border-dashed border-border bg-card/50 p-4 shadow-sm sm:grid-cols-4"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <div className="col-span-full grid gap-1">
                                                    <Label htmlFor="master_caption">Caption</Label>
                                                    <textarea
                                                        id="master_caption"
                                                        name="master_caption"
                                                        rows={3}
                                                        className="border-input dark:bg-input/30 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                                                    />
                                                    <InputError message={errors.master_caption} />
                                                </div>
                                                <div className="col-span-full grid gap-1">
                                                    <Label>Target accounts</Label>
                                                    <div className="flex flex-wrap gap-2">
                                                        {targetAccounts.length === 0 && (
                                                            <p className="text-sm text-muted-foreground">
                                                                Add a social account before creating posts.
                                                            </p>
                                                        )}
                                                        {targetAccounts.map((account) => {
                                                            const AccountIcon = platformIcon[account.platform] ?? Share2;
                                                            const selected = selectedTargetAccounts.includes(account.id);
                                                            return (
                                                                <button
                                                                    key={account.id}
                                                                    type="button"
                                                                    onClick={() => toggleTargetAccount(account.id)}
                                                                    className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors ${
                                                                        selected
                                                                            ? 'border-primary bg-primary/10 text-primary'
                                                                            : 'border-border bg-muted/50 text-foreground'
                                                                    }`}
                                                                >
                                                                    <AccountIcon className="size-3.5" />
                                                                    {account.handle} · {account.timezone}
                                                                </button>
                                                            );
                                                        })}
                                                    </div>
                                                    {selectedTargetAccounts.map((accountId, i) => (
                                                        <input
                                                            key={accountId}
                                                            type="hidden"
                                                            name={`targets[${i}][social_account_id]`}
                                                            value={accountId}
                                                        />
                                                    ))}
                                                </div>
                                                <div className="grid gap-1">
                                                    <Label htmlFor="scheduled_local_date">Scheduled date</Label>
                                                    <Input
                                                        id="scheduled_local_date"
                                                        type="date"
                                                        onChange={(e) => {
                                                            document
                                                                .querySelectorAll<HTMLInputElement>('input[name$="[scheduled_local_date]"]')
                                                                .forEach((input) => (input.value = e.target.value));
                                                        }}
                                                    />
                                                    {selectedTargetAccounts.map((accountId, i) => (
                                                        <input key={accountId} type="hidden" name={`targets[${i}][scheduled_local_date]`} />
                                                    ))}
                                                </div>
                                                <div className="grid gap-1">
                                                    <Label htmlFor="scheduled_local_time">Scheduled time (local)</Label>
                                                    <Input
                                                        id="scheduled_local_time"
                                                        type="time"
                                                        onChange={(e) => {
                                                            document
                                                                .querySelectorAll<HTMLInputElement>('input[name$="[scheduled_local_time]"]')
                                                                .forEach((input) => (input.value = e.target.value));
                                                        }}
                                                    />
                                                    {selectedTargetAccounts.map((accountId, i) => (
                                                        <input key={accountId} type="hidden" name={`targets[${i}][scheduled_local_time]`} />
                                                    ))}
                                                </div>
                                                <InputError message={errors['targets.0.social_account_id']} />
                                                <div className="col-span-full">
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing || selectedTargetAccounts.length === 0}
                                                    >
                                                        Create post
                                                    </Button>
                                                </div>
                                            </>
                                        )}
                                    </Form>
                                )}

                            {posts.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">No posts yet.</p>
                                ) : (
                                    <div className="grid gap-3">
                                        {posts.map((post) => (
                                            <div
                                                key={post.id}
                                                role="button"
                                                tabIndex={0}
                                                onClick={() => router.visit(editPost(post.id).url)}
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter' || e.key === ' ') {
                                                        e.preventDefault();
                                                        router.visit(editPost(post.id).url);
                                                    }
                                                }}
                                                className="flex cursor-pointer flex-col gap-2 rounded-xl border border-border bg-card p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-agency"
                                            >
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <div className="flex items-center gap-2">
                                                        <Badge variant={postStatusVariant[post.status] ?? 'outline'}>
                                                            {post.status.replace('_', ' ')}
                                                        </Badge>
                                                        {post.campaign_name && (
                                                            <span className="text-xs text-muted-foreground">{post.campaign_name}</span>
                                                        )}
                                                    </div>
                                                    <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                                                        <Pencil className="size-3.5" />
                                                        Open editor
                                                    </span>
                                                </div>
                                                {post.master_caption && (
                                                    <p className="text-sm">{post.master_caption}</p>
                                                )}
                                                {post.targets.length > 0 && (
                                                    <div className="flex flex-wrap gap-2">
                                                        {post.targets.map((target) => {
                                                            const AccountIcon = platformIcon[target.platform ?? ''] ?? Share2;
                                                            return (
                                                                <span
                                                                    key={target.id}
                                                                    className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-xs text-foreground"
                                                                >
                                                                    <AccountIcon className="size-3.5 text-primary" />
                                                                    {target.handle} · {target.scheduled_local_date ?? '—'}
                                                                    {target.scheduled_local_time ? ` ${target.scheduled_local_time}` : ''}
                                                                </span>
                                                            );
                                                        })}
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </>
                        </>
                    </TabsContent>

                    <TabsContent
                        value="assets"
                        className="grid gap-4 rounded-2xl border border-border bg-card p-5 shadow-sm"
                    >
                        <TabHeading icon={FileImage} title="Assets" description="Uploads and Figma/URL links for this client" />

                        <>
                            <>
                                {can.createAsset && (
                                    <Form
                                        {...storeAsset.form(client.id)}
                                        resetOnSuccess
                                        className="grid grid-cols-2 gap-3 rounded-2xl border border-dashed border-border bg-card/50 p-4 shadow-sm sm:grid-cols-4"
                                        encType="multipart/form-data"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <div className="grid gap-1">
                                                    <Label htmlFor="source">Source</Label>
                                                    <Select
                                                        name="source"
                                                        defaultValue="upload"
                                                        onValueChange={(value) => setAssetSource(value as 'upload' | 'figma' | 'url')}
                                                    >
                                                        <SelectTrigger id="source" className="w-full">
                                                            <SelectValue placeholder="Select a source" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {assetSourceOptions.map((source) => (
                                                                <SelectItem key={source.value} value={source.value}>
                                                                    {source.label}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <InputError message={errors.source} />
                                                </div>
                                                {assetSource === 'upload' ? (
                                                    <div className="grid gap-1">
                                                        <Label htmlFor="file">File</Label>
                                                        <Input
                                                            id="file"
                                                            name="file"
                                                            type="file"
                                                            accept="image/*,video/*,.pdf,.doc,.docx,.ppt,.pptx,.zip"
                                                        />
                                                        <InputError message={errors.file} />
                                                    </div>
                                                ) : (
                                                    <div className="grid gap-1">
                                                        <Label htmlFor="url">
                                                            {assetSource === 'figma' ? 'Figma URL' : 'URL'}
                                                        </Label>
                                                        <Input id="url" name="url" type="url" placeholder="https://" />
                                                        <InputError message={errors.url} />
                                                    </div>
                                                )}
                                                <div className="grid gap-1">
                                                    <Label htmlFor="rights">Rights</Label>
                                                    <Input id="rights" name="rights" />
                                                    <InputError message={errors.rights} />
                                                </div>
                                                <div className="col-span-full">
                                                    <Button type="submit" size="sm" disabled={processing}>
                                                        Add asset
                                                    </Button>
                                                </div>
                                            </>
                                        )}
                                    </Form>
                                )}

                            {assets.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">No assets yet.</p>
                                ) : (
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {assets.map((asset) => {
                                            const SourceIcon = assetSourceIcon[asset.source] ?? FileImage;
                                            return (
                                            <div
                                                key={asset.id}
                                                className="flex items-center justify-between gap-3 rounded-xl border border-border bg-card p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-agency"
                                            >
                                                <div className="flex items-center gap-3">
                                                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                                        <SourceIcon className="size-4" />
                                                    </span>
                                                    <div className="flex flex-col gap-1">
                                                        <div className="flex items-center gap-2">
                                                            <a
                                                                href={asset.url ?? '#'}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="text-sm font-medium underline"
                                                            >
                                                                {asset.original_filename ?? asset.url}
                                                            </a>
                                                            <Badge variant="outline">{asset.source}</Badge>
                                                        </div>
                                                        {asset.rights && (
                                                            <span className="text-xs text-muted-foreground">{asset.rights}</span>
                                                        )}
                                                    </div>
                                                </div>
                                                {asset.can.delete && (
                                                    <Form {...destroyAsset.form(asset.id)}>
                                                        {({ processing }) => (
                                                            <Button type="submit" size="sm" variant="outline" disabled={processing}>
                                                                <Trash2 />
                                                                Remove
                                                            </Button>
                                                        )}
                                                    </Form>
                                                )}
                                            </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </>
                        </>
                    </TabsContent>

                    {can.viewBilling && (
                    <TabsContent
                        value="billing"
                        className="grid gap-4 rounded-2xl border border-border bg-card p-5 shadow-sm"
                    >
                        <TabHeading icon={Receipt} title="Billing history" description="Invoices raised for this client" />

                        <>
                        {can.createInvoice && (
                            <Form
                                {...storeInvoice.form(client.id)}
                                resetOnSuccess
                                className="grid grid-cols-2 gap-3 rounded-2xl border border-dashed border-border bg-card/50 p-4 shadow-sm sm:grid-cols-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-1">
                                            <Label htmlFor="amount">Amount</Label>
                                            <Input id="amount" name="amount" type="number" step="0.01" min="0.01" required />
                                            <InputError message={errors.amount} />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="issue_date">Issue date</Label>
                                            <Input id="issue_date" name="issue_date" type="date" required />
                                            <InputError message={errors.issue_date} />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="due_date">Due date</Label>
                                            <Input id="due_date" name="due_date" type="date" />
                                            <InputError message={errors.due_date} />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="description">Description</Label>
                                            <Input id="description" name="description" />
                                            <InputError message={errors.description} />
                                        </div>
                                        <div className="col-span-full">
                                            <Button type="submit" size="sm" disabled={processing}>
                                                Create invoice
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}

                        {invoices.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No invoices yet.</p>
                        ) : (
                            <div className="grid gap-3">
                                {invoices.map((invoice) => (
                                    <div
                                        key={invoice.id}
                                        className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-agency"
                                    >
                                        <div className="flex items-center gap-3">
                                            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                                <Receipt className="size-4" />
                                            </span>
                                            <div className="flex flex-col gap-1">
                                                <div className="flex items-center gap-2">
                                                    <Link href={showInvoice(invoice.id)} className="text-sm font-medium underline">
                                                        {invoice.invoice_number}
                                                    </Link>
                                                    <Badge variant={invoiceStatusVariant[invoice.status] ?? 'outline'}>
                                                        {invoice.status}
                                                    </Badge>
                                                </div>
                                                <span className="text-xs text-muted-foreground">
                                                    Issued {invoice.issue_date}
                                                    {invoice.due_date ? ` · Due ${invoice.due_date}` : ''}
                                                    {invoice.sent_at ? ` · Sent ${invoice.sent_at}` : ''}
                                                    {invoice.paid_at ? ` · Paid ${invoice.paid_at}` : ''}
                                                </span>
                                                {invoice.description && (
                                                    <span className="text-xs text-muted-foreground">{invoice.description}</span>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <span className="tabular-nums text-sm font-medium">
                                                {formatCurrency(invoice.amount, currency)}
                                            </span>
                                            {invoice.can.send && (
                                                <Form {...sendInvoice.form(invoice.id)}>
                                                    {({ processing }) => (
                                                        <Button type="submit" size="sm" variant="outline" disabled={processing}>
                                                            Send
                                                        </Button>
                                                    )}
                                                </Form>
                                            )}
                                            {invoice.can.mark_paid && (
                                                <Form {...markPaidInvoice.form(invoice.id)}>
                                                    {({ processing }) => (
                                                        <Button type="submit" size="sm" variant="outline" disabled={processing}>
                                                            Mark paid
                                                        </Button>
                                                    )}
                                                </Form>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                        </>
                    </TabsContent>
                    )}

                    {can.viewInvitations && (
                    <TabsContent
                        value="invitations"
                        className="grid gap-4 rounded-2xl border border-border bg-card p-5 shadow-sm"
                    >
                        <TabHeading icon={UserPlus} title="Client portal access" description="Invite a named contact at this client to their own login" />

                        <>
                        {can.createInvitation && (
                            <Form
                                {...storeInvitation.form(client.id)}
                                resetOnSuccess
                                className="grid grid-cols-2 gap-3 rounded-2xl border border-dashed border-border bg-card/50 p-4 shadow-sm sm:grid-cols-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="col-span-2 grid gap-1">
                                            <Label htmlFor="email">Email</Label>
                                            <Input id="email" name="email" type="email" required />
                                            <InputError message={errors.email} />
                                        </div>
                                        <div className="col-span-full">
                                            <Button type="submit" size="sm" disabled={processing}>
                                                <UserPlus />
                                                Send invitation
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}

                        {invitations.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No invitations sent yet.</p>
                        ) : (
                            <div className="grid gap-3">
                                {invitations.map((invitation) => (
                                    <div
                                        key={invitation.id}
                                        className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-agency"
                                    >
                                        <div className="flex items-center gap-3">
                                            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                                <UserPlus className="size-4" />
                                            </span>
                                            <div className="flex flex-col gap-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-medium">{invitation.email}</span>
                                                    <Badge
                                                        variant={
                                                            invitation.accepted_at
                                                                ? 'success'
                                                                : invitation.revoked_at
                                                                  ? 'secondary'
                                                                  : invitation.is_pending
                                                                    ? 'warning'
                                                                    : 'outline'
                                                        }
                                                    >
                                                        {invitation.accepted_at
                                                            ? 'accepted'
                                                            : invitation.revoked_at
                                                              ? 'revoked'
                                                              : invitation.is_pending
                                                                ? 'pending'
                                                                : 'expired'}
                                                    </Badge>
                                                </div>
                                                <span className="text-xs text-muted-foreground">
                                                    Expires {invitation.expires_at}
                                                </span>
                                            </div>
                                        </div>
                                        {invitation.can.delete && (
                                            <Form {...destroyInvitation.form(invitation.id)}>
                                                {({ processing }) => (
                                                    <Button type="submit" size="sm" variant="outline" disabled={processing}>
                                                        <Trash2 />
                                                        Revoke
                                                    </Button>
                                                )}
                                            </Form>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                        </>
                    </TabsContent>
                    )}

                    {showAiPanel && (
                    <TabsContent
                        value="ai"
                        className="grid gap-4 rounded-2xl border border-border bg-card p-5 shadow-sm"
                    >
                        <TabHeading icon={TrendingUp} title="Trends & Ideas" description="AI-discovered trends and fresh post ideas for this client" />

                        <div className="flex flex-wrap items-center gap-2">
                            {platformOptions.map((platform) => {
                                const selected = aiPlatforms.includes(platform.value);
                                const PlatformIcon = platformIcon[platform.value] ?? Share2;

                                return (
                                    <button
                                        key={platform.value}
                                        type="button"
                                        onClick={() =>
                                            setAiPlatforms((current) =>
                                                selected ? current.filter((v) => v !== platform.value) : [...current, platform.value],
                                            )
                                        }
                                        className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors ${
                                            selected ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-muted/50 text-foreground'
                                        }`}
                                    >
                                        <PlatformIcon className="size-3.5" />
                                        {platform.label}
                                    </button>
                                );
                            })}
                            <Button type="button" size="sm" onClick={fetchTrendsAndIdeas} disabled={aiLoading}>
                                {aiLoading ? 'Loading…' : 'Refresh'}
                            </Button>
                            {aiError && <span className="text-xs text-destructive">{aiError}</span>}
                        </div>

                        <div className="flex flex-wrap gap-3">
                            <div className="grid gap-1">
                                <Label>Filter by type</Label>
                                <div className="flex items-center gap-1 rounded-md border border-border p-0.5">
                                    {([
                                        { value: 'all', label: 'All' },
                                        { value: 'trend', label: 'Trends' },
                                        { value: 'idea', label: 'Ideas' },
                                    ] as const).map((option) => (
                                        <button
                                            key={option.value}
                                            type="button"
                                            onClick={() => setAiTypeFilter(option.value)}
                                            className={`rounded-sm px-2.5 py-1 text-xs transition-colors ${
                                                aiTypeFilter === option.value
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'text-muted-foreground hover:text-foreground'
                                            }`}
                                        >
                                            {option.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="ai-list-platform-filter">Filter by channel</Label>
                                <Select value={aiListPlatformFilter || '__all'} onValueChange={(v) => setAiListPlatformFilter(v === '__all' ? '' : v)}>
                                    <SelectTrigger id="ai-list-platform-filter" className="w-48">
                                        <SelectValue placeholder="All channels" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all">All channels</SelectItem>
                                        {platformOptions.map((platform) => (
                                            <SelectItem key={platform.value} value={platform.value}>
                                                {platform.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="ai-pillar-filter">Filter ideas by pillar</Label>
                                <Select value={aiPillarFilter || '__all'} onValueChange={(v) => setAiPillarFilter(v === '__all' ? '' : v)}>
                                    <SelectTrigger id="ai-pillar-filter" className="w-48">
                                        <SelectValue placeholder="All pillars" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all">All pillars</SelectItem>
                                        {(ai?.pillars ?? []).map((pillar) => (
                                            <SelectItem key={pillar} value={pillar}>
                                                {pillar}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="ai-campaign-filter">Filter ideas by campaign</Label>
                                <Select value={aiCampaignFilter || '__all'} onValueChange={(v) => setAiCampaignFilter(v === '__all' ? '' : v)}>
                                    <SelectTrigger id="ai-campaign-filter" className="w-48">
                                        <SelectValue placeholder="All campaigns" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all">All campaigns</SelectItem>
                                        {(ai?.campaigns ?? []).map((campaign) => (
                                            <SelectItem key={campaign.id} value={campaign.name}>
                                                {campaign.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="relative grid gap-2">
                            {aiLoading && (
                                <div className="absolute inset-0 z-10 flex items-center justify-center gap-2 rounded-md bg-background/70 text-sm text-muted-foreground backdrop-blur-sm">
                                    <Loader2 className="size-4 animate-spin" />
                                    Loading trends &amp; ideas…
                                </div>
                            )}

                            {combinedAiItems.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No trends or ideas saved yet, or none match the current filters.</p>
                            ) : (
                                paginatedAiItems.map(({ type, item }) =>
                                    type === 'trend' ? (
                                        <Link
                                            key={`trend-${item.id}`}
                                            href={showAiTrend(item.id)}
                                            className="flex flex-col gap-1 rounded-xl border border-border bg-card p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-agency"
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <div className="font-medium">{item.title}</div>
                                                <Badge variant="outline" className="inline-flex items-center gap-1">
                                                    <TrendingUp className="size-3" />
                                                    Trend
                                                </Badge>
                                            </div>
                                            <p className="line-clamp-2 text-sm text-muted-foreground">{item.summary}</p>
                                            {item.platforms.length > 0 && (
                                                <div className="mt-1 flex flex-wrap gap-1">
                                                    {item.platforms.map((p, pi) => (
                                                        <Badge key={pi} variant="outline">{p}</Badge>
                                                    ))}
                                                </div>
                                            )}
                                        </Link>
                                    ) : (
                                        <Link
                                            key={`idea-${item.id}`}
                                            href={showAiPostIdea(item.id)}
                                            className="flex flex-col gap-1 rounded-xl border border-border bg-card p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-agency"
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <div className="font-medium">{item.title}</div>
                                                <div className="flex items-center gap-2">
                                                    {item.post_id && <Badge variant="success">Draft created</Badge>}
                                                    <Badge variant="outline" className="inline-flex items-center gap-1">
                                                        <Lightbulb className="size-3" />
                                                        Idea
                                                    </Badge>
                                                </div>
                                            </div>
                                            <p className="line-clamp-2 text-sm text-muted-foreground">{item.description}</p>
                                            {item.platforms.length > 0 && (
                                                <div className="mt-1 flex flex-wrap gap-1">
                                                    {item.platforms.map((p, pi) => (
                                                        <Badge key={pi} variant="outline">{p}</Badge>
                                                    ))}
                                                </div>
                                            )}
                                        </Link>
                                    ),
                                )
                            )}

                            {combinedAiItems.length > 0 && aiTotalPages > 1 && (
                                <div className="flex items-center justify-between pt-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setAiPage((p) => Math.max(1, p - 1))}
                                        disabled={aiCurrentPage <= 1}
                                    >
                                        Previous
                                    </Button>
                                    <span className="text-xs text-muted-foreground">
                                        Page {aiCurrentPage} of {aiTotalPages}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setAiPage((p) => Math.min(aiTotalPages, p + 1))}
                                        disabled={aiCurrentPage >= aiTotalPages}
                                    >
                                        Next
                                    </Button>
                                </div>
                            )}
                        </div>
                    </TabsContent>
                    )}

                    {showAiPanel && (
                    <TabsContent
                        value="daily-briefs"
                        className="grid gap-4 rounded-2xl border border-border bg-card p-5 shadow-sm"
                    >
                        <TabHeading icon={CalendarDays} title="Daily Briefs" description="Nightly AI-generated trends and post ideas for this client" />

                        <div className="flex flex-wrap items-center gap-2">
                            <Button type="button" size="sm" onClick={fetchDailyBriefs} disabled={briefsLoading}>
                                {briefsLoading ? 'Loading…' : 'Load briefs'}
                            </Button>
                            {briefsError && <span className="text-xs text-destructive">{briefsError}</span>}
                        </div>

                        {briefs.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No daily briefs yet. Click "Load briefs".</p>
                        ) : (
                            <div className="grid gap-3">
                                {briefs.map((brief) => (
                                    <div key={brief.id} className="rounded-2xl border border-border bg-card p-4 shadow-sm">
                                        <div className="mb-2 font-serif text-sm font-semibold italic">{brief.brief_date}</div>
                                        <div className="grid gap-3 md:grid-cols-2">
                                            <div className="grid gap-2">
                                                <h4 className="text-xs font-medium text-muted-foreground">Trends</h4>
                                                {brief.trends.map((trend, i) => (
                                                    <div key={i} className="rounded-xl border border-border bg-muted/20 p-2 shadow-sm">
                                                        <div className="text-sm font-medium">{trend.title}</div>
                                                        <p className="text-xs text-muted-foreground">{trend.summary}</p>
                                                    </div>
                                                ))}
                                            </div>
                                            <div className="grid gap-2">
                                                <h4 className="text-xs font-medium text-muted-foreground">Ideas</h4>
                                                {brief.ideas.map((idea, i) => (
                                                    <div key={i} className="rounded-xl border border-border bg-muted/20 p-2 shadow-sm">
                                                        <div className="text-sm font-medium">{idea.title}</div>
                                                        <p className="text-xs text-muted-foreground">{idea.description}</p>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </TabsContent>
                    )}
                </Tabs>
            </div>
        </>
    );
}

ClientShow.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
