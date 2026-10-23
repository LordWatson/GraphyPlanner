import { Form, Head, Link } from '@inertiajs/react';
import { Building2, MailCheck, UserRound } from 'lucide-react';
import { useState } from 'react';
import OrganizationController from '@/actions/App/Http/Controllers/SuperAdmin/OrganizationController';
import InputError from '@/components/input-error';
import { Page, PageHeader, Panel } from '@/components/page';
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
import {
    create as createOrganization,
    index as organizationsIndex,
} from '@/routes/super-admin/organizations';

type Option = { value: string; label: string };

/**
 * Step 15.3 — a super admin onboards an organization on a customer's behalf. The same
 * `RegisterOrganizationAction` as the public signup runs underneath; the owner gets a welcome
 * email with a link to choose their own password.
 */
export default function CreateOrganization({
    timezones,
    currencies,
    organizationTypes,
}: {
    timezones: Option[];
    currencies: Option[];
    organizationTypes: Option[];
}) {
    const [timezone, setTimezone] = useState('UTC');
    const [currency, setCurrency] = useState('USD');
    const [type, setType] = useState(organizationTypes[0]?.value ?? 'agency');

    return (
        <>
            <Head title="Onboard an organization" />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Onboarding"
                    title={
                        <>
                            Onboard an <em>organization</em>.
                        </>
                    }
                    description="Set up a workspace for a customer. Its owner gets a welcome email with a link to choose their password — no one else ever sees it."
                />

                <Form
                    {...OrganizationController.store.form()}
                    disableWhileProcessing
                    className="flex flex-col gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <Panel
                                eyebrow="01"
                                title="The organization"
                                icon={Building2}
                            >
                                <div className="grid gap-5">
                                    <div className="grid gap-2">
                                        <Label htmlFor="organization_name">
                                            Organization name
                                        </Label>
                                        <Input
                                            id="organization_name"
                                            name="organization_name"
                                            required
                                            autoFocus
                                            placeholder="e.g. Northlight Studio"
                                        />
                                        <InputError
                                            message={errors.organization_name}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="type">
                                            Workspace type
                                        </Label>
                                        <input
                                            type="hidden"
                                            name="type"
                                            value={type}
                                        />
                                        <Select
                                            value={type}
                                            onValueChange={setType}
                                        >
                                            <SelectTrigger
                                                id="type"
                                                className="w-full"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {organizationTypes.map(
                                                    (option) => (
                                                        <SelectItem
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.type} />
                                        <p className="text-muted-foreground text-xs text-pretty">
                                            A business workspace gets one brand
                                            created with it; an agency manages
                                            many clients.
                                        </p>
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
                                </div>
                            </Panel>

                            <Panel
                                eyebrow="02"
                                title="Its owner"
                                icon={UserRound}
                            >
                                <div className="grid gap-5 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Full name</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            autoComplete="off"
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            required
                                            autoComplete="off"
                                            placeholder="owner@studio.com"
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                </div>
                                <p className="text-muted-foreground mt-5 flex items-start gap-2 text-xs text-pretty">
                                    <MailCheck className="mt-px size-4 shrink-0" />
                                    The welcome email doubles as their email
                                    verification — the set-password link is the
                                    only way in.
                                </p>
                            </Panel>

                            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                <Button variant="outline" asChild>
                                    <Link href={organizationsIndex()}>
                                        Cancel
                                    </Link>
                                </Button>
                                <Button type="submit">
                                    {processing && <Spinner />}
                                    Create organization
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}

CreateOrganization.layout = {
    breadcrumbs: [
        { title: 'Organizations', href: organizationsIndex() },
        { title: 'Onboard', href: createOrganization() },
    ],
};
