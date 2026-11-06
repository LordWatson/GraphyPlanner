import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { BIO_THEME_CLASSES } from '@/components/bio/bio-page-view';
import { formatCurrency } from '@/lib/currency';
import { cn } from '@/lib/utils';

type Order = {
    id: number;
    status: string;
    status_label: string;
    amount_minor: number;
    currency: string;
    product_title: string;
    product_type: string;
    booked_for: string | null;
    is_paid: boolean;
};

/**
 * Step CG-4.2 / CG-4.3 — where the buyer lands after paying. It may well load before the provider's
 * webhook has reached us, so it says "confirming" rather than guessing, and the email is the real
 * delivery either way.
 *
 * It shows the purchase, never the buyer: its only secret is an order id, so a stranger opening the
 * URL learns nothing personal. Asking for the download link again needs the buyer's own email, and
 * the answer is the same either way.
 */
export default function PublicOrderThanksPage({
    theme,
    handle,
    brandName,
    order,
}: {
    theme: string;
    handle: string;
    brandName: string | null;
    order: Order;
}) {
    const [email, setEmail] = useState('');
    const [sending, setSending] = useState(false);

    return (
        <div
            className={cn(
                'flex min-h-screen flex-col items-center px-6 py-12',
                BIO_THEME_CLASSES[theme] ?? BIO_THEME_CLASSES.cream,
            )}
        >
            <Head title={`Thank you — ${brandName ?? handle}`} />

            <div className="flex w-full max-w-sm flex-col gap-6 text-center">
                <h1 className="font-display text-3xl leading-tight text-balance">
                    {order.is_paid ? 'Thank you.' : 'Confirming your payment…'}
                </h1>

                <p className="text-[15px] opacity-90">
                    {order.product_title} —{' '}
                    {formatCurrency(order.amount_minor / 100, order.currency)}.{' '}
                    {order.is_paid
                        ? 'Your receipt is on its way by email.'
                        : 'This page does not need to stay open — your receipt will arrive by email.'}
                </p>

                {order.booked_for && (
                    <p className="text-[15px] font-semibold">
                        {new Date(order.booked_for).toLocaleString()}
                    </p>
                )}

                {order.product_type === 'qa' && (
                    <p className="text-sm opacity-80">
                        Your card is held, not charged. You pay only when{' '}
                        {brandName ?? 'they'} answers.
                    </p>
                )}

                {order.product_type === 'download' && order.is_paid && (
                    <form
                        className="flex flex-col gap-2 text-left"
                        onSubmit={(event) => {
                            event.preventDefault();
                            setSending(true);
                            router.post(
                                `/@${handle}/thanks/${order.id}/resend`,
                                { buyer_email: email },
                                { onFinish: () => setSending(false) },
                            );
                        }}
                    >
                        <label className="flex flex-col gap-1 text-sm">
                            Lost the email? Send the link again
                            <input
                                value={email}
                                onChange={(event) =>
                                    setEmail(event.target.value)
                                }
                                type="email"
                                required
                                placeholder="The address you bought with"
                                className="rounded-xl border-2 border-current/25 bg-transparent px-4 py-3"
                            />
                        </label>
                        <button
                            type="submit"
                            disabled={sending}
                            className="rounded-full border-2 border-current/25 px-5 py-3 text-[15px] font-semibold transition hover:border-current/60 disabled:opacity-50"
                        >
                            Email me the link
                        </button>
                    </form>
                )}

                <a
                    href={`/@${handle}`}
                    className="font-mono text-[11px] tracking-wide uppercase opacity-60 hover:opacity-100"
                >
                    Back to @{handle}
                </a>
            </div>
        </div>
    );
}
