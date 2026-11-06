import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Handshake, Pencil, Plus, Sparkles, Trash2 } from 'lucide-react';
import { ChapterHero, EmptyState, Page, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatCurrency } from '@/lib/currency';
import {
    destroy as destroyDeal,
    index as dealsIndex,
    store as storeDeal,
    update as updateDeal,
} from '@/routes/business/deals';
import { index as mediaKitIndex } from '@/routes/business/media-kit';
import { edit as editPlan } from '@/routes/plan';

type Option = { value: string; label: string };

type Deliverable = {
    platform: string;
    platform_label: string;
    quantity: number;
    published: number;
};

type Deal = {
    id: number;
    partner_name: string;
    partner_handle: string | null;
    status: string;
    status_label: string;
    fee_minor: number | null;
    currency: string | null;
    starts_on: string | null;
    due_on: string | null;
    is_overdue: boolean;
    notes: string | null;
    contact_email: string | null;
    show_on_media_kit: boolean;
    posts_count: number;
    deliverables: Deliverable[];
};

/**
 * Step CG-5.2 (B-11.2) — the brand-deals tracker. Progress is counted from the deal's own published
 * posts, so there is nothing to tick off by hand: link a post to a deal in the post editor's Paid
 * partnership panel and it fills in when it goes out.
 *
 * Enquiries from the media kit (CG-5.3) land here as drafts, with the sponsor's email, so answering
 * one is the owner's own email rather than something we send for them.
 */
