import { Link, router, usePage } from '@inertiajs/react';
import { Plus, Receipt } from 'lucide-react';
import { EmptyState } from '@/components/page';
import { Button } from '@/components/ui/button';
import { InvoiceLedgerList } from '@/components/invoices/invoice-ledger-list';
import {
    MarkPaidDialog,
    SendInvoiceDialog,
} from '@/components/invoices/invoice-action-dialogs';
import { NewInvoiceDialog } from '@/components/invoices/new-invoice-dialog';
import type {
    CurrencyOption,
    InvoiceAbilities,
    InvoiceRow,
} from '@/components/invoices/types';
import { index } from '@/routes/clients';
import { index as invoicesIndex } from '@/routes/invoices';
import type { ClientPageProps } from '@/components/clients/client-layout';
import {
    ClientLayout,
    TabHeading,
    TabPanel,
} from '@/components/clients/client-layout';
import type { Paginated } from '@/components/pagination';
import { Pagination } from '@/components/pagination';

type InvoiceData = InvoiceRow & { can: InvoiceAbilities };

/**
 * Step I-2.4 — Billing tab (`clients.billing`), paginated by issue date.
 */
export default function ClientBillingTab({
    invoices: invoicePage,
    currencies,
    ...page
}: ClientPageProps & {
    invoices: Paginated<InvoiceData>;
    currencies: CurrencyOption[];
}) {
    const { client, can } = page;
    const invoices = invoicePage.data;
    const { currency } = usePage<{ currency: string }>().props;

    return (
        <ClientLayout {...page}>
            <TabPanel>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <TabHeading
                        icon={Receipt}
                        title="Billing history"
                        description={
                            (client.billing_email ?? client.approval_email)
                                ? `Invoices are emailed to ${client.billing_email ?? client.approval_email}`
                                : 'No billing email yet — add one on the client to email invoices'
                        }
                    />
                    <div className="flex items-center gap-2">
                        <Button size="sm" variant="ghost" asChild>
                            <Link
                                href={invoicesIndex({
                                    query: {
                                        client_id: client.id,
                                    },
                                })}
                            >
                                All invoices
                            </Link>
                        </Button>
                        {can.createInvoice && (
                            <NewInvoiceDialog
                                clientId={client.id}
                                currencies={currencies}
                                defaultCurrency={currency}
                                trigger={
                                    <Button size="sm">
                                        <Plus />
                                        New invoice
                                    </Button>
                                }
                            />
                        )}
                    </div>
                </div>

                {invoices.length === 0 ? (
                    <EmptyState
                        compact
                        icon={Receipt}
                        title="Nothing billed yet."
                        description="Start a draft, add what you delivered, and send it when it's ready."
                    />
                ) : (
                    <InvoiceLedgerList
                        invoices={invoices}
                        showClient={false}
                        actions={(row) => {
                            const invoice = invoices.find(
                                (candidate) => candidate.id === row.id,
                            );

                            if (!invoice) {
                                return null;
                            }

                            return (
                                <>
                                    {invoice.can.send && (
                                        <SendInvoiceDialog
                                            invoice={invoice}
                                            defaultEmail={
                                                client.billing_email ??
                                                client.approval_email
                                            }
                                            variant="outline"
                                        />
                                    )}
                                    {invoice.can.mark_paid && (
                                        <MarkPaidDialog invoice={invoice} />
                                    )}
                                </>
                            );
                        }}
                    />
                )}
            </TabPanel>
            <Pagination
                page={invoicePage}
                label="invoices"
                onPageChange={(page) =>
                    router.reload({ data: { page }, only: ['invoices'] })
                }
            />
        </ClientLayout>
    );
}

ClientBillingTab.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
