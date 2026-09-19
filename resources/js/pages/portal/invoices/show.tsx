import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import ClientPortalLayout from '@/layouts/client-portal/client-portal-layout';

type InvoiceData = {
    id: number;
    invoice_number: string;
    status: string;
    status_label: string;
    amount: string;
    currency: string;
    issue_date: string;
    due_date: string | null;
    is_unpaid: boolean;
    pdf_url: string | null;
    pdf_original_filename: string | null;
};

/**
 * Step 6.6 — read-only detail for a single invoice inside the client portal, linked from the
 * invoices list. No create/edit/send/mark-paid actions are exposed here.
 */
export default function PortalInvoiceShow({ invoice }: { invoice: InvoiceData }) {
    return (
        <ClientPortalLayout title={`Invoice ${invoice.invoice_number}`}>
            <Heading title={`Invoice ${invoice.invoice_number}`} />

            <div className="mt-6 rounded-xl border border-border bg-card p-6 shadow-lg shadow-black/[0.03]">
                <Badge variant={invoice.is_unpaid ? 'warning' : 'success'}>{invoice.is_unpaid ? 'Unpaid' : invoice.status_label}</Badge>
                <p className="mt-4 text-sm">
                    {invoice.currency} {invoice.amount}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">Issued {invoice.issue_date}</p>
                {invoice.due_date && <p className="text-xs text-muted-foreground">Due {invoice.due_date}</p>}
            </div>

            {invoice.pdf_url && (
                <div className="mt-6 rounded-xl border border-border bg-card p-6 shadow-lg shadow-black/[0.03]">
                    <p className="text-sm text-muted-foreground">
                        {invoice.pdf_original_filename ?? 'invoice.pdf'}
                    </p>
                    <iframe
                        src={invoice.pdf_url}
                        title="Invoice PDF"
                        className="mt-2 h-[70vh] w-full rounded-md border border-border"
                    />
                </div>
            )}
        </ClientPortalLayout>
    );
}
