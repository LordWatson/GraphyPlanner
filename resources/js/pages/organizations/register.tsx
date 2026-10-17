import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import RegistrationController from '@/actions/App/Http/Controllers/Organizations/RegistrationController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
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
import { login } from '@/routes';

type Option = { value: string; label: string };

type Props = {
    timezones: Option[];
    currencies: Option[];
    passwordRules: string;
};

/** The visitor's own IANA zone when we offer it, so most people never touch the field. */
function detectTimezone(timezones: Option[]): string {
    try {
        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        if (timezones.some((option) => option.value === zone)) {
            return zone;
        }
    } catch {
        // Fall through to UTC.
    }

    return 'UTC';
}

function SectionLabel({
    number,
    children,
}: {
    number: string;
    children: string;
}) {
    return (
        <div className="border-foreground/85 flex items-baseline gap-2.5 border-t pt-3">
            <span className="text-muted-foreground font-mono text-[11px] tabular-nums">
                {number}
            </span>
            <span className="eyebrow text-foreground">{children}</span>
        </div>
    );
}

/**
 * Step 15.2.2 — the public "create your organization" signup. One form, two short sections: the
 * studio (name, timezone, currency) and its first Owner. On success the new Owner is signed in
 * and lands in the studio, behind Fortify's email verification.
 */
export default function RegisterOrganization({
    timezones,
    currencies,
    passwordRules,
}: Props) {
    const [timezone, setTimezone] = useState(() => detectTimezone(timezones));
    const [currency, setCurrency] = useState(
        currencies.some((option) => option.value === 'USD')
            ? 'USD'
            : (currencies[0]?.value ?? ''),
    );

    return (
        <>
            <Head title="Create your organization" />
            <Form
                {...RegistrationController.store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-7"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-5">
                            <SectionLabel number="01">Your studio</SectionLabel>

                            <div className="grid gap-2">
                                <Label htmlFor="organization_name">
                                    Organization name
                                </Label>
                                <Input
                                    id="organization_name"
                                    name="organization_name"
                                    required
                                    autoFocus
                                    autoComplete="organization"
                                    placeholder="e.g. Northlight Studio"
                                />
                                <InputError
                                    message={errors.organization_name}
                                />
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
                                            <SelectValue placeholder="Select a timezone" />
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
                                        message={errors.default_timezone}
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
                                            <SelectValue placeholder="Select a currency" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {currencies.map((option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.value} —{' '}
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={errors.default_currency}
                                    />
                                </div>
                            </div>
                        </div>

                        <div className="grid gap-5">
                            <SectionLabel number="02">
                                You, the owner
                            </SectionLabel>

                            <div className="grid gap-2">
                                <Label htmlFor="name">Your name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="Full name"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Work email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoComplete="email"
                                    placeholder="you@studio.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-5 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="password">Password</Label>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
                                        autoComplete="new-password"
                                        placeholder="Password"
                                        passwordrules={passwordRules}
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        Confirm password
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        required
                                        autoComplete="new-password"
                                        placeholder="Confirm password"
                                        passwordrules={passwordRules}
                                    />
                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>
                            </div>
                        </div>

                        <div className="flex flex-col gap-4">
                            <Button
                                type="submit"
                                size="lg"
                                className="w-full"
                                data-test="register-organization-button"
                            >
                                {processing && <Spinner />}
                                Create my organization
                            </Button>
                            <p className="text-muted-foreground text-center text-xs text-pretty">
                                We’ll email you a link to confirm your address
                                before you start planning.
                            </p>
                        </div>

                        <div className="text-muted-foreground text-center text-sm">
                            Already have an account?{' '}
                            <TextLink href={login()}>Log in</TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

RegisterOrganization.layout = {
    title: "Let's set up your studio.",
    description:
        'Two minutes, two steps — your organization, then you as its owner.',
};
