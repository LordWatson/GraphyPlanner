import { Link } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import type { ReactNode } from 'react';
import type { ServerSort } from '@/components/data-table';
import { DataTable } from '@/components/data-table';
import type { Paginated } from '@/components/pagination';
import { StatusPill } from '@/components/status-pill';
import { formatCurrency } from '@/lib/currency';
import { cn } from '@/lib/utils';
import { show as showInvoice } from '@/routes/invoices';
import type { InvoiceRow } from './types';
import { formatInvoiceDate } from './types';

/**
 * The flat, hairline invoice ledger (brand guidelines §5 lists) on the shared `DataTable`: mono
 * number, client (or notes), dates, amount in each invoice's own currency and a status pill —
 * overdue rows call out how late they are. Used by the org-wide invoices page (server-sorted and
 * paginated) and the client's Billing tab.
 */
export function InvoiceLedgerList({
    invoices,
    showClient = true,
    actions,
    sort,
    onSortChange,
    pagination,
    onPageChange,
}: {
    invoices: InvoiceRow[];
    showClient?: boolean;
    /** Optional per-row actions in the last column. */
    actions?: (invoice: InvoiceRow) => ReactNode;
    sort?: ServerSort;
    onSortChange?: (sort: ServerSort) => void;
    pagination?: Pick<
        Paginated<unknown>,
        'current_page' | 'last_page' | 'total' | 'from' | 'to'
    >;
    onPageChange?: (page: number) => void;
}) {
    const sortable = onSortChange !== undefined;

    const columns: ColumnDef<InvoiceRow, unknown>[] = [
        {
            id: 'invoice_number',
            header: 'Invoice',
            enableSorting: sortable,
            enableHiding: false,
            cell: ({ row }) => (
                <Link
                    href={showInvoice(row.original.id)}
                    className="hover:text-primary font-mono text-sm font-medium transition-colors"
                >
                    {row.original.invoice_number}
                </Link>
            ),
        },
        showClient
            ? {
                  id: 'client',
                  header: 'Client',
                  meta: { label: 'Client', className: 'max-w-56 truncate' },
                  cell: ({ row }) => row.original.client?.name ?? '—',
              }
            : {
                  id: 'notes',
                  header: 'Notes',
                  meta: {
                      label: 'Notes',
                      hideBelow: 'md',
                      className: 'max-w-72 truncate',
                  },
                  cell: ({ row }) =>
                      row.original.description ?? (
                          <span className="text-muted-foreground">
                              Issued{' '}
                              {formatInvoiceDate(row.original.issue_date)}
                          </span>
                      ),
              },
        {
            id: 'issue_date',
            header: 'Issued',
            enableSorting: sortable,
            meta: {
                label: 'Issued',
                hideBelow: 'md',
                className:
                    'text-muted-foreground font-mono text-xs tabular-nums',
            },
            cell: ({ row }) => formatInvoiceDate(row.original.issue_date),
        },
        {
            id: 'due_date',
            header: 'Due',
            enableSorting: sortable,
            meta: { label: 'Due', hideBelow: 'sm' },
            cell: ({ row }) => (
                <span
                    className={cn(
                        'font-mono text-xs tabular-nums',
                        row.original.is_overdue
                            ? 'text-destructive'
                            : 'text-muted-foreground',
                    )}
                >
                    {formatInvoiceDate(row.original.due_date)}
                    {row.original.is_overdue && (
                        <span className="block text-[11px]">
                            {row.original.days_overdue}d late
                        </span>
                    )}
                </span>
            ),
        },
        {
            id: 'amount',
            header: 'Amount',
            enableSorting: sortable,
            meta: { label: 'Amount', align: 'right' },
            cell: ({ row }) => (
                <span
                    className={cn(
                        'font-mono text-sm',
                        row.original.status === 'void' &&
                            'text-muted-foreground line-through',
                    )}
                >
                    {formatCurrency(row.original.amount, row.original.currency)}
                </span>
            ),
        },
        {
            id: 'status',
            header: 'Status',
            meta: { label: 'Status' },
            cell: ({ row }) => (
                <StatusPill
                    kind="invoice"
                    value={row.original.display_status}
                    label={row.original.display_status_label}
                />
            ),
        },
        ...(actions
            ? [
                  {
                      id: 'actions',
                      header: () => <span className="sr-only">Actions</span>,
                      enableHiding: false,
                      meta: { className: 'w-0 whitespace-nowrap' },
                      cell: ({ row }) => (
                          <div className="flex items-center justify-end gap-1.5">
                              {actions(row.original)}
                          </div>
                      ),
                  } satisfies ColumnDef<InvoiceRow, unknown>,
              ]
            : []),
    ];

    return (
        <DataTable
            caption="Invoices"
            columns={columns}
            data={invoices}
            getRowId={(invoice) => String(invoice.id)}
            rowHref={(invoice) => showInvoice.url(invoice.id)}
            sort={sort}
            onSortChange={onSortChange}
            pagination={pagination}
            onPageChange={onPageChange}
            paginationLabel="invoices"
            storageKey={showClient ? 'invoices' : 'client-invoices'}
        />
    );
}
