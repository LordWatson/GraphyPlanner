import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { StatusPill } from '@/components/status-pill';
import { formatCurrency } from '@/lib/currency';
import { cn } from '@/lib/utils';
import { show as showInvoice } from '@/routes/invoices';
import type { InvoiceRow } from './types';
import { formatInvoiceDate } from './types';

/**
 * The flat, hairline invoice ledger (brand guidelines §5 lists): mono number, client, dates,
 * amount and a status pill — overdue rows call out how late they are. Used by the org-wide
 * invoices page and the client's Billing tab.
 */
export function InvoiceLedgerList({
    invoices,
    currency,
    showClient = true,
    actions,
}: {
    invoices: InvoiceRow[];
    currency: string;
    showClient?: boolean;
    /** Optional per-row actions rendered beside the row (outside the link). */
    actions?: (invoice: InvoiceRow) => ReactNode;
}) {
    const columns = showClient
        ? 'md:grid-cols-[8.5rem_minmax(0,1.4fr)_7rem_7rem_8.5rem_7.5rem_1.25rem]'
        : 'md:grid-cols-[8.5rem_minmax(0,1.4fr)_7rem_8.5rem_7.5rem_1.25rem]';

    return (
        <div className="border-border bg-card overflow-hidden rounded-2xl border">
            <div
                className={cn(
                    'eyebrow border-border text-muted-foreground hidden items-center gap-4 border-b px-5 py-3 md:grid',
                    columns,
                )}
            >
                <span>Invoice</span>
                <span>{showClient ? 'Client' : 'Notes'}</span>
                {showClient && <span>Issued</span>}
                <span>Due</span>
                <span className="text-right">Amount</span>
                <span>Status</span>
                <span />
            </div>
            <ul className="divide-border/70 divide-y">
                {invoices.map((invoice) => (
                    <li
                        key={invoice.id}
                        className="hover:bg-muted/50 flex flex-wrap items-center gap-x-3 transition-colors md:flex-nowrap"
                    >
                        <Link
                            href={showInvoice(invoice.id)}
                            className={cn(
                                'group grid min-w-0 flex-1 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 px-5 py-3.5',
                                columns,
                            )}
                        >
                            <span className="group-hover:text-primary truncate font-mono text-sm font-medium transition-colors">
                                {invoice.invoice_number}
                            </span>
                            <span className="text-right font-mono text-sm tabular-nums md:hidden">
                                {formatCurrency(invoice.amount, currency)}
                            </span>
                            <span className="text-muted-foreground md:text-foreground min-w-0 truncate text-sm">
                                {showClient
                                    ? (invoice.client?.name ?? '—')
                                    : (invoice.description ?? (
                                          <span className="text-muted-foreground">
                                              Issued{' '}
                                              {formatInvoiceDate(
                                                  invoice.issue_date,
                                              )}
                                          </span>
                                      ))}
                            </span>
                            <span className="justify-self-end md:hidden">
                                <StatusPill
                                    kind="invoice"
                                    value={invoice.display_status}
                                    label={invoice.display_status_label}
                                />
                            </span>
                            {showClient && (
                                <span className="text-muted-foreground hidden font-mono text-xs tabular-nums md:inline">
                                    {formatInvoiceDate(invoice.issue_date)}
                                </span>
                            )}
                            <span
                                className={cn(
                                    'hidden font-mono text-xs tabular-nums md:inline',
                                    invoice.is_overdue
                                        ? 'text-destructive'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {formatInvoiceDate(invoice.due_date)}
                                {invoice.is_overdue && (
                                    <span className="block text-[11px]">
                                        {invoice.days_overdue}d late
                                    </span>
                                )}
                            </span>
                            <span
                                className={cn(
                                    'hidden text-right font-mono text-sm tabular-nums md:inline',
                                    invoice.status === 'void' &&
                                        'text-muted-foreground line-through',
                                )}
                            >
                                {formatCurrency(invoice.amount, currency)}
                            </span>
                            <span className="hidden md:inline">
                                <StatusPill
                                    kind="invoice"
                                    value={invoice.display_status}
                                    label={invoice.display_status_label}
                                />
                            </span>
                            <ArrowUpRight className="text-muted-foreground group-hover:text-primary hidden size-4 justify-self-end transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 md:block" />
                        </Link>
                        {actions && (
                            <div className="flex shrink-0 items-center gap-1.5 px-5 pb-3.5 md:pb-0 md:pl-0">
                                {actions(invoice)}
                            </div>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
