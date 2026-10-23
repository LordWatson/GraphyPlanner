import { Form, Head } from '@inertiajs/react';
import OrganizationController from '@/actions/App/Http/Controllers/Settings/OrganizationController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit, testUploadPost } from '@/routes/organization';
import {
    store as storeTaxRate,
    update as updateTaxRate,
    archive as archiveTaxRate,
} from '@/routes/tax-rates';
import { useState } from 'react';
import { Pencil, Plus, Trash2, Undo2, X } from 'lucide-react';

/** "Key is set (…a1b2)" — Step I-1.6 shows the last four characters, never the key. */
function storedHint(
    isSet: boolean,
    last4: string | null,
    noun: string,
): string {
    if (!isSet) {
        return 'Not set';
    }

    return `${noun} is set${last4 ? ` (…${last4})` : ''} — leave blank to keep it`;
}

type Option = { value: string; label: string };

type TaxRate = {
    id: number;
    name: string;
    rate: string;
    is_archived: boolean;
};

type OrganizationProps = {
    organization: {
        name: string;
        default_timezone: string;
        default_currency: string;
        billing_details: string | null;
        payment_instructions: string | null;
        tax_registration_number: string | null;
        tax_registration_label: string | null;
        invoice_payment_terms_days: number;
        approval_sla_hours: number | null;
        has_upload_post_key: boolean;
        has_upload_post_webhook_secret: boolean;
        has_xai_key: boolean;
        upload_post_key_last4: string | null;
        upload_post_webhook_secret_last4: string | null;
        xai_key_last4: string | null;
    };
    currencies: Option[];
    tax_rates: TaxRate[];
    can_manage_tax_rates: boolean;
};

