/**
 * Shapes produced by `App\Support\Invoices\InvoicePresenter` — keep in sync with it.
 */
export type InvoiceStatusValue = 'draft' | 'sent' | 'paid' | 'void';

export type InvoiceDisplayStatus = InvoiceStatusValue | 'overdue';

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
    finalized_at: string | null;
    sent_to_email: string | null;
    last_reminded_at: string | null;
    reminder_count: number;
    payment_reference: string | null;
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
    if (!value) {
        return '—';
    }

    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/** Today as YYYY-MM-DD in the viewer's local timezone (for date input defaults). */
export function todayIso(): string {
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}
