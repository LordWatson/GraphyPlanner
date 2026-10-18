import { Link, usePage } from '@inertiajs/react';
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
import { billing, index } from '@/routes/clients';
import { index as invoicesIndex } from '@/routes/invoices';
import type { ClientPageProps } from '@/components/clients/client-layout';
import {
    TabSearchFilter,
    TabSegmentedFilter,
    visitClientTab,
} from '@/components/clients/tab-filters';
import {
    ClientLayout,
    TabHeading,
    TabPanel,
} from '@/components/clients/client-layout';
import type { Paginated } from '@/components/pagination';
import { Pagination } from '@/components/pagination';

type InvoiceData = InvoiceRow & { can: InvoiceAbilities };

type BillingFilters = {
    search: string | null;
    status: string | null;
};

// The org ledger's status filters (`InvoiceLedgerService::STATUS_FILTERS`), in workflow order.
const statusFilters = [
    { value: null, label: 'All' },
    { value: 'draft', label: 'Draft' },
    { value: 'outstanding', label: 'Outstanding' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'paid', label: 'Paid' },
    { value: 'void', label: 'Void' },
];

/**
 * Step I-2.4 — Billing tab (`clients.billing`), paginated by issue date and filtered server-side
 * by status and invoice number/description search.
 */
export default function ClientBillingTab({
    invoices: invoicePage,
    filters,
    currencies,
    ...page
}: ClientPageProps & {
    invoices: Paginated<InvoiceData>;
    filters: BillingFilters;
    currencies: CurrencyOption[];
}) {
    const { client, can } = page;
    const invoices = invoicePage.data;
    const filtered = filters.search !== null || filters.status !== null;
    const visit = (changes: Partial<BillingFilters> & { page?: number }) =>
        visitClientTab(billing(client.id).url, filters, changes, [
            'invoices',
            'filters',
        ]);
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

                <div className="flex flex-wrap gap-3">
                    <TabSegmentedFilter
                        label="Filter by status"
                        value={filters.status}
                        options={statusFilters}
                        onChange={(status) => visit({ status })}
                    />
                    <TabSearchFilter
                        id="invoices-search"
                        label="Search invoices"
                        placeholder="Number or description"
                        value={filters.search}
                        onSearch={(search) => visit({ search })}
                    />
                </div>

                {invoices.length === 0 ? (
                    filtered ? (
                        <p className="text-muted-foreground text-sm">
                            No invoices match the current filters.
                        </p>
                    ) : (
                        <EmptyState
                            compact
                            icon={Receipt}
                            title="Nothing billed yet."
                            description="Start a draft, add what you delivered, and send it when it's ready."
                        />
                    )
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

                <Pagination
                    className="pt-2"
                    page={invoicePage}
                    label="invoices"
                    onPageChange={(page) => visit({ page })}
                />
            </TabPanel>
        </ClientLayout>
    );
}

ClientBillingTab.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
