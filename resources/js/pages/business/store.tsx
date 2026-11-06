import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    BadgePercent,
    Megaphone,
    Pencil,
    Plus,
    ShoppingBag,
    Sparkles,
    Trash2,
} from 'lucide-react';
import {
    ProductForm,
    type ProductFormValues,
} from '@/components/store/product-form';
import { ChapterHero, EmptyState, Page, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatCurrency } from '@/lib/currency';
import { index as storeIndex, launch, setup } from '@/routes/business/store';
import {
    store as storeCoupon,
    destroy as destroyCoupon,
} from '@/routes/business/store/coupons';
import { answer, refund } from '@/routes/business/store/orders';
import {
    destroy as destroyProduct,
    store as createProduct,
    update as updateProduct,
} from '@/routes/business/store/products';
import { edit as editPlan } from '@/routes/plan';

type Option = { value: string; label: string };

type Account = {
    charges_enabled: boolean;
    payouts_enabled: boolean;
    requirements_due: string[];
    country: string | null;
} | null;

type Product = ProductFormValues & {
    id: number;
    type_label: string;
    status_label: string;
    sales_count: number;
    public_url: string | null;
};

type Order = {
    id: number;
    product_title: string;
    product_type: string;
    buyer_email: string;
    buyer_name: string | null;
    amount_minor: number;
    currency: string;
    status: string;
    status_label: string;
    message: string | null;
    answer: string | null;
    booked_for: string | null;
    created_at: string | null;
    can_refund: boolean;
    can_answer: boolean;
};

type Coupon = {
    id: number;
    code: string;
    product_id: number | null;
    percent_off: number | null;
    amount_off_minor: number | null;
    expires_at: string | null;
    max_redemptions: number | null;
    redemptions: number;
};

type Earnings = {
    currency: string;
    paid_minor: number;
    refunded_minor: number;
    orders: number;
};

/**
 * Step CG-4.6 — the creator's shop in one page: what is on sale, what sold, the codes, and the
 * state of their payment account. The money is theirs (0% platform fee), so the page says so and
 * links out to Stripe for payouts rather than pretending to know their schedule.
 *
 * Everything that spends money or sends something is behind `canManage` (Owner/Manager), and the
 * whole page still renders without the plan gate so a Free workspace can see what it would get.
 */
