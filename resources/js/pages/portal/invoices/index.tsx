import { Link } from '@inertiajs/react';

import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import ClientPortalLayout from '@/layouts/client-portal/client-portal-layout';
import { show as portalInvoicesShow } from '@/routes/portal/invoices';

type InvoiceRow = {
    id: number;
    invoice_number: string;
    status: string;
    status_label: string;
    amount: string;
    currency: string;
    issue_date: string;
    due_date: string | null;
    is_unpaid: boolean;
};

/**
 * Step 6.6 — the client portal's read-only invoices list: every invoice for this client, with
 * unpaid ones clearly flagged. No create/edit/send/mark-paid actions are exposed here.
 */
export default function PortalInvoicesIndex({ invoices }: { invoices: InvoiceRow[] }) {
    return (
        <ClientPortalLayout title="Invoices">
            <Heading title="Invoices" description="Your billing history for this account." />

            <div className="mt-6 flex flex-col gap-2">
                {invoices.length > 0 ? (
                    invoices.map((invoice) => (
                        <Link
                            key={invoice.id}
                            href={portalInvoicesShow(invoice.id)}
                            className="flex items-center justify-between gap-3 rounded-xl border border-border bg-card px-4 py-3 text-sm shadow-sm hover:bg-muted/50"
                        >
                            <div className="flex flex-col">
                                <span className="font-medium">{invoice.invoice_number}</span>
                                <span className="text-xs text-muted-foreground">
                                    Issued {invoice.issue_date}
                                    {invoice.due_date ? ` · Due ${invoice.due_date}` : ''}
                                </span>
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-sm font-medium">
                                    {invoice.currency} {invoice.amount}
                                </span>
                                <Badge variant={invoice.is_unpaid ? 'warning' : 'success'} className="whitespace-nowrap text-[10px]">
                                    {invoice.is_unpaid ? 'Unpaid' : invoice.status_label}
                                </Badge>
                            </div>
                        </Link>
                    ))
                ) : (
                    <div className="rounded-xl border border-dashed border-border bg-muted/20 p-6 text-center text-sm text-muted-foreground">
                        No invoices to show here yet.
                    </div>
                )}
            </div>
        </ClientPortalLayout>
    );
}