export default function Organization({
    organization,
    currencies,
    tax_rates: taxRates,
    can_manage_tax_rates: canManageTaxRates,
}: OrganizationProps) {
    return (
        <>
            <Head title="Organization settings" />

            <h1 className="sr-only">Organization settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Organization"
                    description="Manage your organization's defaults, invoicing profile, and publish-adapter keys (Owner only)"
                />

                <Form
                    {...OrganizationController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="default_timezone">
                                    Default timezone
                                </Label>

                                <Input
                                    id="default_timezone"
                                    className="mt-1 block w-full"
                                    defaultValue={organization.default_timezone}
                                    name="default_timezone"
                                    required
                                    placeholder="e.g. Europe/Berlin"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.default_timezone}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="default_currency">
                                    Default currency
                                </Label>

                                <Select
                                    name="default_currency"
                                    defaultValue={organization.default_currency}
                                >
                                    <SelectTrigger
                                        id="default_currency"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Select a currency" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {currencies.map((currency) => (
                                            <SelectItem
                                                key={currency.value}
                                                value={currency.value}
                                            >
                                                {currency.value} —{' '}
                                                {currency.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <InputError
                                    className="mt-2"
                                    message={errors.default_currency}
                                />
                            </div>

                            <div className="grid gap-6 pt-4">
                                <Heading
                                    variant="small"
                                    title="Invoicing"
                                    description="Printed on every branded invoice PDF and included in invoice emails"
                                />

                                <div className="grid gap-2">
                                    <Label htmlFor="invoice_payment_terms_days">
                                        Default payment terms (days)
                                    </Label>
                                    <Input
                                        id="invoice_payment_terms_days"
                                        name="invoice_payment_terms_days"
                                        type="number"
                                        min={0}
                                        max={365}
                                        className="mt-1 block w-32"
                                        defaultValue={
                                            organization.invoice_payment_terms_days
                                        }
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        New invoices without a due date are due
                                        this many days after issue. 0 = due on
                                        receipt.
                                    </p>
                                    <InputError
                                        className="mt-2"
                                        message={
                                            errors.invoice_payment_terms_days
                                        }
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="approval_sla_hours">
                                        Approval SLA (hours)
                                    </Label>
                                    <Input
                                        id="approval_sla_hours"
                                        name="approval_sla_hours"
                                        type="number"
                                        min={1}
                                        max={720}
                                        className="mt-1 block w-32"
                                        defaultValue={
                                            organization.approval_sla_hours ??
                                            ''
                                        }
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        Flag a post as overdue once it has
                                        waited on review this long. Leave blank
                                        for no SLA.
                                    </p>
                                    <InputError
                                        className="mt-2"
                                        message={errors.approval_sla_hours}
                                    />
                                </div>

                                <div className="grid gap-2 sm:grid-cols-[1fr_12rem]">
                                    <div className="grid gap-2">
                                        <Label htmlFor="tax_registration_number">
                                            VAT / TRN number
                                        </Label>
                                        <Input
                                            id="tax_registration_number"
                                            name="tax_registration_number"
                                            defaultValue={
                                                organization.tax_registration_number ??
                                                ''
                                            }
                                            placeholder="Your VAT reg / TRN"
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            Set it and invoices read “Tax
                                            invoice” and print your number.
                                        </p>
                                        <InputError
                                            className="mt-2"
                                            message={
                                                errors.tax_registration_number
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="tax_registration_label">
                                            Label
                                        </Label>
                                        <Input
                                            id="tax_registration_label"
                                            name="tax_registration_label"
                                            defaultValue={
                                                organization.tax_registration_label ??
                                                ''
                                            }
                                            placeholder="VAT or TRN"
                                        />
                                        <InputError
                                            className="mt-2"
                                            message={
                                                errors.tax_registration_label
                                            }
                                        />
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="billing_details">
                                        Studio billing details
                                    </Label>
                                    <Textarea
                                        id="billing_details"
                                        name="billing_details"
                                        rows={3}
                                        defaultValue={
                                            organization.billing_details ?? ''
                                        }
                                        placeholder={
                                            'Address, company number, VAT/tax ID…'
                                        }
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.billing_details}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="payment_instructions">
                                        How clients should pay
                                    </Label>
                                    <Textarea
                                        id="payment_instructions"
                                        name="payment_instructions"
                                        rows={3}
                                        defaultValue={
                                            organization.payment_instructions ??
                                            ''
                                        }
                                        placeholder={
                                            'Bank details, payment link, or other instructions'
                                        }
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.payment_instructions}
                                    />
                                </div>
                            </div>

                            <div className="grid gap-6 pt-4">
                                <Heading
                                    variant="small"
                                    title="Integrations"
                                    description="API keys for publishing and AI — stored encrypted"
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="upload_post_key">
                                    Upload-Post API key
                                </Label>

                                <Input
                                    id="upload_post_key"
                                    type="password"
                                    className="mt-1 block w-full"
                                    name="upload_post_key"
                                    autoComplete="off"
                                    placeholder={storedHint(
                                        organization.has_upload_post_key,
                                        organization.upload_post_key_last4,
                                        'Key',
                                    )}
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.upload_post_key}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="upload_post_webhook_secret">
                                    Upload-Post webhook secret
                                </Label>

                                <Input
                                    id="upload_post_webhook_secret"
                                    type="password"
                                    className="mt-1 block w-full"
                                    name="upload_post_webhook_secret"
                                    autoComplete="off"
                                    placeholder={
                                        organization.has_upload_post_webhook_secret
                                            ? storedHint(
                                                  true,
                                                  organization.upload_post_webhook_secret_last4,
                                                  'Secret',
                                              )
                                            : 'Not set — copy the whsec_... value from the Upload-Post notifications dashboard'
                                    }
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.upload_post_webhook_secret}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="xai_key">
                                    xAI (Grok) API key
                                </Label>

                                <Input
                                    id="xai_key"
                                    type="password"
                                    className="mt-1 block w-full"
                                    name="xai_key"
                                    autoComplete="off"
                                    placeholder={storedHint(
                                        organization.has_xai_key,
                                        organization.xai_key_last4,
                                        'Key',
                                    )}
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.xai_key}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-organization-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                {canManageTaxRates && <TaxRatesManager taxRates={taxRates} />}

                {organization.has_upload_post_key && (
                    <Form
                        {...testUploadPost.form()}
                        options={{ preserveScroll: true }}
                        className="border-border flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"
                    >
                        {({ processing }) => (
                            <>
                                <p className="text-muted-foreground text-sm">
                                    Check that Upload-Post accepts your saved
                                    key. Nothing gets published.
                                </p>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    {processing
                                        ? 'Testing…'
                                        : 'Test connection'}
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

/**
 * Step M-4.1 — the organization's tax-rate list, applied to invoice line items and the retainer.
 * Each row is edited inline or archived (never deleted, so historical invoices keep their rate).
 */
function TaxRatesManager({ taxRates }: { taxRates: TaxRate[] }) {
    const [editingId, setEditingId] = useState<number | null>(null);

    return (
        <section className="border-border space-y-4 rounded-xl border p-4">
            <Heading
                variant="small"
                title="Tax rates"
                description="Choose from these on invoice line items. Archive a rate to retire it — invoices already using it keep it."
            />

            <ul className="divide-border divide-y">
                {taxRates.map((rate) =>
                    editingId === rate.id ? (
                        <li key={rate.id} className="py-3">
                            <Form
                                {...updateTaxRate.form(rate.id)}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setEditingId(null)}
                                className="flex flex-wrap items-end gap-3"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid flex-1 gap-1.5">
                                            <Label
                                                htmlFor={`rate-name-${rate.id}`}
                                            >
                                                Name
                                            </Label>
                                            <Input
                                                id={`rate-name-${rate.id}`}
                                                name="name"
                                                defaultValue={rate.name}
                                            />
                                            <InputError message={errors.name} />
                                        </div>
                                        <div className="grid w-28 gap-1.5">
                                            <Label
                                                htmlFor={`rate-pct-${rate.id}`}
                                            >
                                                Rate %
                                            </Label>
                                            <Input
                                                id={`rate-pct-${rate.id}`}
                                                name="rate"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                max="100"
                                                defaultValue={rate.rate}
                                            />
                                            <InputError message={errors.rate} />
                                        </div>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            Save
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setEditingId(null)}
                                        >
                                            <X />
                                            Cancel
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </li>
                    ) : (
                        <li
                            key={rate.id}
                            className="flex items-center justify-between gap-3 py-3"
                        >
                            <div className="min-w-0">
                                <span
                                    className={
                                        rate.is_archived
                                            ? 'text-muted-foreground line-through'
                                            : ''
                                    }
                                >
                                    {rate.name}
                                </span>
                                <span className="text-muted-foreground ml-2 font-mono text-sm tabular-nums">
                                    {rate.rate}%
                                </span>
                                {rate.is_archived && (
                                    <span className="text-muted-foreground ml-2 text-xs">
                                        Archived
                                    </span>
                                )}
                            </div>
                            <div className="flex shrink-0 items-center gap-1">
                                {!rate.is_archived && (
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        className="size-8"
                                        aria-label={`Edit ${rate.name}`}
                                        onClick={() => setEditingId(rate.id)}
                                    >
                                        <Pencil className="size-3.5" />
                                    </Button>
                                )}
                                <Form
                                    {...archiveTaxRate.form(rate.id)}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            size="icon"
                                            variant="ghost"
                                            className="size-8"
                                            disabled={processing}
                                            aria-label={
                                                rate.is_archived
                                                    ? `Restore ${rate.name}`
                                                    : `Archive ${rate.name}`
                                            }
                                        >
                                            {rate.is_archived ? (
                                                <Undo2 className="size-3.5" />
                                            ) : (
                                                <Trash2 className="size-3.5" />
                                            )}
                                        </Button>
                                    )}
                                </Form>
                            </div>
                        </li>
                    ),
                )}
                {taxRates.length === 0 && (
                    <li className="text-muted-foreground py-3 text-sm">
                        No tax rates yet. Add one below.
                    </li>
                )}
            </ul>

            <Form
                {...storeTaxRate.form()}
                options={{ preserveScroll: true }}
                resetOnSuccess
                className="border-border flex flex-wrap items-end gap-3 border-t pt-4"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid flex-1 gap-1.5">
                            <Label htmlFor="new-rate-name">New rate name</Label>
                            <Input
                                id="new-rate-name"
                                name="name"
                                placeholder="e.g. UK VAT (20%)"
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid w-28 gap-1.5">
                            <Label htmlFor="new-rate-pct">Rate %</Label>
                            <Input
                                id="new-rate-pct"
                                name="rate"
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                placeholder="20"
                            />
                            <InputError message={errors.rate} />
                        </div>
                        <Button type="submit" size="sm" disabled={processing}>
                            <Plus />
                            Add rate
                        </Button>
                    </>
                )}
            </Form>
        </section>
    );
}

Organization.layout = {
    breadcrumbs: [
        {
            title: 'Organization settings',
            href: edit(),
        },
    ],
};
