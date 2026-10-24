import { Form, Head, Link, router } from '@inertiajs/react';
import {
    BarChart3,
    Building2,
    CirclePause,
    CirclePlay,
    Settings2,
    Trash2,
    UserRound,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import OrganizationController from '@/actions/App/Http/Controllers/SuperAdmin/OrganizationController';
import { confirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import {
    EmptyState,
    Page,
    PageHeader,
    Panel,
    StatStrip,
} from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/dates';
import {
    analytics as organizationAnalytics,
    index as organizationsIndex,
} from '@/routes/super-admin/organizations';

type Option = { value: string; label: string };

type OrganizationDetail = {
    id: number;
    name: string;
    slug: string;
    default_timezone: string;
    default_currency: string;
    ai_monthly_image_quota: number | null;
    created_at: string | null;
    is_suspended: boolean;
    suspended_at: string | null;
    suspension_reason: string | null;
    suspended_by: string | null;
    is_preview: boolean;
    is_design_partner: boolean;
    has_upload_post_key: boolean;
    owner: { id: number; name: string; email: string } | null;
};

type Member = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    role_label: string | null;
    email_verified: boolean;
    created_at: string | null;
};

type ClientRow = {
    id: number;
    name: string;
    status: string;
    status_label: string;
    created_at: string | null;
};

type Props = {
    organization: OrganizationDetail;
    members: Member[];
    clients: ClientRow[];
    counts: {
        users: number;
        clients: number;
        active_clients: number;
        posts: number;
        published_posts: number;
        scheduled_posts: number;
        invoices: number;
        connected_accounts: number;
    };
    last_published_at: string | null;
    timezones: Option[];
    currencies: Option[];
};

/**
 * Step 15.3 — one organization, from the platform's side: headline counts, editable details,
 * suspension (with a reason its members will see) and permanent deletion, plus its team and
 * clients. The preview fixture can't be suspended or deleted.
 */
export default function ShowOrganization({
    organization,
    members,
    clients,
    counts,
    last_published_at: lastPublishedAt,
    timezones,
    currencies,
}: Props) {
    const [timezone, setTimezone] = useState(organization.default_timezone);
    const [currency, setCurrency] = useState(organization.default_currency);
    const [designPartner, setDesignPartner] = useState(
        organization.is_design_partner,
    );

    const reinstate = async () => {
        if (
            !(await confirmDialog({
                title: `Reinstate ${organization.name}?`,
                description:
                    'Everyone on the team can sign in again straight away, and scheduled work resumes.',
                confirmLabel: 'Reinstate',
            }))
        ) {
            return;
        }

        router.post(
            OrganizationController.reinstate.url(organization.id),
            undefined,
            {
                preserveScroll: true,
            },
        );
    };

    const status = organization.is_suspended
        ? 'suspended'
        : organization.is_preview
          ? 'preview'
          : 'active';

    return (
        <>
            <Head title={organization.name} />
            <Page>
                <PageHeader
                    eyebrow={`Since ${formatDate(organization.created_at)}`}
                    title={organization.name}
                    description={
                        organization.owner
                            ? `Owned by ${organization.owner.name} · ${organization.owner.email}`
                            : 'This organization has no owner.'
                    }
                    meta={
                        <>
                            <StatusPill kind="organization" value={status} />
                            {organization.is_design_partner && (
                                <span className="bg-lime/15 text-foreground border-lime/40 eyebrow inline-flex items-center rounded-full border px-2.5 py-0.5">
                                    Design partner
                                </span>
                            )}
                            <span className="text-muted-foreground font-mono text-xs">
                                /{organization.slug}
                            </span>
                        </>
                    }
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link
                                    href={organizationAnalytics(
                                        organization.id,
                                    )}
                                >
                                    <BarChart3 />
                                    Analytics
                                </Link>
                            </Button>
                            {!organization.is_preview &&
                                (organization.is_suspended ? (
                                    <Button
                                        variant="secondary"
                                        onClick={reinstate}
                                    >
                                        <CirclePlay />
                                        Reinstate
                                    </Button>
                                ) : (
                                    <SuspendDialog
                                        organization={organization}
                                    />
                                ))}
                        </>
                    }
                />

                {organization.is_suspended && (
                    <section className="bg-ink text-ink-foreground flex flex-col gap-2 rounded-2xl p-6">
                        <span className="eyebrow text-cream/60">
                            Suspended{' '}
                            {formatDateTime(organization.suspended_at)}
                            {organization.suspended_by
                                ? ` by ${organization.suspended_by}`
                                : ''}
                        </span>
                        <p className="text-[17px] leading-relaxed font-light text-pretty">
                            {organization.suspension_reason ? (
                                <>
                                    <span
                                        aria-hidden
                                        className="text-lime mr-1"
                                    >
                                        “
                                    </span>
                                    {organization.suspension_reason}
                                </>
                            ) : (
                                'No reason was given. Members see a “contact support” page.'
                            )}
                        </p>
                    </section>
                )}

                <StatStrip
                    className="animate-rise"
                    items={[
                        {
                            label: 'People',
                            value: String(counts.users).padStart(2, '0'),
                            caption: 'Staff and client reviewers',
                        },
                        {
                            label: 'Active clients',
                            value: String(counts.active_clients).padStart(
                                2,
                                '0',
                            ),
                            caption: `of ${counts.clients} on the roster`,
                        },
                        {
                            label: 'Published',
                            value: String(counts.published_posts).padStart(
                                2,
                                '0',
                            ),
                            caption: lastPublishedAt
                                ? `Last ${formatDate(lastPublishedAt)}`
                                : 'Nothing live yet',
                        },
                        {
                            label: 'Scheduled',
                            value: String(counts.scheduled_posts).padStart(
                                2,
                                '0',
                            ),
                            caption: `${counts.connected_accounts} connected accounts`,
                        },
                    ]}
                />

                <div className="grid gap-5 lg:grid-cols-[1.45fr_1fr]">
                    <Panel
                        eyebrow="Core details"
                        title="Organization"
                        icon={Settings2}
                    >
                        <Form
                            {...OrganizationController.update.form(
                                organization.id,
                            )}
                            options={{ preserveScroll: true }}
                            className="grid gap-5"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-5 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="name">Name</Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                defaultValue={organization.name}
                                                required
                                            />
                                            <InputError message={errors.name} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="slug">Slug</Label>
                                            <Input
                                                id="slug"
                                                name="slug"
                                                defaultValue={organization.slug}
                                                readOnly={
                                                    organization.is_preview
                                                }
                                                required
                                            />
                                            <InputError message={errors.slug} />
                                        </div>
                                    </div>
                                    <div className="grid gap-5 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="default_timezone">
                                                Timezone
                                            </Label>
                                            <input
                                                type="hidden"
                                                name="default_timezone"
                                                value={timezone}
                                            />
                                            <Select
                                                value={timezone}
                                                onValueChange={setTimezone}
                                            >
                                                <SelectTrigger
                                                    id="default_timezone"
                                                    className="w-full"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {timezones.map((option) => (
                                                        <SelectItem
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={
                                                    errors.default_timezone
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="default_currency">
                                                Currency
                                            </Label>
                                            <input
                                                type="hidden"
                                                name="default_currency"
                                                value={currency}
                                            />
                                            <Select
                                                value={currency}
                                                onValueChange={setCurrency}
                                            >
                                                <SelectTrigger
                                                    id="default_currency"
                                                    className="w-full"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {currencies.map(
                                                        (option) => (
                                                            <SelectItem
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.value} —{' '}
                                                                {option.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={
                                                    errors.default_currency
                                                }
                                            />
                                        </div>
                                    </div>
                                    <div className="grid gap-2 sm:max-w-xs">
                                        <Label htmlFor="ai_monthly_image_quota">
                                            Monthly AI image quota
                                        </Label>
                                        <Input
                                            id="ai_monthly_image_quota"
                                            name="ai_monthly_image_quota"
                                            type="number"
                                            min={0}
                                            defaultValue={
                                                organization.ai_monthly_image_quota ??
                                                ''
                                            }
                                            placeholder="Platform default"
                                        />
                                        <InputError
                                            message={
                                                errors.ai_monthly_image_quota
                                            }
                                        />
                                    </div>
                                    <label className="border-border/80 hover:bg-muted/40 flex items-start gap-3 rounded-xl border p-4 transition-colors">
                                        <input
                                            type="hidden"
                                            name="is_design_partner"
                                            value={designPartner ? '1' : '0'}
                                        />
                                        <Checkbox
                                            id="is_design_partner"
                                            checked={designPartner}
                                            onCheckedChange={(checked) =>
                                                setDesignPartner(
                                                    checked === true,
                                                )
                                            }
                                            className="mt-0.5"
                                        />
                                        <span className="flex flex-col gap-0.5">
                                            <span className="text-sm font-medium">
                                                Design partner
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                On the design-partner offer.
                                                Shows in the partner usage view,
                                                and gets the design-partner
                                                coupon when billing goes live.
                                            </span>
                                        </span>
                                    </label>
                                    <div className="flex items-center justify-between gap-3 pt-1">
                                        <span className="text-muted-foreground text-xs">
                                            API keys and invoicing stay with the
                                            organization’s owner.
                                        </span>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            Save details
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </Panel>

                    <div className="flex flex-col gap-5">
                        <Panel
                            eyebrow="Primary contact"
                            title="Owner"
                            icon={UserRound}
                        >
                            {organization.owner ? (
                                <div className="flex flex-col gap-1">
                                    <span className="font-medium">
                                        {organization.owner.name}
                                    </span>
                                    <a
                                        href={`mailto:${organization.owner.email}`}
                                        className="text-primary text-sm underline-offset-4 hover:underline"
                                    >
                                        {organization.owner.email}
                                    </a>
                                </div>
                            ) : (
                                <p className="text-muted-foreground text-sm">
                                    No owner on this organization.
                                </p>
                            )}
                            <dl className="divide-border/70 mt-4 flex flex-col divide-y text-sm">
                                <div className="flex justify-between gap-3 py-2">
                                    <dt className="text-muted-foreground">
                                        Invoices raised
                                    </dt>
                                    <dd className="font-mono tabular-nums">
                                        {counts.invoices}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-3 py-2">
                                    <dt className="text-muted-foreground">
                                        Posts, all stages
                                    </dt>
                                    <dd className="font-mono tabular-nums">
                                        {counts.posts}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-3 py-2">
                                    <dt className="text-muted-foreground">
                                        Publishing key
                                    </dt>
                                    <dd>
                                        <StatusPill
                                            kind="connection"
                                            value={
                                                organization.has_upload_post_key
                                                    ? 'connected'
                                                    : 'not_connected'
                                            }
                                            label={
                                                organization.has_upload_post_key
                                                    ? 'Set'
                                                    : 'Not set'
                                            }
                                        />
                                    </dd>
                                </div>
                            </dl>
                        </Panel>

                        {!organization.is_preview && (
                            <Panel
                                eyebrow="Danger zone"
                                title="Delete organization"
                                icon={Trash2}
                            >
                                <p className="text-muted-foreground text-sm text-pretty">
                                    Permanently removes the organization, its{' '}
                                    {counts.users}{' '}
                                    {counts.users === 1 ? 'member' : 'members'},
                                    clients, posts and invoices. This can’t be
                                    undone — suspend instead if you might need
                                    it back.
                                </p>
                                <DeleteDialog organization={organization} />
                            </Panel>
                        )}
                    </div>
                </div>

                <div className="grid gap-5 lg:grid-cols-2">
                    <Panel
                        eyebrow={`${members.length} with access`}
                        title="Team"
                        icon={UsersRound}
                        bodyClassName="p-0 sm:p-0"
                    >
                        {members.length === 0 ? (
                            <div className="p-5 sm:p-6">
                                <EmptyState
                                    compact
                                    icon={UsersRound}
                                    title="No one here."
                                    description="This organization has no members."
                                />
                            </div>
                        ) : (
                            <ul className="divide-border/70 divide-y">
                                {members.map((member, index) => (
                                    <li
                                        key={member.id}
                                        className="flex items-center gap-3 px-5 py-3 sm:px-6"
                                    >
                                        <span className="text-muted-foreground w-5 font-mono text-[11px] tabular-nums">
                                            {String(index + 1).padStart(2, '0')}
                                        </span>
                                        <span className="flex min-w-0 flex-1 flex-col">
                                            <span className="truncate text-sm font-medium">
                                                {member.name}
                                            </span>
                                            <span className="text-muted-foreground truncate text-xs">
                                                {member.email}
                                                {!member.email_verified &&
                                                    ' · unverified'}
                                            </span>
                                        </span>
                                        {member.role && (
                                            <StatusPill
                                                kind="role"
                                                value={member.role}
                                                label={member.role_label}
                                                className="shrink-0"
                                            />
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel
                        eyebrow={`${counts.clients} on the roster`}
                        title="Clients"
                        icon={Building2}
                        bodyClassName="p-0 sm:p-0"
                    >
                        {clients.length === 0 ? (
                            <div className="p-5 sm:p-6">
                                <EmptyState
                                    compact
                                    icon={Building2}
                                    title="No clients yet."
                                    description="Clients this organization adds show up here."
                                />
                            </div>
                        ) : (
                            <ul className="divide-border/70 divide-y">
                                {clients.map((client) => (
                                    <li
                                        key={client.id}
                                        className="flex items-center gap-3 px-5 py-3 sm:px-6"
                                    >
                                        <span className="min-w-0 flex-1 truncate text-sm font-medium">
                                            {client.name}
                                        </span>
                                        <span className="text-muted-foreground hidden font-mono text-xs sm:inline">
                                            {formatDate(client.created_at)}
                                        </span>
                                        <StatusPill
                                            kind="client"
                                            value={client.status}
                                            label={client.status_label}
                                            className="shrink-0"
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>
            </Page>
        </>
    );
}

function SuspendDialog({ organization }: { organization: OrganizationDetail }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <CirclePause />
                    Suspend
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Suspend {organization.name}?</DialogTitle>
                    <DialogDescription>
                        Everyone on the team is locked out and sees a “contact
                        support” page. Scheduled posts, reminders and AI briefs
                        pause. Nothing is deleted — you can reinstate it any
                        time.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...OrganizationController.suspend.form(organization.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="reason">
                                    Reason{' '}
                                    <span className="text-muted-foreground font-normal">
                                        (shown to its members)
                                    </span>
                                </Label>
                                <Textarea
                                    id="reason"
                                    name="reason"
                                    rows={3}
                                    maxLength={500}
                                    placeholder="e.g. Your subscription payment didn’t go through."
                                />
                                <InputError
                                    message={
                                        errors.reason ?? errors.organization
                                    }
                                />
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                >
                                    Keep it running
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Suspend organization
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteDialog({ organization }: { organization: OrganizationDetail }) {
    const [open, setOpen] = useState(false);
    const [confirmName, setConfirmName] = useState('');
    const matches =
        confirmName.trim().toLowerCase() ===
        organization.name.trim().toLowerCase();

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setConfirmName('');
            }}
        >
            <DialogTrigger asChild>
                <Button variant="destructive" className="mt-4">
                    <Trash2 />
                    Delete organization
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Delete {organization.name} for good?
                    </DialogTitle>
                    <DialogDescription>
                        Every member account, client, post, asset record and
                        invoice in this organization is removed. There’s no
                        undo.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...OrganizationController.destroy.form(organization.id)}
                    className="flex flex-col gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="confirm_name">
                                    Type{' '}
                                    <span className="font-semibold">
                                        {organization.name}
                                    </span>{' '}
                                    to confirm
                                </Label>
                                <Input
                                    id="confirm_name"
                                    name="confirm_name"
                                    autoComplete="off"
                                    value={confirmName}
                                    onChange={(event) =>
                                        setConfirmName(event.target.value)
                                    }
                                />
                                <InputError
                                    message={
                                        errors.confirm_name ??
                                        errors.organization
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="delete_org_password">
                                    Your password
                                </Label>
                                <Input
                                    id="delete_org_password"
                                    name="password"
                                    type="password"
                                    autoComplete="current-password"
                                    required
                                />
                                <InputError message={errors.password} />
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={!matches || processing}
                                >
                                    {processing && <Spinner />}
                                    Delete permanently
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

ShowOrganization.layout = {
    breadcrumbs: [{ title: 'Organizations', href: organizationsIndex() }],
};
