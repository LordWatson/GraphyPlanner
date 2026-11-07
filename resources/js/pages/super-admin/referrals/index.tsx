import { Head, router } from '@inertiajs/react';
import { Handshake } from 'lucide-react';
import { useState } from 'react';

import { ChapterHero, EmptyState, Page, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCurrency } from '@/lib/currency';
import {
    end as endPartner,
    index as referralsIndex,
    store as storePartner,
} from '@/routes/super-admin/referrals';
import { store as storePayout } from '@/routes/super-admin/referrals/payouts';

type Partner = {
    id: number;
    code: string;
    name: string;
    email: string | null;
    commission_percent: number;
    active: boolean;
    referred_count: number;
    paying_count: number;
    paid: { currency: string; amount_minor: number }[];
};

/**
 * Step GTM-7.3 — the affiliate programme, super-admin side.
 *
 * A payout here **records a transfer that has already happened**; there is no payment integration
 * behind it, on purpose. Paying a partner is a bank transfer and a conversation, and automating it
 * before the first partner exists would be building the wrong thing twice.
 */
export default function Referrals({
    partners,
    unattributed,
    default_commission_percent: defaultCommission,
}: {
    partners: Partner[];
    unattributed: number;
    default_commission_percent: number;
}) {
    const [code, setCode] = useState('');
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [commission, setCommission] = useState(String(defaultCommission));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [payoutFor, setPayoutFor] = useState<number | null>(null);

    return (
        <>
            <Head title="Referrals" />
            <Page>
                <ChapterHero
                    eyebrow="Distribution"
                    title={
                        <>
                            Who sends us <em>customers</em>.
                        </>
                    }
                    description={`${partners.length} partner(s). ${unattributed} organisations arrived without a referral — the comparison pages and the partner page are what change that.`}
                />

                <Panel eyebrow="Partners" title="The programme">
                    {partners.length === 0 ? (
                        <EmptyState
                            compact
                            className="border-0"
                            icon={Handshake}
                            title="No partners yet."
                            description="Add the first one below. Their code works on any page as ?ref=code."
                        />
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-border/70 border-b text-left">
                                    <th className="py-2 font-medium">
                                        Partner
                                    </th>
                                    <th className="py-2 text-right font-medium">
                                        Referred
                                    </th>
                                    <th className="py-2 text-right font-medium">
                                        Paying now
                                    </th>
                                    <th className="py-2 text-right font-medium">
                                        Paid out
                                    </th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {partners.map((partner) => (
                                    <tr
                                        key={partner.id}
                                        className="border-border/40 border-b"
                                    >
                                        <td className="py-2.5">
                                            {partner.name}
                                            <span className="text-muted-foreground ml-2 font-mono text-xs">
                                                ?ref={partner.code}
                                            </span>
                                            <span className="text-muted-foreground block text-xs">
                                                {partner.commission_percent}%
                                                {partner.email
                                                    ? ` · ${partner.email}`
                                                    : ''}
                                                {partner.active
                                                    ? ''
                                                    : ' · ended'}
                                            </span>
                                        </td>
                                        <Num
                                            text={String(
                                                partner.referred_count,
                                            )}
                                        />
                                        <Num
                                            text={String(partner.paying_count)}
                                        />
                                        <Num
                                            text={
                                                partner.paid.length === 0
                                                    ? '—'
                                                    : partner.paid
                                                          .map((paid) =>
                                                              formatCurrency(
                                                                  paid.amount_minor /
                                                                      100,
                                                                  paid.currency,
                                                              ),
                                                          )
                                                          .join(' · ')
                                            }
                                        />
                                        <td className="py-2.5 text-right whitespace-nowrap">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setPayoutFor(
                                                        payoutFor === partner.id
                                                            ? null
                                                            : partner.id,
                                                    )
                                                }
                                            >
                                                Record a payout
                                            </Button>
                                            {partner.active && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            endPartner({
                                                                partner:
                                                                    partner.id,
                                                            }).url,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    End
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    <p className="text-muted-foreground text-[13px]">
                        &ldquo;Paid out&rdquo; is money actually sent. What is
                        owed is worked out from the partner&rsquo;s paying
                        organisations when you settle up &mdash; it is
                        deliberately not stored, so there is only ever one
                        number that can be wrong.
                    </p>
                </Panel>

                {payoutFor !== null && (
                    <PayoutForm
                        partnerId={payoutFor}
                        onDone={() => setPayoutFor(null)}
                    />
                )}

                <Panel eyebrow="Add" title="A new partner">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field
                            id="code"
                            label="Code"
                            value={code}
                            onChange={setCode}
                            placeholder="thatblog"
                            error={errors.code}
                        />
                        <Field
                            id="name"
                            label="Name"
                            value={name}
                            onChange={setName}
                            placeholder="That Blog"
                            error={errors.name}
                        />
                        <Field
                            id="email"
                            label="Email"
                            value={email}
                            onChange={setEmail}
                            placeholder="hello@thatblog.com"
                            error={errors.email}
                        />
                        <Field
                            id="commission_percent"
                            label="Commission %"
                            value={commission}
                            onChange={setCommission}
                            error={errors.commission_percent}
                        />
                    </div>
                    <div>
                        <Button
                            variant="brand"
                            disabled={code.trim() === '' || name.trim() === ''}
                            onClick={() =>
                                router.post(
                                    storePartner().url,
                                    {
                                        code,
                                        name,
                                        email: email || null,
                                        commission_percent: Number(commission),
                                    },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setErrors({});
                                            setCode('');
                                            setName('');
                                            setEmail('');
                                        },
                                        onError: (bag) =>
                                            setErrors(
                                                bag as Record<string, string>,
                                            ),
                                    },
                                )
                            }
                        >
                            Add partner
                        </Button>
                    </div>
                </Panel>
            </Page>
        </>
    );
}

function PayoutForm({
    partnerId,
    onDone,
}: {
    partnerId: number;
    onDone: () => void;
}) {
    const [amount, setAmount] = useState('');
    const [currency, setCurrency] = useState('GBP');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [reference, setReference] = useState('');

    return (
        <Panel eyebrow="Payout" title="Record money already sent">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <Field
                    id="amount"
                    label="Amount (minor units)"
                    value={amount}
                    onChange={setAmount}
                    placeholder="12000"
                />
                <Field
                    id="currency"
                    label="Currency"
                    value={currency}
                    onChange={setCurrency}
                />
                <Field
                    id="from"
                    label="Period from"
                    value={from}
                    onChange={setFrom}
                    placeholder="2026-10-01"
                />
                <Field
                    id="to"
                    label="Period to"
                    value={to}
                    onChange={setTo}
                    placeholder="2026-12-31"
                />
                <Field
                    id="reference"
                    label="Reference"
                    value={reference}
                    onChange={setReference}
                    placeholder="Bank transfer"
                />
            </div>
            <div className="flex gap-2">
                <Button
                    variant="brand"
                    disabled={amount.trim() === '' || from === '' || to === ''}
                    onClick={() =>
                        router.post(
                            storePayout({ partner: partnerId }).url,
                            {
                                amount_minor: Number(amount),
                                currency,
                                period_start: from,
                                period_end: to,
                                reference: reference || null,
                            },
                            { preserveScroll: true, onSuccess: onDone },
                        )
                    }
                >
                    Record it
                </Button>
                <Button variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </Panel>
    );
}

function Num({ text }: { text: string }) {
    return (
        <td className="py-2.5 text-right font-mono text-xs tabular-nums">
            {text}
        </td>
    );
}

function Field({
    id,
    label,
    value,
    onChange,
    placeholder,
    error,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    error?: string;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder}
            />
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}

Referrals.layout = {
    breadcrumbs: [{ title: 'Referrals', href: referralsIndex() }],
};
