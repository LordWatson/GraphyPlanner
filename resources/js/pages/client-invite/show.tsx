import { Form, Head } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';

import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { PublicShell, PublicTitle } from '@/components/public-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export default function ClientInviteShow({
    token,
    client,
    email,
}: {
    token: string;
    client: { name: string };
    email: string;
}) {
    return (
        <>
            <Head title={`Join the ${client.name} portal — AlimaPlanner`} />
            <PublicShell context={client.name}>
                <PublicTitle
                    kicker="Your client portal"
                    title={
                        <>
                            {client.name}&apos;s <em>portal</em>
                        </>
                    }
                >
                    <span className="flex items-start gap-1.5">
                        <UserPlus className="mt-0.5 size-4 shrink-0" />
                        Set a password to finish creating your account for{' '}
                        {email}
                    </span>
                </PublicTitle>

                <Form
                    action={`/client-invite/${token}`}
                    method="post"
                    resetOnSuccess={['password', 'password_confirmation']}
                    disableWhileProcessing
                    className="border-border bg-card shadow-paper grid gap-5 rounded-2xl border p-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Your name</Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    autoFocus
                                    autoComplete="name"
                                    name="name"
                                    placeholder="Full name"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    required
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder="Password"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirm password
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    required
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder="Confirm password"
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <Button
                                type="submit"
                                size="lg"
                                className="mt-2 w-full"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                Create my account
                            </Button>
                        </>
                    )}
                </Form>
            </PublicShell>
        </>
    );
}
