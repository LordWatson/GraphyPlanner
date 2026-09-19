import { Form, Head, Link, router } from '@inertiajs/react';
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
    Link2,
    Linkedin,
    Mail,
    Music2,
    NotebookPen,
    Pencil,
    Receipt,
    Send,
    Share2,
    Trash2,
    UploadCloud,
    UserPlus,
    Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import { edit as editBrandBrain } from '@/routes/clients/brand-brain';
import { edit, index, destroy as destroyClient } from '@/routes/clients';
import { store as storeSocialAccount } from '@/routes/clients/social-accounts';
import { destroy as destroySocialAccount, connect as connectSocialAccount } from '@/routes/social-accounts';
import { store as storeCampaign } from '@/routes/clients/campaigns';
import { destroy as destroyCampaign } from '@/routes/campaigns';
import { store as storePost } from '@/routes/clients/posts';
import { edit as editPost } from '@/routes/posts';
import { store as storeAsset } from '@/routes/clients/assets';
import { destroy as destroyAsset } from '@/routes/assets';
import { store as storeInvoice } from '@/routes/clients/invoices';
import { show as showInvoice, send as sendInvoice, markPaid as markPaidInvoice } from '@/routes/invoices';
import { store as storeInvitation } from '@/routes/clients/invitations';
import { destroy as destroyInvitation } from '@/routes/invitations';

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
}) {
    const [activeTab, setActiveTab] = useState('social-accounts');
    const [assetSource, setAssetSource] = useState<'upload' | 'figma' | 'url'>('upload');
    const [selectedTargetAccounts, setSelectedTargetAccounts] = useState<number[]>([]);
    const [connectingAccountId, setConnectingAccountId] = useState<number | null>(null);

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
            ? { icon: Wallet, label: `${client.retainer_amount}${client.billing_cycle ? ` / ${client.billing_cycle}` : ''}` }
            : null,
    ].filter((chip): chip is { icon: typeof Globe; label: string } => chip !== null);

    const outstandingInvoiceTotal = invoices
        .filter((invoice) => invoice.status !== 'paid')
        .reduce((total, invoice) => total + Number(invoice.amount), 0);

    const statCards = [
        { icon: Share2, label: 'Social accounts', value: socialAccounts.length, hint: 'Connected platforms', tab: 'social-accounts' },
        { icon: Clapperboard, label: 'Campaigns', value: campaigns.length, hint: 'Total campaigns', tab: 'campaigns' },
        { icon: Send, label: 'Posts', value: posts.length, hint: 'Scheduled & published', tab: 'posts' },
        { icon: FileImage, label: 'Assets', value: assets.length, hint: 'Uploads & links on file', tab: 'assets' },
        ...(can.viewBilling
            ? [
                  {
                      icon: Receipt,
                      label: 'Outstanding',
                      value: outstandingInvoiceTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
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
    ];

    return (
        <>
            <Head title={client.name} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
                <div className="relative overflow-hidden rounded-xl border border-border p-6 shadow-lg shadow-primary/10">
                    <span aria-hidden className="pointer-events-none absolute inset-0 -z-10 bg-gradient-brand opacity-[0.14]" />
                    <div className="relative flex flex-col gap-4">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <Heading title={client.name} description={client.legal_name ?? undefined} />
                            <div className="flex items-center gap-2">
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
                                    <Form {...destroyClient.form(client.id)}>
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                <Trash2 />
                                                Delete
                                            </Button>
                                        )}
                                    </Form>
                                )}
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant={statusVariant[client.status] ?? 'outline'}>{client.status}</Badge>
                            <Badge variant={healthVariant[client.health] ?? 'outline'} title={client.health_reason}>
                                {client.health}
                            </Badge>
                            <span className="text-xs text-muted-foreground">{client.health_reason}</span>
                        </div>

                        {metaChips.length > 0 && (
                            <div className="flex flex-wrap items-center gap-3 text-sm">
                                {metaChips.map(({ icon: Icon, label }, index) => (
                                    <span
                                        key={`${label}-${index}`}
                                        className="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted/50 px-2.5 py-1 text-xs text-foreground"
                                    >
                                        <Icon className="size-3.5 text-primary" />
                                        {label}
                                    </span>
                                ))}
                            </div>
                        )}

                        {client.notes_internal && (
                            <div className="rounded-md border border-dashed border-border bg-muted/30 p-3">
                                <span className="text-xs font-medium text-muted-foreground">Internal notes</span>
                                <p className="mt-1 text-sm whitespace-pre-wrap">{client.notes_internal}</p>
                            </div>
                        )}
                    </div>
                </div>

                <div
                    className={cn(
                        'grid gap-4 sm:grid-cols-2',
                        statCards.length >= 6 ? 'lg:grid-cols-6' : statCards.length === 5 ? 'lg:grid-cols-5' : 'lg:grid-cols-4',
                    )}
                >
                    {statCards.map(({ icon: Icon, label, value, hint, tab }) => (
                        <button
                            type="button"
                            key={label}
                            onClick={() => setActiveTab(tab)}
                            aria-current={activeTab === tab}
                            className={`relative flex flex-col gap-2 overflow-hidden rounded-xl border p-4 text-left shadow-sm transition-colors ${
                                activeTab === tab ? 'border-primary bg-primary/5' : 'border-border bg-card hover:bg-muted/30'
                            }`}
                        >
                            <span aria-hidden className="absolute inset-x-0 top-0 h-1 bg-gradient-brand" />
                            <div className="flex items-center gap-2 text-muted-foreground">
                                <span className="flex size-8 items-center justify-center rounded-md bg-gradient-brand text-white shadow-sm">
                                    <Icon className="size-4" />
                                </span>
                                <span className="text-xs">{label}</span>
                            </div>
                            <span className="text-3xl font-bold tabular-nums">{value}</span>
                            <span className="text-xs text-muted-foreground">{hint}</span>
                        </button>
                    ))}
                </div>

                <Tabs value={activeTab} onValueChange={setActiveTab} className="gap-4">
                    <TabsList className="h-auto w-full flex-wrap justify-start gap-1 bg-muted/60 p-1">
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
                    </TabsList>

                    <TabsContent
                        value="social-accounts"
                        className="grid gap-4 rounded-lg border border-border bg-card p-4"
                    >
                        <Heading title="Social accounts" description="Platforms this client posts to" />

                        <>
                            <>
                                {can.createSocialAccount && (
                                    <Form
                                        {...storeSocialAccount.form(client.id)}
                                        resetOnSuccess
                                        className="grid grid-cols-2 gap-3 rounded-md border border-dashed border-border p-3 sm:grid-cols-4"
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
                                                    className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-muted/20 p-3"
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
                        className="grid gap-4 rounded-lg border border-border bg-card p-4"
                    >
                        <Heading title="Campaigns" description="Marketing campaigns for this client" />

                        <>
                            <>
                                {can.createCampaign && (
                                    <Form
                                        {...storeCampaign.form(client.id)}
                                        resetOnSuccess
                                        className="grid grid-cols-2 gap-3 rounded-md border border-dashed border-border p-3 sm:grid-cols-4"
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
                                                className="flex items-center justify-between gap-3 rounded-lg border border-border bg-muted/20 p-3"
                                            >
                                                <div className="flex items-center gap-3">
                                                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                                        <Clapperboard className="size-4" />
                                                    </span>
                                                    <div className="flex flex-col gap-1">
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-sm font-medium">{campaign.name}</span>
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
                                                </div>
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
                                        ))}
                                    </div>
                                )}
                            </>
                        </>
                    </TabsContent>

                    <TabsContent
                        value="posts"
                        className="grid gap-4 rounded-lg border border-border bg-card p-4"
                    >
                        <Heading title="Posts" description="Scheduled content across social accounts" />

                        <>
                            <>
                                {can.createPost && (
                                    <Form
                                        {...storePost.form(client.id)}
                                        resetOnSuccess
                                        onSuccess={() => setSelectedTargetAccounts([])}
                                        className="grid grid-cols-2 gap-3 rounded-md border border-dashed border-border p-3 sm:grid-cols-4"
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
                                                className="flex cursor-pointer flex-col gap-2 rounded-lg border border-border bg-muted/20 p-3 transition-colors hover:border-primary/50 hover:bg-muted/40"
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
                        className="grid gap-4 rounded-lg border border-border bg-card p-4"
                    >
                        <Heading title="Assets" description="Uploads and Figma/URL links for this client" />

                        <>
                            <>
                                {can.createAsset && (
                                    <Form
                                        {...storeAsset.form(client.id)}
                                        resetOnSuccess
                                        className="grid grid-cols-2 gap-3 rounded-md border border-dashed border-border p-3 sm:grid-cols-4"
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
                                                className="flex items-center justify-between gap-3 rounded-lg border border-border bg-muted/20 p-3"
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
                        className="grid gap-4 rounded-lg border border-border bg-card p-4"
                    >
                        <Heading title="Billing history" description="Invoices raised for this client" />

                        <>
                        {can.createInvoice && (
                            <Form
                                {...storeInvoice.form(client.id)}
                                resetOnSuccess
                                className="grid grid-cols-2 gap-3 rounded-md border border-dashed border-border p-3 sm:grid-cols-4"
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
                                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-muted/20 p-3"
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
                                                {invoice.currency} {invoice.amount}
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
                        className="grid gap-4 rounded-lg border border-border bg-card p-4"
                    >
                        <Heading title="Client portal access" description="Invite a named contact at this client to their own login" />

                        <>
                        {can.createInvitation && (
                            <Form
                                {...storeInvitation.form(client.id)}
                                resetOnSuccess
                                className="grid grid-cols-2 gap-3 rounded-md border border-dashed border-border p-3 sm:grid-cols-4"
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
                                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-muted/20 p-3"
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
                </Tabs>
            </div>
        </>
    );
}

ClientShow.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
