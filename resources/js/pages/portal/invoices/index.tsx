import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowUpRight, Receipt } from 'lucide-react';

import {
    ChapterHero,
    EmptyState,
    HeroStat,
    Page,
    Stat,
} from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Badge } from '@/components/ui/badge';
import {
    formatCurrency,
    formatCurrencyTotals,
    totalsByCurrency,
} from '@/lib/currency';
import { show as portalInvoicesShow } from '@/routes/portal/invoices';
import { formatDate } from '@/lib/dates';

type InvoiceRow = {
    id: number;
    invoice_number: string;
    status: string;
    status_label: string;
    amount: string;
    currency: string;
    issue_date: string;
    due_date: string | null;
    display_status: string;
    display_status_label: string;
    is_unpaid: boolean;
    is_overdue: boolean;
    days_overdue: number;
};

/**
 * Step 6.6 — the client portal's read-only invoices: an outstanding/paid summary and a ledger,
 * with unpaid invoices clearly flagged. No create/edit/send/mark-paid actions are exposed here.
 */
export default function PortalInvoicesIndex({
    invoices,
}: {
    invoices: InvoiceRow[];
}) {
    const { currency } = usePage<{ currency: string }>().props;

    // Split per currency — invoices billed in different currencies are never added together.
    const outstanding = totalsByCurrency(
        invoices.filter((invoice) => invoice.is_unpaid),
        currency,
    );
    const paid = totalsByCurrency(
        invoices.filter((invoice) => invoice.status === 'paid'),
        currency,
    );
    const unpaidCount = invoices.filter((invoice) => invoice.is_unpaid).length;
    const overdueCount = invoices.filter(
        (invoice) => invoice.is_overdue,
    ).length;

    return (
        <>
            <Head title="Invoices — Client portal" />
            <Page>
                <ChapterHero
                    eyebrow="Billing"
                    title={
                        <>
                            Your <em>invoices</em>.
                        </>
                    }
                    description="Your billing history with the studio. Open any invoice to view or download the PDF."
                    aside={
                        <HeroStat
                            icon={Receipt}
                            eyebrow={
                                unpaidCount > 0 ? 'Outstanding' : 'Billing'
                            }
                            value={String(
                                unpaidCount > 0 ? unpaidCount : invoices.length,
                            ).padStart(2, '0')}
                            caption={
                                unpaidCount > 0
                                    ? overdueCount > 0
                                        ? `${overdueCount} overdue`
                                        : unpaidCount === 1
                                          ? 'Unpaid invoice'
                                          : 'Unpaid invoices'
                                    : 'Invoices in total'
                            }
                        />
                    }
                />

                {invoices.length === 0 ? (
                    <EmptyState
                        icon={Receipt}
                        title="No invoices yet."
                        description="Invoices from the studio will appear here as soon as they're issued."
                    />
                ) : (
                    <>
                        <div className="animate-rise grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <Stat
                                tone={unpaidCount > 0 ? 'ink' : 'paper'}
                                label="Outstanding"
                                value={formatCurrencyTotals(
                                    outstanding,
                                    currency,
                                )}
                                hint={
                                    unpaidCount > 0
                                        ? `${unpaidCount} unpaid invoice${unpaidCount === 1 ? '' : 's'}${overdueCount > 0 ? ` · ${overdueCount} overdue` : ''}`
                                        : 'Nothing to pay — thank you'
                                }
                                className="[&_.font-display]:text-4xl"
                            />
                            <Stat
                                label="Paid to date"
                                value={formatCurrencyTotals(paid, currency)}
                                className="[&_.font-display]:text-4xl"
                            />
                            <Stat
                                label="Invoices"
                                value={invoices.length}
                                className="[&_.font-display]:text-4xl"
                            />
                        </div>

                        <section className="animate-rise border-border bg-card overflow-hidden rounded-2xl border">
                            <div className="eyebrow border-border text-muted-foreground hidden grid-cols-[minmax(0,1.3fr)_1fr_1fr_8rem_6rem_1.5rem] items-center gap-4 border-b px-5 py-3 md:grid">
                                <span>Invoice</span>
                                <span>Issued</span>
                                <span>Due</span>
                                <span className="text-right">Amount</span>
                                <span>Status</span>
                                <span />
                            </div>
                            <ul className="divide-border/70 divide-y">
                                {invoices.map((invoice) => (
                                    <li key={invoice.id}>
                                        <Link
                                            href={portalInvoicesShow(
                                                invoice.id,
                                            )}
                                            className="group hover:bg-muted/50 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 px-5 py-4 transition-colors md:grid-cols-[minmax(0,1.3fr)_1fr_1fr_8rem_6rem_1.5rem]"
                                        >
                                            <span className="group-hover:text-primary truncate font-mono text-sm font-medium">
                                                {invoice.invoice_number}
                                            </span>
                                            <span className="text-right font-mono text-sm tabular-nums md:hidden">
                                                {formatCurrency(
                                                    invoice.amount,
                                                    invoice.currency,
                                                )}
                                            </span>
                                            <span className="text-muted-foreground font-mono text-xs tabular-nums">
                                                <span className="md:hidden">
                                                    Issued{' '}
                                                </span>
                                                {formatDate(invoice.issue_date)}
                                            </span>
                                            <span className="text-muted-foreground hidden font-mono text-xs tabular-nums md:inline">
                                                {formatDate(invoice.due_date)}
                                            </span>
                                            <span className="hidden text-right font-mono text-sm tabular-nums md:inline">
                                                {formatCurrency(
                                                    invoice.amount,
                                                    invoice.currency,
                                                )}
                                            </span>
                                            <span className="justify-self-end md:justify-self-start">
                                                {invoice.is_unpaid &&
                                                !invoice.is_overdue ? (
                                                    <Badge variant="warning">
                                                        <span
                                                            aria-hidden
                                                            className="bg-warning size-1.5 rounded-full"
                                                        />
                                                        Unpaid
                                                    </Badge>
                                                ) : (
                                                    <StatusPill
                                                        kind="invoice"
                                                        value={
                                                            invoice.display_status
                                                        }
                                                        label={
                                                            invoice.display_status_label
                                                        }
                                                    />
                                                )}
                                            </span>
                                            <ArrowUpRight className="text-muted-foreground group-hover:text-primary hidden size-4 justify-self-end transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 md:block" />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </>
                )}
            </Page>
        </>
    );
}
