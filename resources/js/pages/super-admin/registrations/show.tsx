import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Building2,
    CalendarPlus,
    Mail,
    Phone,
} from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Page, PageHeader, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/dates';
import { create as createOrganization } from '@/routes/super-admin/organizations';
import { index, update } from '@/routes/super-admin/registrations';

type Registration = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    company_name: string;
    location: string | null;
    country: string | null;
    status: string;
    source: string;
    utm_source: string | null;
    demo_at: string | null;
    contacted_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    notes: string | null;
    ip_address: string | null;
    city: string | null;
    timezone: string | null;
    locale: string | null;
    user_agent: string | null;
    referrer: string | null;
    utm_medium: string | null;
    utm_campaign: string | null;
    referral_code: string | null;
};

type Props = {
    registration: Registration;
    statuses: { value: string; label: string }[];
};

/** ISO instant → the viewer's local `YYYY-MM-DDTHH:mm` for a `datetime-local` input. */
function toLocalInput(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);
    const offset = date.getTimezoneOffset() * 60_000;

    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

/** A prefilled Google Calendar invite for the demo, with the applicant as a guest. */
function calendarInviteUrl(registration: Registration, demoAt: string): string {
    const params = new URLSearchParams({
        action: 'TEMPLATE',
        text: `Alima demo — ${registration.company_name}`,
        add: registration.email,
        details: `A walkthrough of Alima for ${registration.name} at ${registration.company_name}.`,
    });

    if (demoAt) {
        const start = new Date(demoAt);
        const end = new Date(start.getTime() + 30 * 60_000);
        const stamp = (date: Date) =>
            date.toISOString().replace(/[-:]|\.\d{3}/g, '');

        params.set('dates', `${stamp(start)}/${stamp(end)}`);
    }

    return `https://calendar.google.com/calendar/render?${params}`;
}

function Detail({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="flex flex-col gap-1">
            <dt className="eyebrow text-muted-foreground">{label}</dt>
            <dd className="text-sm break-words">{value || '—'}</dd>
        </div>
    );
}

/**
 * One registration: contact them, book the demo, keep notes, and move them through the pipeline.
 * When they're ready, the organization itself is created from Organizations → onboard.
 */
export default function RegistrationShow({ registration, statuses }: Props) {
    const form = useForm({
        status: registration.status,
        demo_at: toLocalInput(registration.demo_at),
        notes: registration.notes ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            demo_at: data.demo_at ? new Date(data.demo_at).toISOString() : null,
        }));
        form.put(update.url(registration.id), { preserveScroll: true });
    };

    const firstName = registration.name.split(' ')[0];

    return (
        <>
            <Head title={registration.company_name} />
            <Page>
                <PageHeader
                    eyebrow={`Registered ${formatDateTime(registration.created_at)}`}
                    title={registration.company_name}
                    description={`${registration.name}${registration.location ? ` · ${registration.location}` : ''}`}
                    meta={
                        <StatusPill
                            kind="registration"
                            value={registration.status}
                        />
                    }
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={index()}>
                                <ArrowLeft />
                                All registrations
                            </Link>
                        </Button>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-[1.2fr_1fr]">
                    <Panel title="Work this lead" eyebrow="Pipeline">
                        <form onSubmit={submit} className="flex flex-col gap-5">
                            <div className="grid gap-5 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="status">Status</Label>
                                    <Select
                                        value={form.data.status}
                                        onValueChange={(value) =>
                                            form.setData('status', value)
                                        }
                                    >
                                        <SelectTrigger id="status">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {statuses.map((status) => (
                                                <SelectItem
                                                    key={status.value}
                                                    value={status.value}
                                                >
                                                    {status.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={form.errors.status} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="demo_at">Demo</Label>
                                    <Input
                                        id="demo_at"
                                        type="datetime-local"
                                        value={form.data.demo_at}
                                        onChange={(event) =>
                                            form.setData(
                                                'demo_at',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={form.errors.demo_at} />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea
                                    id="notes"
                                    rows={6}
                                    value={form.data.notes}
                                    onChange={(event) =>
                                        form.setData(
                                            'notes',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Clients, channels, what they need, how the call went…"
                                />
                                <InputError message={form.errors.notes} />
                            </div>
                            <div className="flex flex-wrap items-center gap-3">
                                <Button type="submit" disabled={form.processing}>
                                    {form.processing && <Spinner />}
                                    Save
                                </Button>
                                {form.recentlySuccessful && (
                                    <span className="text-muted-foreground text-sm">
                                        Saved.
                                    </span>
                                )}
                            </div>
                        </form>
                    </Panel>

                    <Panel title="Get in touch" eyebrow="Contact">
                        <div className="flex flex-col gap-4">
                            <dl className="grid gap-4">
                                <Detail label="Name" value={registration.name} />
                                <Detail label="Email" value={registration.email} />
                                <Detail label="Phone" value={registration.phone} />
                                <Detail
                                    label="First contact"
                                    value={
                                        registration.contacted_at
                                            ? formatDateTime(
                                                  registration.contacted_at,
                                              )
                                            : 'Not yet'
                                    }
                                />
                            </dl>
                            <div className="flex flex-wrap gap-2">
                                <Button asChild>
                                    <a
                                        href={`mailto:${registration.email}?subject=${encodeURIComponent(`Alima x ${registration.company_name}`)}&body=${encodeURIComponent(`Hi ${firstName},\n\nThanks for registering your interest in Alima! `)}`}
                                    >
                                        <Mail />
                                        Email
                                    </a>
                                </Button>
                                {registration.phone && (
                                    <Button asChild variant="outline">
                                        <a href={`tel:${registration.phone}`}>
                                            <Phone />
                                            Call
                                        </a>
                                    </Button>
                                )}
                                <Button asChild variant="outline">
                                    <a
                                        href={calendarInviteUrl(
                                            registration,
                                            form.data.demo_at,
                                        )}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <CalendarPlus />
                                        Calendar invite
                                    </a>
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href={createOrganization()}>
                                        <Building2 />
                                        Onboard
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </Panel>
                </div>

                <Panel
                    title="Where they came from"
                    eyebrow="Visit details"
                    description="Captured when the form was sent. Country and city come from Cloudflare when the site is behind it."
                >
                    <dl className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <Detail label="Source" value={registration.source} />
                        <Detail label="Referrer" value={registration.referrer} />
                        <Detail
                            label="Campaign"
                            value={
                                [
                                    registration.utm_source,
                                    registration.utm_medium,
                                    registration.utm_campaign,
                                ]
                                    .filter(Boolean)
                                    .join(' / ') || null
                            }
                        />
                        <Detail
                            label="Referral code"
                            value={registration.referral_code}
                        />
                        <Detail label="IP address" value={registration.ip_address} />
                        <Detail
                            label="Country / city"
                            value={
                                [registration.country, registration.city]
                                    .filter(Boolean)
                                    .join(' · ') || null
                            }
                        />
                        <Detail label="Timezone" value={registration.timezone} />
                        <Detail label="Language" value={registration.locale} />
                        <div className="sm:col-span-2 lg:col-span-4">
                            <Detail
                                label="Browser"
                                value={registration.user_agent}
                            />
                        </div>
                    </dl>
                </Panel>
            </Page>
        </>
    );
}
