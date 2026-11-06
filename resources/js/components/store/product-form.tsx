import { router } from '@inertiajs/react';
import type { FormDataConvertible } from '@inertiajs/core';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
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

type Option = { value: string; label: string };

/** A `settings` value as an input's string, without stringifying a stray object into "[object Object]". */
function text(value: unknown): string {
    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : '';
}

export type ProductFormValues = {
    type: string;
    title: string;
    description: string | null;
    price_minor: number;
    currency: string;
    status: string;
    cover_asset_id: number | null;
    download_asset_id: number | null;
    settings: Record<string, unknown>;
};

/**
 * Step CG-4.2 / CG-4.5 — one form for every product type. The fields that only matter to a type
 * appear only for that type: a download picks its file and its link rules, a booking sets its
 * weekly availability, a tip sets a floor, a paid question sets how long the creator has to answer.
 *
 * Prices are entered in whole units and submitted in minor units, because that is the only form the
 * money ever takes server-side.
 */
export function ProductForm({
    product,
    types,
    statuses,
    currencies,
    action,
    onDone,
}: {
    product: ProductFormValues | null;
    types: Option[];
    statuses: Option[];
    currencies: string[];
    action: { url: string; method: string };
    onDone: () => void;
}) {
    const [form, setForm] = useState({
        type: product?.type ?? 'download',
        title: product?.title ?? '',
        description: product?.description ?? '',
        price: ((product?.price_minor ?? 0) / 100).toString(),
        currency: product?.currency ?? currencies[0],
        status: product?.status ?? 'draft',
        download_asset_id: product?.download_asset_id?.toString() ?? '',
        settings: (product?.settings ?? {}) as Record<string, unknown>,
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const availability = (form.settings.availability ?? {}) as Record<
        string,
        unknown
    >;

    const setAvailability = (key: string, value: unknown) =>
        setForm((current) => ({
            ...current,
            settings: {
                ...current.settings,
                availability: { ...availability, [key]: value },
            },
        }));

    const submit = () => {
        setSaving(true);
        router.visit(action.url, {
            method: action.method as 'post' | 'put',
            data: {
                type: form.type,
                title: form.title,
                description: form.description || null,
                price_minor: Math.round(Number(form.price || 0) * 100),
                currency: form.currency,
                status: form.status,
                download_asset_id:
                    form.download_asset_id === ''
                        ? null
                        : Number(form.download_asset_id),
                // The per-type payload (availability, download rules, …) travels as JSON; the
                // Form Request validates it key by key.
                settings: form.settings as Record<string, FormDataConvertible>,
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
                <Field label="What is it?" error={errors.type}>
                    <Select
                        value={form.type}
                        onValueChange={(value) =>
                            setForm({ ...form, type: value })
                        }
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {types.map((type) => (
                                <SelectItem key={type.value} value={type.value}>
                                    {type.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                <Field label="Status" error={errors.status}>
                    <Select
                        value={form.status}
                        onValueChange={(value) =>
                            setForm({ ...form, status: value })
                        }
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
            </div>

            <Field label="Name" error={errors.title}>
                <Input
                    value={form.title}
                    onChange={(event) =>
                        setForm({ ...form, title: event.target.value })
                    }
                    maxLength={120}
                    placeholder="Sourdough starter guide"
                />
            </Field>

            <Field label="Description" error={errors.description}>
                <Textarea
                    value={form.description}
                    onChange={(event) =>
                        setForm({ ...form, description: event.target.value })
                    }
                    rows={3}
                    maxLength={2000}
                    placeholder="What the buyer gets, in a line or two."
                />
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label={form.type === 'tip' ? 'Suggested amount' : 'Price'}
                    error={errors.price_minor}
                >
                    <Input
                        value={form.price}
                        onChange={(event) =>
                            setForm({ ...form, price: event.target.value })
                        }
                        type="number"
                        min={0}
                        step="0.01"
                    />
                </Field>

                <Field label="Currency" error={errors.currency}>
                    <Select
                        value={form.currency}
                        onValueChange={(value) =>
                            setForm({ ...form, currency: value })
                        }
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {currencies.map((currency) => (
                                <SelectItem key={currency} value={currency}>
                                    {currency}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
            </div>

            {form.type === 'download' && (
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field
                        label="Media id of the file"
                        error={errors.download_asset_id}
                    >
                        <Input
                            value={form.download_asset_id}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    download_asset_id: event.target.value,
                                })
                            }
                            type="number"
                            min={1}
                            placeholder="From your media library"
                        />
                    </Field>
                    <Field label="Link lasts (hours)">
                        <Input
                            value={text(form.settings.download_expiry_hours)}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    settings: {
                                        ...form.settings,
                                        download_expiry_hours: Number(
                                            event.target.value,
                                        ),
                                    },
                                })
                            }
                            type="number"
                            min={1}
                            placeholder="72"
                        />
                    </Field>
                    <Field label="Max downloads">
                        <Input
                            value={text(form.settings.download_limit)}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    settings: {
                                        ...form.settings,
                                        download_limit: Number(
                                            event.target.value,
                                        ),
                                    },
                                })
                            }
                            type="number"
                            min={1}
                            placeholder="5"
                        />
                    </Field>
                </div>
            )}

            {form.type === 'booking' && (
                <div className="grid gap-4 sm:grid-cols-4">
                    <Field label="From">
                        <Input
                            value={text(availability.start)}
                            onChange={(event) =>
                                setAvailability('start', event.target.value)
                            }
                            type="time"
                            placeholder="09:00"
                        />
                    </Field>
                    <Field label="Until">
                        <Input
                            value={text(availability.end)}
                            onChange={(event) =>
                                setAvailability('end', event.target.value)
                            }
                            type="time"
                            placeholder="17:00"
                        />
                    </Field>
                    <Field label="Slot (minutes)">
                        <Input
                            value={text(availability.slot_minutes)}
                            onChange={(event) =>
                                setAvailability(
                                    'slot_minutes',
                                    Number(event.target.value),
                                )
                            }
                            type="number"
                            min={5}
                            placeholder="30"
                        />
                    </Field>
                    <Field label="Notice (hours)">
                        <Input
                            value={text(availability.lead_hours)}
                            onChange={(event) =>
                                setAvailability(
                                    'lead_hours',
                                    Number(event.target.value),
                                )
                            }
                            type="number"
                            min={0}
                            placeholder="24"
                        />
                    </Field>
                    <p className="text-muted-foreground text-sm sm:col-span-4">
                        Slots run Monday to Friday in your own timezone. Buyers
                        can move or cancel their own booking from the link in
                        their receipt.
                    </p>
                </div>
            )}

            {form.type === 'qa' && (
                <Field label="Answer within (hours)">
                    <Input
                        value={text(form.settings.answer_window_hours)}
                        onChange={(event) =>
                            setForm({
                                ...form,
                                settings: {
                                    ...form.settings,
                                    answer_window_hours: Number(
                                        event.target.value,
                                    ),
                                },
                            })
                        }
                        type="number"
                        min={1}
                        max={144}
                        placeholder="72"
                    />
                </Field>
            )}

            <div className="flex items-center gap-3">
                <Button
                    disabled={saving || form.title.trim() === ''}
                    onClick={submit}
                >
                    Save product
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
