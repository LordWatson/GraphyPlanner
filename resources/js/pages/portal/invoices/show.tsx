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
    due_date: string | null;
};

/**
 * Step 6.3 — minimal scoped invoice view proving the portal's isolation guarantee. The full
 * read-only invoices list lands in Step 6.6.
 */
export default function PortalInvoiceShow({ invoice }: { invoice: InvoiceData }) {
    return (
        <ClientPortalLayout title={`Invoice ${invoice.invoice_number}`}>
            <Heading title={`Invoice ${invoice.invoice_number}`} />

            <div className="mt-6 rounded-xl border border-border bg-card p-6 shadow-lg shadow-black/[0.03]">
                <Badge variant="outline">{invoice.status_label}</Badge>
                <p className="mt-4 text-sm">
                    {invoice.currency} {invoice.amount}
                </p>
                {invoice.due_date && <p className="mt-1 text-xs text-muted-foreground">Due {invoice.due_date}</p>}
            </div>
        </ClientPortalLayout>
    );
}
