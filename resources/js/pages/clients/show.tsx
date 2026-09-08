import { Form, Head, Link } from '@inertiajs/react';
import { ChevronDown, NotebookPen, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import AssetController from '@/actions/App/Http/Controllers/AssetController';
import CampaignController from '@/actions/App/Http/Controllers/CampaignController';
import ClientController from '@/actions/App/Http/Controllers/ClientController';
import InvoiceController from '@/actions/App/Http/Controllers/InvoiceController';
import SocialAccountController from '@/actions/App/Http/Controllers/SocialAccountController';
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
import { edit as editBrandBrain } from '@/routes/clients/brand-brain';
import { edit, index } from '@/routes/clients';

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
    can: { send: boolean; mark_paid: boolean };
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
    can: { update: boolean; delete: boolean };
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

export default function ClientShow({
    client,
    can,
    invoices,
    socialAccounts,
    campaigns,
    assets,
}: {
    client: ClientData;
    can: {
        update: boolean;
        delete: boolean;
        createInvoice: boolean;
        createSocialAccount: boolean;
        createCampaign: boolean;
        createAsset: boolean;
    };
    invoices: InvoiceData[];
    socialAccounts: SocialAccountData[];
    campaigns: CampaignData[];
    assets: AssetData[];
}) {
    const [billingHistoryOpen, setBillingHistoryOpen] = useState(false);
    const [socialAccountsOpen, setSocialAccountsOpen] = useState(false);
    const [campaignsOpen, setCampaignsOpen] = useState(false);
    const [assetsOpen, setAssetsOpen] = useState(false);
    const [assetSource, setAssetSource] = useState<'upload' | 'figma' | 'url'>('upload');

    return (
        <>
            <Head title={client.name} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
                <div className="flex items-start justify-between">
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
                            <Form {...ClientController.destroy.form(client.id)}>
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

                <div className="grid gap-4 rounded-lg border border-border bg-card p-4">
                    <div className="flex flex-wrap items-center gap-4">
                        <div className="flex items-center gap-2">
                            <span className="text-xs text-muted-foreground">Status</span>
                            <Badge variant={statusVariant[client.status] ?? 'outline'}>
                                {client.status}
                            </Badge>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="text-xs text-muted-foreground">Health</span>
                            <Badge
                                variant={healthVariant[client.health] ?? 'outline'}
                                title={client.health_reason}
                            >
                                {client.health}
                            </Badge>
                            <span className="text-xs text-muted-foreground">{client.health_reason}</span>
                        </div>
                    </div>

                    <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <div>
                            <dt className="text-xs text-muted-foreground">Website</dt>
                            <dd>{client.website ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">Industry</dt>
                            <dd>{client.industry ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">Countries</dt>
                            <dd>{client.countries?.join(', ') || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">Default language</dt>
                            <dd>{client.default_language ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">Start date</dt>
                            <dd>{client.start_date ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">Approval email</dt>
                            <dd>{client.approval_email ?? '—'}</dd>
                        </div>
                        {client.retainer_amount !== null && (
                            <div>
                                <dt className="text-xs text-muted-foreground">Retainer amount</dt>
                                <dd className="tabular-nums">{client.retainer_amount}</dd>
                            </div>
                        )}
                        {client.billing_cycle !== null && (
                            <div>
                                <dt className="text-xs text-muted-foreground">Billing cycle</dt>
                                <dd>{client.billing_cycle}</dd>
                            </div>
                        )}
                    </dl>

                    {client.notes_internal && (
                        <dl>
                            <dt className="text-xs text-muted-foreground">Internal notes</dt>
                            <dd className="mt-1 text-sm whitespace-pre-wrap">{client.notes_internal}</dd>
                        </dl>
                    )}
                </div>

                {socialAccounts !== undefined && (
                    <div className="grid gap-4 rounded-lg border border-border bg-card p-4">
                        <button
                            type="button"
                            className="flex w-full items-center justify-between gap-2 text-left"
                            onClick={() => setSocialAccountsOpen((open) => !open)}
                            aria-expanded={socialAccountsOpen}
                        >
                            <Heading title="Social accounts" description="Platforms this client posts to" />
                            <ChevronDown
                                className={`size-4 shrink-0 text-muted-foreground transition-transform ${socialAccountsOpen ? 'rotate-180' : ''}`}
                            />
                        </button>

                        {socialAccountsOpen && (
                            <>
                                {can.createSocialAccount && (
                                    <Form
                                        {...SocialAccountController.store.form(client.id)}
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
                                            <Input id="timezone" name="timezone" placeholder="Europe/Amsterdam" required />
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
                                    <div className="flex flex-col divide-y divide-border">
                                        {socialAccounts.map((account) => (
                                            <div
                                                key={account.id}
                                                className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                            >
                                                <div className="flex flex-col gap-1">
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-sm font-medium">{account.handle}</span>
                                                        <Badge variant="outline">{account.platform}</Badge>
                                                        <Badge variant={connectionStatusVariant[account.connection_status] ?? 'outline'}>
                                                            {account.connection_status}
                                                        </Badge>
                                                    </div>
                                                    <span className="text-xs text-muted-foreground">
                                                        {account.display_name ?? account.handle} · {account.timezone}
                                                    </span>
                                                </div>
                                                {account.can.delete && (
                                                    <Form {...SocialAccountController.destroy.form(account.id)}>
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
                        )}
                    </div>
                )}

                {campaigns !== undefined && (
                    <div className="grid gap-4 rounded-lg border border-border bg-card p-4">
                        <button
                            type="button"
                            className="flex w-full items-center justify-between gap-2 text-left"
                            onClick={() => setCampaignsOpen((open) => !open)}
                            aria-expanded={campaignsOpen}
                        >
                            <Heading title="Campaigns" description="Marketing campaigns for this client" />
                            <ChevronDown
                                className={`size-4 shrink-0 text-muted-foreground transition-transform ${campaignsOpen ? 'rotate-180' : ''}`}
                            />
                        </button>

                        {campaignsOpen && (
                            <>
                                {can.createCampaign && (
                                    <Form
                                        {...CampaignController.store.form(client.id)}
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
                                    <div className="flex flex-col divide-y divide-border">
                                        {campaigns.map((campaign) => (
                                            <div
                                                key={campaign.id}
                                                className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                            >
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
                                                {campaign.can.delete && (
                                                    <Form {...CampaignController.destroy.form(campaign.id)}>
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
                        )}
                    </div>
                )}

                {assets !== undefined && (
                    <div className="grid gap-4 rounded-lg border border-border bg-card p-4">
                        <button
                            type="button"
                            className="flex w-full items-center justify-between gap-2 text-left"
                            onClick={() => setAssetsOpen((open) => !open)}
                            aria-expanded={assetsOpen}
                        >
                            <Heading title="Assets" description="Uploads and Figma/URL links for this client" />
                            <ChevronDown
                                className={`size-4 shrink-0 text-muted-foreground transition-transform ${assetsOpen ? 'rotate-180' : ''}`}
                            />
                        </button>

                        {assetsOpen && (
                            <>
                                {can.createAsset && (
                                    <Form
                                        {...AssetController.store.form(client.id)}
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
                                                        <Input id="file" name="file" type="file" />
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
                                    <div className="flex flex-col divide-y divide-border">
                                        {assets.map((asset) => (
                                            <div
                                                key={asset.id}
                                                className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                            >
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
                                                {asset.can.delete && (
                                                    <Form {...AssetController.destroy.form(asset.id)}>
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
                        )}
                    </div>
                )}

                {invoices !== undefined && (
                    <div className="grid gap-4 rounded-lg border border-border bg-card p-4">
                        <button
                            type="button"
                            className="flex w-full items-center justify-between gap-2 text-left"
                            onClick={() => setBillingHistoryOpen((open) => !open)}
                            aria-expanded={billingHistoryOpen}
                        >
                            <Heading title="Billing history" description="Invoices raised for this client" />
                            <ChevronDown
                                className={`size-4 shrink-0 text-muted-foreground transition-transform ${billingHistoryOpen ? 'rotate-180' : ''}`}
                            />
                        </button>

                        {billingHistoryOpen && (
                        <>
                        {can.createInvoice && (
                            <Form
                                {...InvoiceController.store.form(client.id)}
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
                            <div className="flex flex-col divide-y divide-border">
                                {invoices.map((invoice) => (
                                    <div
                                        key={invoice.id}
                                        className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                    >
                                        <div className="flex flex-col gap-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium">{invoice.invoice_number}</span>
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
                                        <div className="flex items-center gap-3">
                                            <span className="tabular-nums text-sm font-medium">
                                                {invoice.currency} {invoice.amount}
                                            </span>
                                            {invoice.can.send && (
                                                <Form {...InvoiceController.send.form(invoice.id)}>
                                                    {({ processing }) => (
                                                        <Button type="submit" size="sm" variant="outline" disabled={processing}>
                                                            Send
                                                        </Button>
                                                    )}
                                                </Form>
                                            )}
                                            {invoice.can.mark_paid && (
                                                <Form {...InvoiceController.markPaid.form(invoice.id)}>
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
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

ClientShow.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
