import { formatDate } from '@/lib/dates';
/**
 * Shapes produced by `App\Support\Invoices\InvoicePresenter` — keep in sync with it.
 */
export type InvoiceStatusValue =
    | 'draft'
    | 'sent'
    | 'pending_confirmation'
    | 'paid'
    | 'void';

export type InvoiceDisplayStatus = InvoiceStatusValue | 'overdue';

/** One per-rate line of `Invoice::tax_breakdown` ([{name, rate, net, tax}]). */
export type InvoiceTaxLine = {
    name: string;
    rate: number;
    net: number;
    tax: number;
};

/** A tax-rate option from the invoice controller (active rates for the org). */
export type TaxRateOption = { value: string; label: string; rate: string };

export type InvoiceRow = {
    id: number;
    invoice_number: string;
    status: InvoiceStatusValue;
    status_label: string;
    display_status: InvoiceDisplayStatus;
    display_status_label: string;
    is_overdue: boolean;
    days_overdue: number;
    amount: string;
    subtotal: string;
    tax_total: string;
    tax_breakdown: InvoiceTaxLine[];
    currency: string;
    issue_date: string;
    due_date: string | null;
    description: string | null;
    sent_at: string | null;
    paid_at: string | null;
    client: { id: number; name: string } | null;
};

export type InvoiceItemData = {
    id: number;
    description: string;
    is_billable: boolean;
    price: string | null;
    quantity: number;
    tax_rate_id: number | null;
    tax_rate: string | null;
    tax_name: string | null;
    tax_amount: number;
    sort_order: number;
    line_total: number;
};

export type InvoiceTimelineEvent = {
    event: 'created' | 'finalized' | 'sent' | 'reminded' | 'paid' | 'voided';
    label: string;
    at: string;
    detail: string | null;
};

export type InvoiceDetail = InvoiceRow & {
    is_finalized: boolean;
    needs_finalizing: boolean;
    retainer_amount_snapshot: string | null;
    retainer_tax_rate: string | null;
    retainer_tax_name: string | null;
    tax_rate_id: number | null;
    finalized_at: string | null;
    sent_to_email: string | null;
    last_reminded_at: string | null;
    reminder_count: number;
    payment_reference: string | null;
    payment_reported_at: string | null;
    payment_reported_method: string | null;
    payment_reported_reference: string | null;
    payment_reported_note: string | null;
    voided_at: string | null;
    void_reason: string | null;
    pdf_url: string | null;
    pdf_original_filename: string | null;
    pdf_is_generated: boolean;
    items: InvoiceItemData[];
    items_subtotal: number;
    timeline: InvoiceTimelineEvent[];
    recipient_email: string | null;
};

export type InvoiceAbilities = {
    update: boolean;
    delete: boolean;
    send: boolean;
    mark_paid: boolean;
    confirm_payment: boolean;
    revert_payment: boolean;
    finalize: boolean;
    manage_items: boolean;
    edit_currency: boolean;
    edit_amount: boolean;
    generate_pdf: boolean;
    void: boolean;
    remind: boolean;
    duplicate: boolean;
};

/** An entry of `App\Support\Options\CurrencyOptions::options()`. */
export type CurrencyOption = { value: string; label: string };

export type InvoiceClientSummary = {
    id: number;
    name: string;
    legal_name: string | null;
    billing_email: string | null;
    billing_address: string | null;
    recipient_email: string | null;
    retainer_amount: string | null;
};

/** "2026-10-16" → "16 Oct 2026" (dates are plain calendar dates — no timezone shifting). */
export function formatInvoiceDate(value: string | null): string {
    return formatDate(value);
}

/** Today as YYYY-MM-DD in the viewer's local timezone (for date input defaults). */
export function todayIso(): string {
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}
