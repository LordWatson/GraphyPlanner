import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Slot = { starts_at: string; ends_at: string; label: string };

/**
 * Step CG-4.5 — the buyer's own booking page, reached only from the signed link in their receipt:
 * what they booked, and the two things they may do about it. Both forms post to their own signed
 * URL, so the page carries its authorisation rather than assuming it.
 *
 * Plain cream, not the creator's theme: this is an account-less admin page, not a shop window.
 */
export default function PublicBookingPage({
    brandName,
    productTitle,
    timezone,
    booking,
    slots,
    moveUrl,
    cancelUrl,
}: {
    brandName: string | null;
    productTitle: string;
    timezone: string;
    booking: { starts_at: string; label: string; can_manage: boolean };
    slots: Slot[];
    moveUrl: string;
    cancelUrl: string;
}) {
    const [slot, setSlot] = useState(slots[0]?.starts_at ?? '');
    const [busy, setBusy] = useState(false);

    return (
        <div className="bg-cream text-ink flex min-h-screen flex-col items-center px-6 py-12">
            <Head title={`Your booking with ${brandName ?? 'us'}`} />

            <div className="flex w-full max-w-sm flex-col gap-6">
                <header className="flex flex-col gap-2 text-center">
                    <h1 className="font-display text-3xl leading-tight text-balance">
                        Your booking
                    </h1>
                    <p className="text-[15px] opacity-90">
                        {productTitle} with {brandName ?? 'them'}.
                    </p>
                    <p className="text-[15px] font-semibold">{booking.label}</p>
                    <p className="font-mono text-xs tracking-wide uppercase opacity-60">
                        {timezone}
                    </p>
                </header>

                {!booking.can_manage && (
                    <p className="text-sm opacity-80">
                        This booking can no longer be changed here. Get in touch
                        with {brandName ?? 'the seller'} if you need to.
                    </p>
                )}

                {booking.can_manage && slots.length > 0 && (
                    <form
                        className="flex flex-col gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            setBusy(true);
                            router.post(
                                moveUrl,
                                { slot },
                                { onFinish: () => setBusy(false) },
                            );
                        }}
                    >
                        <label className="flex flex-col gap-1 text-sm">
                            Move it to
                            <select
                                value={slot}
                                onChange={(event) =>
                                    setSlot(event.target.value)
                                }
                                className="border-ink/25 rounded-xl border-2 bg-transparent px-4 py-3"
                            >
                                {slots.map((option) => (
                                    <option
                                        key={option.starts_at}
                                        value={option.starts_at}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <button
                            type="submit"
                            disabled={busy}
                            className="border-ink/25 hover:border-ink/60 rounded-full border-2 px-5 py-3 text-[15px] font-semibold transition disabled:opacity-50"
                        >
                            Move booking
                        </button>
                    </form>
                )}

                {booking.can_manage && (
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => {
                            setBusy(true);
                            router.post(
                                cancelUrl,
                                {},
                                { onFinish: () => setBusy(false) },
                            );
                        }}
                        className="text-sm underline opacity-70 hover:opacity-100 disabled:opacity-40"
                    >
                        Cancel this booking
                    </button>
                )}
            </div>
        </div>
    );
}
