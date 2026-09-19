import { Form, Head } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';

import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export default function StaffInviteShow({
    token,
    organization,
    email,
    role_label: roleLabel,
}: {
    token: string;
    organization: { name: string };
    email: string;
    role_label: string;
}) {
    return (
        <>
            <Head title={`Join ${organization.name} on Graphy`} />
            <div className="relative flex min-h-screen flex-col overflow-hidden bg-background text-foreground">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[420px] bg-gradient-brand opacity-[0.10] blur-3xl"
                />

                <header className="mx-auto flex w-full max-w-md items-center gap-2 px-6 py-8">
                    <span className="flex size-9 items-center justify-center rounded-lg bg-gradient-brand shadow-lg shadow-primary/20">
                        <AppLogoIcon className="size-5 fill-current text-white" />
                    </span>
                    <span className="text-lg font-semibold">Graphy</span>
                </header>

                <main className="mx-auto flex w-full max-w-md flex-1 flex-col gap-6 px-6 pb-16">
                    <div className="flex flex-col gap-2">
                        <p className="text-sm text-muted-foreground">You&apos;ve been invited to join</p>
                        <h1 className="font-serif text-2xl font-bold tracking-tight sm:text-3xl">
                            <span className="text-gradient-brand">{organization.name}</span>
                        </h1>
                        <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                            <UserPlus className="size-4" />
                            Set a password to finish creating your {roleLabel} account for {email}
                        </p>
                    </div>

                    <Form
                        action={`/staff-invite/${token}`}
                        method="post"
                        resetOnSuccess={['password', 'password_confirmation']}
                        disableWhileProcessing
                        className="grid gap-4 rounded-xl border border-border bg-card p-6 shadow-lg shadow-black/[0.03]"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Your name</Label>
                                    <Input id="name" type="text" required autoFocus autoComplete="name" name="name" placeholder="Full name" />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password">Password</Label>
                                    <PasswordInput id="password" required autoComplete="new-password" name="password" placeholder="Password" />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">Confirm password</Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        required
                                        autoComplete="new-password"
                                        name="password_confirmation"
                                        placeholder="Confirm password"
                                    />
                                    <InputError message={errors.password_confirmation} />
                                </div>

                                <Button type="submit" size="lg" className="mt-2 w-full shadow-lg shadow-primary/20" disabled={processing}>
                                    {processing && <Spinner />}
                                    Create my account
                                </Button>
                            </>
                        )}
                    </Form>
                </main>

                <footer className="mx-auto w-full max-w-md px-6 py-6 text-center text-xs text-muted-foreground">
                    &copy; {new Date().getFullYear()} Graphy. All rights reserved.
                </footer>
            </div>
        </>
    );
}
