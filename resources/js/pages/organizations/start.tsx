import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import BusinessRegistrationController from '@/actions/App/Http/Controllers/Organizations/BusinessRegistrationController';
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
import { cn } from '@/lib/utils';
import { login } from '@/routes';
import { register as registerAgency } from '@/routes/organizations';

type Option = { value: string; label: string };
type BrandKind = { value: string; label: string; tagline: string };

type Props = {
    timezones: Option[];
    currencies: Option[];
    passwordRules: string;
    brandKinds: BrandKind[];
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
 * Step B-2.1 — the "manage your own brand" signup. One short form: your brand (name, what kind,
 * timezone, currency) and you. On success the new owner is signed in and dropped straight into the
 * guided setup. No agency vocabulary anywhere.
 */
export default function StartWorkspace({
    timezones,
    currencies,
    passwordRules,
    brandKinds,
}: Props) {
    const [timezone, setTimezone] = useState(() => detectTimezone(timezones));
    const [currency, setCurrency] = useState(
        currencies.some((option) => option.value === 'USD')
            ? 'USD'
            : (currencies[0]?.value ?? ''),
    );
    const [brandKind, setBrandKind] = useState(
        brandKinds[0]?.value ?? 'business',
    );

    return (
        <>
            <Head title="Start your brand" />
            <Form
                {...BusinessRegistrationController.store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-7"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-5">
                            <SectionLabel number="01">Your brand</SectionLabel>

                            <div className="grid gap-2">
                                <Label htmlFor="organization_name">
                                    Brand name
                                </Label>
                                <Input
                                    id="organization_name"
                                    name="organization_name"
                                    required
                                    autoFocus
                                    autoComplete="organization"
                                    placeholder="e.g. Sourdough & Co."
                                />
                                <InputError
                                    message={errors.organization_name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <span className="text-sm font-medium">
                                    I'm a…
                                </span>
                                <input
                                    type="hidden"
                                    name="brand_kind"
                                    value={brandKind}
                                />
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {brandKinds.map((kind) => {
                                        const selected =
                                            brandKind === kind.value;

                                        return (
                                            <button
                                                key={kind.value}
                                                type="button"
                                                onClick={() =>
                                                    setBrandKind(kind.value)
                                                }
                                                aria-pressed={selected}
                                                className={cn(
                                                    'flex flex-col gap-1 rounded-xl border-2 p-4 text-left transition-colors',
                                                    selected
                                                        ? 'border-primary bg-primary/5'
                                                        : 'border-input hover:border-foreground/40',
                                                )}
                                            >
                                                <span className="font-display text-[15px]">
                                                    {kind.label}
                                                </span>
                                                <span className="text-muted-foreground text-xs text-pretty">
                                                    {kind.tagline}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                                <InputError message={errors.brand_kind} />
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
                            <SectionLabel number="02">You</SectionLabel>

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
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoComplete="email"
                                    placeholder="you@yourbrand.com"
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
                                data-test="start-workspace-button"
                            >
                                {processing && <Spinner />}
                                Create my workspace
                            </Button>
                            <p className="text-muted-foreground text-center text-xs text-pretty">
                                We'll email you a link to confirm your address
                                before your posts go out.
                            </p>
                        </div>

                        <div className="text-muted-foreground flex flex-col gap-1 text-center text-sm">
                            <span>
                                Already have an account?{' '}
                                <TextLink href={login()}>Log in</TextLink>
                            </span>
                            <span>
                                Running an agency with clients?{' '}
                                <TextLink href={registerAgency()}>
                                    Set up a studio
                                </TextLink>
                            </span>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

StartWorkspace.layout = {
    title: 'Let’s set up your brand.',
    description:
        'Two minutes — your brand, then you. We’ll help you post from there.',
};
