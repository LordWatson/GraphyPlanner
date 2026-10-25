// Components
import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="Email verification" />

            {status === 'verification-link-sent' && (
                <div className="text-success mb-4 text-center text-sm font-medium">
                    A fresh verification link is on its way to the email address
                    you signed up with.
                </div>
            )}

            <p className="text-muted-foreground mb-6 text-center text-sm">
                We've sent you a welcome email. Hit the{' '}
                <span className="font-medium">Verify my email</span> button
                inside it and you'll land straight in your workspace — no need
                to come back here. Can't find it? Check your spam folder, or
                send yourself a new link below.
            </p>

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
                            {processing && <Spinner />}
                            Resend verification email
                        </Button>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            Log out
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Almost there — check your inbox.',
    description:
        'Your workspace is ready. Confirm your email from the welcome message we just sent to get started.',
};