export default function BusinessDealsPage({
    deals,
    statuses,
    platforms,
    currency,
    mediaKit,
    entitled,
    canManage,
}: {
    deals: Deal[];
    statuses: Option[];
    platforms: Option[];
    currency: string;
    mediaKit: { is_public: boolean; accepts_enquiries: boolean };
    entitled: boolean;
    canManage: boolean;
}) {
    const [editing, setEditing] = useState<Deal | 'new' | null>(null);

    return (
        <Page>
            <Head title="Deals" />

            <ChapterHero
                eyebrow="Paid work"
                title={
                    <>
                        Your <em>brand deals</em>.
                    </>
                }
                description="Who you're working with, what you owe them, and what has actually gone out — counted from your own published posts."
                actions={
                    canManage &&
                    entitled && (
                        <Button
                            variant="on-ink"
                            size="lg"
                            onClick={() => setEditing('new')}
                        >
                            <Plus className="size-4" />
                            New deal
                        </Button>
                    )
                }
            />

            {!entitled && (
                <EmptyState
                    icon={Sparkles}
                    title="The deals tracker is on Creator Pro."
                    description="Keep every partnership, deliverable and fee in one place — and let your media kit show who you've worked with."
                    action={
                        <Button variant="brand" asChild>
                            <Link href={editPlan()}>See plans</Link>
                        </Button>
                    }
                />
            )}

            {entitled && !mediaKit.accepts_enquiries && (
                <EmptyState
                    compact
                    icon={Handshake}
                    title="Let sponsors come to you."
                    description="Switch on enquiries on your media kit and every message lands here as a draft deal."
                    action={
                        <Button variant="outline" asChild>
                            <Link href={mediaKitIndex()}>Open media kit</Link>
                        </Button>
                    }
                />
            )}

            <Panel eyebrow="Tracker" title="Deals">
                {deals.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No deals yet. Add the one you're talking to now — it
                        takes a partner name and nothing else.
                    </p>
                ) : (
                    <ul className="divide-border/70 divide-y">
                        {deals.map((deal) => (
                            <li
                                key={deal.id}
                                className="flex flex-col gap-2 py-4 first:pt-0"
                            >
                                <div className="flex flex-wrap items-center gap-3">
                                    <StatusPill
                                        kind="deal"
                                        value={deal.status}
                                        label={deal.status_label}
                                    />
                                    <span className="font-semibold">
                                        {deal.partner_name}
                                    </span>
                                    {deal.partner_handle && (
                                        <span className="text-muted-foreground text-sm">
                                            {deal.partner_handle}
                                        </span>
                                    )}
                                    {deal.fee_minor !== null && (
                                        <span className="text-muted-foreground text-sm">
                                            {formatCurrency(
                                                deal.fee_minor / 100,
                                                deal.currency ?? currency,
                                            )}
                                        </span>
                                    )}
                                    {deal.due_on && (
                                        <span
                                            className={
                                                deal.is_overdue
                                                    ? 'text-destructive text-sm font-semibold'
                                                    : 'text-muted-foreground text-sm'
                                            }
                                        >
                                            due{' '}
                                            {new Date(
                                                deal.due_on,
                                            ).toLocaleDateString()}
                                        </span>
                                    )}
                                    <span className="flex-1" />
                                    {canManage && (
                                        <>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                aria-label={`Edit ${deal.partner_name}`}
                                                onClick={() => setEditing(deal)}
                                            >
                                                <Pencil className="size-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                aria-label={`Remove ${deal.partner_name}`}
                                                onClick={() =>
                                                    router.delete(
                                                        destroyDeal({
                                                            deal: deal.id,
                                                        }).url,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </>
                                    )}
                                </div>

                                {deal.deliverables.length > 0 && (
                                    <ul className="flex flex-wrap gap-2">
                                        {deal.deliverables.map(
                                            (deliverable) => (
                                                <li
                                                    key={deliverable.platform}
                                                    className="border-border/70 rounded-full border px-3 py-1 text-sm tabular-nums"
                                                >
                                                    {deliverable.platform_label}{' '}
                                                    {deliverable.published}/
                                                    {deliverable.quantity}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                )}

                                {deal.contact_email && (
                                    <p className="text-muted-foreground text-sm">
                                        Enquiry from{' '}
                                        <a
                                            href={`mailto:${deal.contact_email}`}
                                            className="underline"
                                        >
                                            {deal.contact_email}
                                        </a>
                                    </p>
                                )}

                                {deal.notes && (
                                    <p className="bg-muted/40 rounded-lg p-3 text-sm whitespace-pre-line">
                                        {deal.notes}
                                    </p>
                                )}

                                {deal.show_on_media_kit && (
                                    <p className="text-muted-foreground text-[13px]">
                                        Shown as a past partner on your media
                                        kit.
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {editing !== null && canManage && (
                    <DealForm
                        deal={editing === 'new' ? null : editing}
                        statuses={statuses}
                        platforms={platforms}
                        currency={currency}
                        onDone={() => setEditing(null)}
                    />
                )}
            </Panel>
        </Page>
    );
}

function DealForm({
    deal,
    statuses,
    platforms,
    currency,
    onDone,
}: {
    deal: Deal | null;
    statuses: Option[];
    platforms: Option[];
    currency: string;
    onDone: () => void;
}) {
    const [form, setForm] = useState({
        partner_name: deal?.partner_name ?? '',
        partner_handle: deal?.partner_handle ?? '',
        status: deal?.status ?? 'pitched',
        fee: deal?.fee_minor != null ? (deal.fee_minor / 100).toString() : '',
        starts_on: deal?.starts_on ?? '',
        due_on: deal?.due_on ?? '',
        notes: deal?.notes ?? '',
        show_on_media_kit: deal?.show_on_media_kit ?? false,
        deliverables:
            deal?.deliverables.map((deliverable) => ({
                platform: deliverable.platform,
                quantity: deliverable.quantity.toString(),
            })) ?? [],
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const submit = () => {
        const action =
            deal === null ? storeDeal() : updateDeal({ deal: deal.id });
        setSaving(true);
        router.visit(action.url, {
            method: action.method as 'post' | 'put',
            data: {
                partner_name: form.partner_name,
                partner_handle: form.partner_handle || null,
                status: form.status,
                fee_minor:
                    form.fee === '' ? null : Math.round(Number(form.fee) * 100),
                currency,
                starts_on: form.starts_on || null,
                due_on: form.due_on || null,
                notes: form.notes || null,
                show_on_media_kit: form.show_on_media_kit,
                deliverables: form.deliverables
                    .filter((deliverable) => deliverable.platform !== '')
                    .map((deliverable) => ({
                        platform: deliverable.platform,
                        quantity: Math.max(
                            1,
                            Number(deliverable.quantity) || 1,
                        ),
                    })),
            },
            preserveScroll: true,
            onSuccess: () => onDone(),
            onError: (bag) => setErrors(bag as Record<string, string>),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <div className="border-border/70 mt-5 flex flex-col gap-4 border-t pt-5">
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Partner" error={errors.partner_name}>
                    <Input
                        value={form.partner_name}
                        onChange={(event) =>
                            setForm({
                                ...form,
                                partner_name: event.target.value,
                            })
                        }
                        maxLength={120}
                        placeholder="e.g. Yorkshire Tea"
                    />
                </Field>
                <Field label="Their handle" error={errors.partner_handle}>
                    <Input
                        value={form.partner_handle}
                        onChange={(event) =>
                            setForm({
                                ...form,
                                partner_handle: event.target.value,
                            })
                        }
                        maxLength={64}
                        placeholder="@yorkshiretea"
                    />
                </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-4">
                <Field label="Status" error={errors.status}>
                    <Select
                        value={form.status}
                        onValueChange={(status) => setForm({ ...form, status })}
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {statuses.map((status) => (
                                <SelectItem
                                    key={status.value}
                                    value={status.value}
                                >
                                    {status.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field label={`Fee (${currency})`} error={errors.fee_minor}>
                    <Input
                        value={form.fee}
                        onChange={(event) =>
                            setForm({ ...form, fee: event.target.value })
                        }
                        type="number"
                        min={0}
                        step="0.01"
                    />
                </Field>
                <Field label="Starts" error={errors.starts_on}>
                    <Input
                        value={form.starts_on}
                        onChange={(event) =>
                            setForm({ ...form, starts_on: event.target.value })
                        }
                        type="date"
                    />
                </Field>
                <Field label="Due" error={errors.due_on}>
                    <Input
                        value={form.due_on}
                        onChange={(event) =>
                            setForm({ ...form, due_on: event.target.value })
                        }
                        type="date"
                    />
                </Field>
            </div>

            <div className="flex flex-col gap-2">
                <Label>Deliverables</Label>
                {form.deliverables.map((deliverable, index) => (
                    <div key={index} className="flex items-center gap-2">
                        <Select
                            value={deliverable.platform}
                            onValueChange={(platform) =>
                                setForm({
                                    ...form,
                                    deliverables: form.deliverables.map(
                                        (current, i) =>
                                            i === index
                                                ? { ...current, platform }
                                                : current,
                                    ),
                                })
                            }
                        >
                            <SelectTrigger className="w-48">
                                <SelectValue placeholder="Where" />
                            </SelectTrigger>
                            <SelectContent>
                                {platforms.map((platform) => (
                                    <SelectItem
                                        key={platform.value}
                                        value={platform.value}
                                    >
                                        {platform.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Input
                            value={deliverable.quantity}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    deliverables: form.deliverables.map(
                                        (current, i) =>
                                            i === index
                                                ? {
                                                      ...current,
                                                      quantity:
                                                          event.target.value,
                                                  }
                                                : current,
                                    ),
                                })
                            }
                            type="number"
                            min={1}
                            className="w-24"
                            aria-label="How many posts"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label="Remove deliverable"
                            onClick={() =>
                                setForm({
                                    ...form,
                                    deliverables: form.deliverables.filter(
                                        (_, i) => i !== index,
                                    ),
                                })
                            }
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </div>
                ))}
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            setForm({
                                ...form,
                                deliverables: [
                                    ...form.deliverables,
                                    { platform: '', quantity: '1' },
                                ],
                            })
                        }
                    >
                        <Plus className="size-4" />
                        Add a deliverable
                    </Button>
                </div>
            </div>

            <Field label="Notes" error={errors.notes}>
                <Textarea
                    value={form.notes}
                    onChange={(event) =>
                        setForm({ ...form, notes: event.target.value })
                    }
                    rows={3}
                    maxLength={5000}
                    placeholder="What was agreed, usage rights, anything you'll want to remember."
                />
            </Field>

            <label className="flex items-start gap-3 text-sm">
                <Checkbox
                    checked={form.show_on_media_kit}
                    onCheckedChange={(checked) =>
                        setForm({
                            ...form,
                            show_on_media_kit: checked === true,
                        })
                    }
                    className="mt-0.5"
                />
                <span>
                    Name them as a past partner on my media kit.
                    <span className="text-muted-foreground block">
                        Only once the deal is delivered or paid — never while
                        you're still talking.
                    </span>
                </span>
            </label>

            <div className="flex items-center gap-3">
                <Button
                    disabled={saving || form.partner_name.trim() === ''}
                    onClick={submit}
                >
                    Save deal
                </Button>
                <Button variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </div>
    );
}

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <Label>{label}</Label>
            {children}
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}

BusinessDealsPage.layout = {
    breadcrumbs: [{ title: 'Deals', href: dealsIndex() }],
};