export default function BusinessStorePage({
    account,
    products,
    orders,
    coupons,
    earnings,
    types,
    statuses,
    currencies,
    hasBioPage,
    entitled,
    canManage,
}: {
    account: Account;
    products: Product[];
    orders: Order[];
    coupons: Coupon[];
    earnings: Earnings[];
    types: Option[];
    statuses: Option[];
    currencies: string[];
    hasBioPage: boolean;
    entitled: boolean;
    canManage: boolean;
}) {
    const [editing, setEditing] = useState<Product | 'new' | null>(null);
    const canSell = account?.charges_enabled ?? false;

    return (
        <Page>
            <Head title="Shop" />

            <ChapterHero
                eyebrow="Sell your way"
                title={
                    <>
                        Your <em>shop</em>.
                    </>
                }
                description="Downloads, bookings, tips and paid questions — sold from your bio link, paid straight into your own Stripe account. We take 0% of it."
                actions={
                    canManage &&
                    entitled && (
                        <Button
                            variant="on-ink"
                            size="lg"
                            onClick={() => router.post(setup().url)}
                        >
                            {account === null
                                ? 'Connect payments'
                                : canSell
                                  ? 'Manage payouts'
                                  : 'Finish your details'}
                        </Button>
                    )
                }
            />

            {!entitled && (
                <EmptyState
                    icon={Sparkles}
                    title="Selling is on Creator Pro."
                    description="Set your products up now — upgrade when you want to take your first payment."
                    action={
                        <Button variant="brand" asChild>
                            <Link href={editPlan()}>See plans</Link>
                        </Button>
                    }
                />
            )}

            {entitled && !canSell && (
                <EmptyState
                    icon={ShoppingBag}
                    title={
                        account === null
                            ? 'Connect Stripe to take payments.'
                            : 'Stripe needs a few more details.'
                    }
                    description={
                        account === null
                            ? 'Your buyers pay you directly. Alima never holds your money and takes no cut of a sale.'
                            : `Stripe is still waiting on: ${account.requirements_due.join(', ') || 'a final check'}.`
                    }
                    action={
                        canManage && (
                            <Button
                                variant="brand"
                                onClick={() => router.post(setup().url)}
                            >
                                {account === null
                                    ? 'Connect Stripe'
                                    : 'Finish setup'}
                            </Button>
                        )
                    }
                />
            )}

            {entitled && canSell && !hasBioPage && (
                <EmptyState
                    compact
                    icon={ShoppingBag}
                    title="Publish your link in bio so people can reach your shop."
                    description="Your products live on your bio handle — until that page is live, there is nowhere to buy from."
                />
            )}

            {earnings.length > 0 && (
                <Panel eyebrow="Earnings" title="What you have taken">
                    <ul className="grid gap-4 sm:grid-cols-3">
                        {earnings.map((row) => (
                            <li
                                key={row.currency}
                                className="flex flex-col gap-1"
                            >
                                <span className="font-display text-2xl">
                                    {formatCurrency(
                                        row.paid_minor / 100,
                                        row.currency,
                                    )}
                                </span>
                                <span className="text-muted-foreground text-sm">
                                    {row.orders} order
                                    {row.orders === 1 ? '' : 's'}
                                    {row.refunded_minor > 0 &&
                                        `, ${formatCurrency(row.refunded_minor / 100, row.currency)} refunded`}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}

            <Panel
                eyebrow="Products"
                title="What you sell"
                actions={
                    canManage && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setEditing('new')}
                        >
                            <Plus className="size-4" />
                            New product
                        </Button>
                    )
                }
            >
                {products.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        Nothing for sale yet. A download, a booking slot, a tip
                        jar or a paid question — start with one.
                    </p>
                ) : (
                    <ul className="divide-border/70 divide-y">
                        {products.map((product) => (
                            <li
                                key={product.id}
                                className="flex flex-wrap items-center gap-3 py-4 first:pt-0"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="truncate font-semibold">
                                            {product.title}
                                        </span>
                                        <StatusPill
                                            kind="product"
                                            value={product.status}
                                            label={product.status_label}
                                        />
                                    </div>
                                    <p className="text-muted-foreground text-sm">
                                        {product.type_label} ·{' '}
                                        {formatCurrency(
                                            product.price_minor / 100,
                                            product.currency,
                                        )}{' '}
                                        · {product.sales_count} sold
                                    </p>
                                </div>

                                {product.public_url && (
                                    <Button variant="ghost" size="sm" asChild>
                                        <a
                                            href={product.public_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            View
                                        </a>
                                    </Button>
                                )}

                                {canManage && (
                                    <>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            aria-label={`Edit ${product.title}`}
                                            onClick={() => setEditing(product)}
                                        >
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            title="Draft a launch campaign"
                                            onClick={() =>
                                                router.post(
                                                    launch({
                                                        product: product.id,
                                                    }).url,
                                                )
                                            }
                                        >
                                            <Megaphone className="size-4" />
                                            Launch plan
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            aria-label={`Archive ${product.title}`}
                                            onClick={() =>
                                                router.delete(
                                                    destroyProduct({
                                                        product: product.id,
                                                    }).url,
                                                )
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {editing !== null && canManage && (
                    <ProductForm
                        product={editing === 'new' ? null : editing}
                        types={types}
                        statuses={statuses}
                        currencies={currencies}
                        action={
                            editing === 'new'
                                ? createProduct()
                                : updateProduct({ product: editing.id })
                        }
                        onDone={() => setEditing(null)}
                    />
                )}
            </Panel>

            <Panel eyebrow="Orders" title="What sold">
                {orders.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No orders yet. They will land here the moment someone
                        buys.
                    </p>
                ) : (
                    <ul className="divide-border/70 divide-y">
                        {orders.map((order) => (
                            <li
                                key={order.id}
                                className="flex flex-col gap-2 py-4 first:pt-0"
                            >
                                <div className="flex flex-wrap items-center gap-3">
                                    <StatusPill
                                        kind="order"
                                        value={order.status}
                                        label={order.status_label}
                                    />
                                    <span className="font-semibold">
                                        {order.product_title}
                                    </span>
                                    <span className="text-muted-foreground text-sm">
                                        {formatCurrency(
                                            order.amount_minor / 100,
                                            order.currency,
                                        )}{' '}
                                        ·{' '}
                                        {order.buyer_name ?? order.buyer_email}
                                    </span>
                                    <span className="flex-1" />
                                    {canManage && order.can_refund && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    refund({ order: order.id })
                                                        .url,
                                                )
                                            }
                                        >
                                            Refund
                                        </Button>
                                    )}
                                </div>

                                {order.booked_for && (
                                    <p className="text-muted-foreground text-sm">
                                        Booked for{' '}
                                        {new Date(
                                            order.booked_for,
                                        ).toLocaleString()}
                                    </p>
                                )}

                                {order.message && (
                                    <p className="bg-muted/40 rounded-lg p-3 text-sm">
                                        “{order.message}”
                                    </p>
                                )}

                                {order.answer && (
                                    <p className="text-muted-foreground text-sm">
                                        You answered: {order.answer}
                                    </p>
                                )}

                                {canManage && order.can_answer && (
                                    <AnswerForm orderId={order.id} />
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <Panel
                eyebrow="Discounts"
                title="Codes"
                icon={BadgePercent}
                description="A code is either a percentage or a fixed amount off, and can be limited to one product, a date or a number of uses."
            >
                {coupons.length > 0 && (
                    <ul className="divide-border/70 mb-4 divide-y">
                        {coupons.map((coupon) => (
                            <li
                                key={coupon.id}
                                className="flex items-center gap-3 py-3 first:pt-0"
                            >
                                <span className="font-mono text-sm font-semibold">
                                    {coupon.code}
                                </span>
                                <span className="text-muted-foreground text-sm">
                                    {coupon.percent_off !== null
                                        ? `${coupon.percent_off}% off`
                                        : `${(coupon.amount_off_minor ?? 0) / 100} off`}
                                    {coupon.max_redemptions !== null &&
                                        ` · ${coupon.redemptions}/${coupon.max_redemptions} used`}
                                </span>
                                <span className="flex-1" />
                                {canManage && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        aria-label={`Remove ${coupon.code}`}
                                        onClick={() =>
                                            router.delete(
                                                destroyCoupon({
                                                    coupon: coupon.id,
                                                }).url,
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {canManage && <CouponForm products={products} />}
            </Panel>
        </Page>
    );
}

function AnswerForm({ orderId }: { orderId: number }) {
    const [text, setText] = useState('');

    return (
        <div className="flex flex-col gap-2">
            <Label htmlFor={`answer-${orderId}`}>Your answer</Label>
            <Textarea
                id={`answer-${orderId}`}
                value={text}
                onChange={(event) => setText(event.target.value)}
                rows={3}
                maxLength={5000}
                placeholder="Answer them — this is also what takes the payment."
            />
            <div>
                <Button
                    size="sm"
                    disabled={text.trim() === ''}
                    onClick={() =>
                        router.post(
                            answer({ order: orderId }).url,
                            { answer: text },
                            { onSuccess: () => setText('') },
                        )
                    }
                >
                    Send answer
                </Button>
            </div>
        </div>
    );
}

function CouponForm({ products }: { products: Product[] }) {
    const [form, setForm] = useState({
        code: '',
        percent_off: '',
        max_redemptions: '',
    });

    return (
        <div className="flex flex-wrap items-end gap-3">
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="coupon-code">Code</Label>
                <Input
                    id="coupon-code"
                    value={form.code}
                    onChange={(event) =>
                        setForm({
                            ...form,
                            code: event.target.value.toUpperCase(),
                        })
                    }
                    maxLength={32}
                    placeholder="WELCOME10"
                    className="w-40"
                />
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="coupon-percent">% off</Label>
                <Input
                    id="coupon-percent"
                    value={form.percent_off}
                    onChange={(event) =>
                        setForm({ ...form, percent_off: event.target.value })
                    }
                    type="number"
                    min={1}
                    max={100}
                    className="w-24"
                />
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="coupon-uses">Max uses</Label>
                <Input
                    id="coupon-uses"
                    value={form.max_redemptions}
                    onChange={(event) =>
                        setForm({
                            ...form,
                            max_redemptions: event.target.value,
                        })
                    }
                    type="number"
                    min={1}
                    className="w-24"
                />
            </div>
            <Button
                variant="outline"
                disabled={form.code.trim() === '' || form.percent_off === ''}
                onClick={() =>
                    router.post(
                        storeCoupon().url,
                        {
                            code: form.code,
                            percent_off: Number(form.percent_off),
                            max_redemptions:
                                form.max_redemptions === ''
                                    ? null
                                    : Number(form.max_redemptions),
                        },
                        {
                            onSuccess: () =>
                                setForm({
                                    code: '',
                                    percent_off: '',
                                    max_redemptions: '',
                                }),
                        },
                    )
                }
            >
                Add code
            </Button>
            {products.length === 0 && (
                <p className="text-muted-foreground text-sm">
                    Codes apply to your whole shop.
                </p>
            )}
        </div>
    );
}

BusinessStorePage.layout = {
    breadcrumbs: [{ title: 'Shop', href: storeIndex() }],
};
