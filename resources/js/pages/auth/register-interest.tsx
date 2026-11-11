import { Form, Head, Link } from '@inertiajs/react';
import { ArrowRight, PartyPopper } from 'lucide-react';
import { useState } from 'react';
import RegistrationInterestController from '@/actions/App/Http/Controllers/RegistrationInterestController';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { home, login } from '@/routes';

/** What the browser can tell us about where the visitor came from, sent as hidden fields. */
function visitContext(): Record<string, string> {
    const params = new URLSearchParams(window.location.search);
    const context: Record<string, string> = {
        referrer: document.referrer,
        utm_source: params.get('utm_source') ?? '',
        utm_medium: params.get('utm_medium') ?? '',
        utm_campaign: params.get('utm_campaign') ?? '',
        timezone: '',
    };

    try {
        context.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    } catch {
        // Leave it blank.
    }

    return context;
}

function Optional() {
    return (
        <span className="text-muted-foreground font-mono text-[11px] font-normal">
            optional
        </span>
    );
}

/**
 * The public "register interest" form at `/register`. While the first studios are onboarded by
 * hand, every "sign up" link on the site lands here instead of the self-serve signup.
 */
export default function RegisterInterest() {
    const [context] = useState(visitContext);

    return (
        <>
            <Head title="Register your interest" />
            <Form
                {...RegistrationInterestController.store.form()}
                disableWhileProcessing
                className="flex flex-col gap-7"
            >
                {({ processing, errors, wasSuccessful }) =>
                    wasSuccessful ? (
                        <div className="bg-lime text-ink animate-rise flex flex-col gap-4 rounded-2xl p-6">
                            <span className="bg-ink text-lime flex size-12 items-center justify-center rounded-full">
                                <PartyPopper className="size-6" />
                            </span>
                            <p className="font-display text-[1.75rem] leading-tight">
                                Thank you, we’ll be in touch within 24 hours.
                            </p>
                            <p className="text-[15px] text-pretty">
                                Usually within a few hours. Keep an eye on your
                                inbox: we’ve sent you a little note to say hello.
                            </p>
                            <Button
                                asChild
                                variant="outline"
                                className="self-start"
                            >
                                <Link href={home()}>Back to the homepage</Link>
                            </Button>
                        </div>
                    ) : (
                        <>
                            {Object.entries(context).map(([name, value]) => (
                                <input
                                    key={name}
                                    type="hidden"
                                    name={name}
                                    value={value}
                                />
                            ))}
                            {/* Honeypot: hidden from people, irresistible to bots. */}
                            <input
                                type="text"
                                name="website"
                                tabIndex={-1}
                                autoComplete="off"
                                aria-hidden
                                className="hidden"
                            />

                            <div className="grid gap-5">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Your name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        autoFocus
                                        autoComplete="name"
                                        placeholder="e.g. Rae Morgan"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="email">Work email</Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        required
                                        autoComplete="email"
                                        placeholder="rae@northlight.studio"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="company_name">
                                        Company name
                                    </Label>
                                    <Input
                                        id="company_name"
                                        name="company_name"
                                        required
                                        autoComplete="organization"
                                        placeholder="e.g. Northlight Studio"
                                    />
                                    <InputError message={errors.company_name} />
                                </div>

                                <div className="grid gap-5 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor="phone"
                                            className="flex items-baseline justify-between"
                                        >
                                            Phone <Optional />
                                        </Label>
                                        <Input
                                            id="phone"
                                            name="phone"
                                            type="tel"
                                            autoComplete="tel"
                                            placeholder="+44 7700 900123"
                                        />
                                        <InputError message={errors.phone} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor="location"
                                            className="flex items-baseline justify-between"
                                        >
                                            Location <Optional />
                                        </Label>
                                        <Input
                                            id="location"
                                            name="location"
                                            autoComplete="address-level2"
                                            placeholder="e.g. Manchester, UK"
                                        />
                                        <InputError message={errors.location} />
                                    </div>
                                </div>
                            </div>

                            <div className="flex flex-col gap-4">
                                <Button
                                    type="submit"
                                    size="lg"
                                    className="w-full"
                                    data-test="register-interest-button"
                                >
                                    {processing ? <Spinner /> : null}
                                    Count me in
                                    {!processing && <ArrowRight />}
                                </Button>
                                <p className="text-muted-foreground text-center text-xs text-pretty">
                                    No card, no commitment. A real human replies
                                    within 24 hours.
                                </p>
                            </div>

                            <p className="text-muted-foreground text-center text-sm">
                                Already have an account?{' '}
                                <TextLink href={login()}>Log in</TextLink>
                            </p>
                        </>
                    )
                }
            </Form>
        </>
    );
}

RegisterInterest.layout = {
    title: 'Let’s make your marketing brighter.',
    description:
        'We’re welcoming our first studios personally. Tell us a little about you and we’ll set up a time to show you around.',
};
