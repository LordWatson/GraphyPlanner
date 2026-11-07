import { Head } from '@inertiajs/react';

/**
 * Step GTM-2.1 — where a contact lands from the two links in their emails: the confirmation link
 * and the unsubscribe link. One page, three messages, no form and nothing to click: whatever
 * brought them here has already happened by the time this renders.
 */
export default function PublicAudience({
    state,
    brandName,
    email,
}: {
    state: 'confirmed' | 'already-confirmed' | 'unsubscribed';
    brandName: string | null;
    email: string;
}) {
    const brand = brandName ?? 'this creator';

    const { title, body } = {
        confirmed: {
            title: 'You are on the list.',
            body: `${email} will hear from ${brand} when there is something worth sending. Every email has an unsubscribe link.`,
        },
        'already-confirmed': {
            title: 'Already confirmed.',
            body: `${email} is on ${brand}'s list — nothing more to do.`,
        },
        unsubscribed: {
            title: 'Unsubscribed.',
            body: `${email} will not get any more emails from ${brand}. No hard feelings.`,
        },
    }[state];

    return (
        <div className="bg-cream text-ink flex min-h-screen flex-col items-center justify-center gap-6 px-6 py-16">
            <Head title={title} />

            <div className="flex max-w-md flex-col gap-3 text-center">
                <h1 className="font-display text-3xl leading-tight text-balance">
                    {title}
                </h1>
                <p className="text-[15px] opacity-80">{body}</p>
            </div>

            <footer className="font-mono text-[11px] tracking-wide uppercase opacity-60">
                <a href="/" className="hover:opacity-100">
                    Made with Alima
                </a>
            </footer>
        </div>
    );
}
