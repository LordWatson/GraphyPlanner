import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { BIO_THEME_CLASSES } from '@/components/bio/bio-page-view';
import { formatCurrency } from '@/lib/currency';
import { cn } from '@/lib/utils';

type Product = {
    id: number;
    type: string;
    type_label: string;
    title: string;
    description: string | null;
    price_minor: number;
    currency: string;
    cover_url: string | null;
    min_minor: number | null;
    takes_message: boolean;
    holds_payment: boolean;
};

type Slot = { starts_at: string; ends_at: string; label: string };

/**
 * Step CG-4.2 — the buyer's page for one product, on the creator's own handle and in the creator's
 * own theme. Deliberately plain: what it is, what it costs, an email address to send the receipt to,
 * and the one button that hands over to the provider's hosted card page. No account, no cart, and
 * no card field anywhere on our side.
 */
export default function PublicProductPage({
    theme,
    handle,
    brandName,
    product,
    slots,
}: {
    theme: string;
    handle: string;
    brandName: string | null;
    product: Product;
    slots: Slot[];
}) {
    const [form, setForm] = useState({
        buyer_email: '',
        buyer_name: '',
        amount: ((product.min_minor ?? product.price_minor) / 100).toString(),
        coupon_code: '',
        message: '',
        slot: slots[0]?.starts_at ?? '',
    });
    const [sending, setSending] = useState(false);

    const buy = () => {
        setSending(true);
        router.post(
            `/@${handle}/p/${product.id}/checkout`,
            {
                buyer_email: form.buyer_email,
                buyer_name: form.buyer_name || null,
                amount_minor:
                    product.min_minor === null
                        ? null
                        : Math.round(Number(form.amount || 0) * 100),
                coupon_code: form.coupon_code || null,
                message: form.message || null,
                slot: form.slot || null,
            },
            { onFinish: () => setSending(false) },
        );
    };

    return (
        <div
            className={cn(
                'flex min-h-screen flex-col items-center px-6 py-12',
                BIO_THEME_CLASSES[theme] ?? BIO_THEME_CLASSES.cream,
            )}
        >
            <Head title={`${product.title} — ${brandName ?? handle}`}>
                <meta
                    name="description"
                    content={
                        product.description ??
                        `${product.title} from ${brandName}.`
                    }
                />
            </Head>

            <div className="flex w-full max-w-sm flex-col gap-6">
                <header className="flex flex-col gap-2 text-center">
                    <a
                        href={`/@${handle}`}
                        className="font-mono text-xs tracking-wide uppercase opacity-70"
                    >
                        @{handle}
                    </a>
                    <h1 className="font-display text-3xl leading-tight text-balance">
                        {product.title}
                    </h1>
                    <p className="text-[15px] font-semibold">
                        {product.min_minor !== null
                            ? 'Pay what you like'
                            : formatCurrency(
                                  product.price_minor / 100,
                                  product.currency,
                              )}
                    </p>
                </header>

                {product.cover_url && (
                    <img
                        src={product.cover_url}
                        alt=""
                        className="aspect-square w-full rounded-2xl border border-current/15 object-cover"
                    />
                )}

                {product.description && (
                    <p className="text-[15px] whitespace-pre-line opacity-90">
                        {product.description}
                    </p>
                )}

                <form
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        buy();
                    }}
                >
                    {product.min_minor !== null && (
                        <label className="flex flex-col gap-1 text-sm">
                            Amount ({product.currency})
                            <input
                                value={form.amount}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        amount: event.target.value,
                                    })
                                }
                                type="number"
                                min={(product.min_minor ?? 100) / 100}
                                step="0.01"
                                className="rounded-xl border-2 border-current/25 bg-transparent px-4 py-3"
                            />
                        </label>
                    )}

                    {slots.length > 0 && (
                        <label className="flex flex-col gap-1 text-sm">
                            Pick a time
                            <select
                                value={form.slot}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        slot: event.target.value,
                                    })
                                }
                                className="rounded-xl border-2 border-current/25 bg-transparent px-4 py-3"
                            >
                                {slots.map((slot) => (
                                    <option
                                        key={slot.starts_at}
                                        value={slot.starts_at}
                                    >
                                        {slot.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}

                    {product.type === 'booking' && slots.length === 0 && (
                        <p className="text-sm opacity-80">
                            No times free just now — check back soon.
                        </p>
                    )}

                    <label className="flex flex-col gap-1 text-sm">
                        Your email
                        <input
                            value={form.buyer_email}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    buyer_email: event.target.value,
                                })
                            }
                            type="email"
                            required
                            autoComplete="email"
                            className="rounded-xl border-2 border-current/25 bg-transparent px-4 py-3"
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-sm">
                        Your name (optional)
                        <input
                            value={form.buyer_name}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    buyer_name: event.target.value,
                                })
                            }
                            autoComplete="name"
                            className="rounded-xl border-2 border-current/25 bg-transparent px-4 py-3"
                        />
                    </label>

                    {product.takes_message && (
                        <label className="flex flex-col gap-1 text-sm">
                            {product.type === 'qa'
                                ? 'Your question'
                                : 'A message (optional)'}
                            <textarea
                                value={form.message}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        message: event.target.value,
                                    })
                                }
                                rows={4}
                                maxLength={2000}
                                required={product.type === 'qa'}
                                className="rounded-xl border-2 border-current/25 bg-transparent px-4 py-3"
                            />
                        </label>
                    )}

                    <label className="flex flex-col gap-1 text-sm">
                        Discount code (optional)
                        <input
                            value={form.coupon_code}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    coupon_code:
                                        event.target.value.toUpperCase(),
                                })
                            }
                            maxLength={32}
                            className="rounded-xl border-2 border-current/25 bg-transparent px-4 py-3"
                        />
                    </label>

                    <button
                        type="submit"
                        disabled={
                            sending ||
                            (product.type === 'booking' && slots.length === 0)
                        }
                        className="rounded-full border-2 border-current/25 px-5 py-3.5 text-[15px] font-semibold transition hover:border-current/60 disabled:opacity-50"
                    >
                        {sending
                            ? 'Taking you to checkout…'
                            : 'Continue to payment'}
                    </button>

                    {product.holds_payment && (
                        <p className="text-xs opacity-70">
                            Your card is only held, not charged. You pay when{' '}
                            {brandName ?? 'they'} answers — if they do not, the
                            hold is released.
                        </p>
                    )}
                </form>

                <footer className="flex justify-center font-mono text-[11px] tracking-wide uppercase opacity-60">
                    <a href="/" className="hover:opacity-100">
                        Made with Alima
                    </a>
                </footer>
            </div>
        </div>
    );
}
