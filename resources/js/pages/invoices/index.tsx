import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronLeft,
    ChevronRight,
    Clock,
    Download,
    Receipt,
    Search,
    Wallet,
    X,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { InvoiceLedgerList } from '@/components/invoices/invoice-ledger-list';
import { NewInvoiceDialog } from '@/components/invoices/new-invoice-dialog';
import type { CurrencyOption, InvoiceRow } from '@/components/invoices/types';
import { EmptyState, Page, PageHeader, Stat } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { CurrencyTotal } from '@/lib/currency';
import { formatCurrencyTotals } from '@/lib/currency';
import { cn } from '@/lib/utils';
import invoicesRoutes, { index as invoicesIndex } from '@/routes/invoices';

type Filters = {
    status: string | null;
    client_id: string | null;
    search: string | null;
    from: string | null;
    to: string | null;
};

/** Money totals are split per currency (org default first) — see `InvoiceLedgerService::summary()`. */
type Summary = {
    outstanding_totals: CurrencyTotal[];
    outstanding_count: number;
    overdue_totals: CurrencyTotal[];
    overdue_count: number;
    paid_this_month_totals: CurrencyTotal[];
    paid_this_month_count: number;
    draft_count: number;
    average_days_to_pay: number | null;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Option = { value: string; label: string };

const statusTabs: { value: string | null; label: string }[] = [
    { value: null, label: 'All' },
    { value: 'draft', label: 'Drafts' },
    { value: 'outstanding', label: 'Outstanding' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'paid', label: 'Paid' },
    { value: 'void', label: 'Void' },
];

const ALL_CLIENTS = 'all';

/**
 * The studio's invoice ledger: headline cash figures, then every invoice across every client,
 * filterable by status (including derived "overdue"), client, issue date and a free-text search.
 */
export default function InvoicesIndex({
    invoices,
    summary,
    filters,
    clients,
    currencies,
}: {
    invoices: Paginated<InvoiceRow>;
    summary: Summary;
    filters: Filters;
    clients: Option[];
    currencies: CurrencyOption[];
}) {
    const { currency } = usePage<{ currency: string }>().props;
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(
            Object.entries(merged).filter(
                ([, value]) => value !== null && value !== '',
            ),
        );

        router.get(invoicesIndex.url({ query }), undefined, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        applyFilters({ search: search.trim() || null });
    };

    const hasFilters = Object.values(filters).some((value) => value);
    const exportQuery = Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value),
    ) as Record<string, string>;

    return (
        <>
            <Head title="Invoices" />
            <Page>
                <PageHeader
                    eyebrow={`Billing — ${summary.outstanding_count} awaiting payment`}
                    title={
                        <>
                            The <em>ledger</em>
                        </>
                    }
                    description="Every invoice across every client — what's owed, what's late, and what's landed."
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <a
                                    href={invoicesRoutes.export.url({
                                        query: exportQuery,
                                    })}
                                >
                                    <Download />
                                    Export CSV
                                </a>
                            </Button>
                            {clients.length > 0 && (
                                <NewInvoiceDialog
                                    clients={clients}
                                    currencies={currencies}
                                    defaultCurrency={currency}
                                />
                            )}
                        </>
                    }
                />

                <div className="animate-rise grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Stat
                        tone="ink"
                        label="Outstanding"
                        icon={Wallet}
                        value={formatCurrencyTotals(
                            summary.outstanding_totals,
                            currency,
                            { compact: true },
                        )}
                        hint={`${formatCurrencyTotals(summary.outstanding_totals, currency)} across ${summary.outstanding_count} invoice${summary.outstanding_count === 1 ? '' : 's'}`}
                    />
                    <Stat
                        label="Overdue"
                        icon={AlertTriangle}
                        value={formatCurrencyTotals(
                            summary.overdue_totals,
                            currency,
                            { compact: true },
                        )}
                        hint={
                            summary.overdue_count > 0 ? (
                                <button
                                    type="button"
                                    onClick={() =>
                                        applyFilters({ status: 'overdue' })
                                    }
                                    className="text-destructive underline-offset-2 hover:underline"
                                >
                                    {summary.overdue_count} past due — review
                                </button>
                            ) : (
                                'Nothing late. Lovely.'
                            )
                        }
                    />
                    <Stat
                        label="Paid this month"
                        icon={Receipt}
                        value={formatCurrencyTotals(
                            summary.paid_this_month_totals,
                            currency,
                            { compact: true },
                        )}
                        hint={`${summary.paid_this_month_count} payment${summary.paid_this_month_count === 1 ? '' : 's'} received`}
                    />
                    <Stat
                        label="Avg. days to pay"
                        icon={Clock}
                        value={summary.average_days_to_pay ?? '—'}
                        hint={
                            summary.draft_count > 0
                                ? `${summary.draft_count} draft${summary.draft_count === 1 ? '' : 's'} not yet sent`
                                : 'Sent → paid, last 12 months'
                        }
                    />
                </div>

                <section className="animate-rise flex flex-col gap-4">
                    <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                        <div
                            role="tablist"
                            aria-label="Filter by status"
                            className="bg-muted inline-flex w-fit max-w-full shrink-0 gap-1 overflow-x-auto rounded-full border p-1"
                        >
                            {statusTabs.map((tab) => {
                                const active = filters.status === tab.value;

                                return (
                                    <button
                                        key={tab.label}
                                        type="button"
                                        role="tab"
                                        aria-selected={active}
                                        onClick={() =>
                                            applyFilters({ status: tab.value })
                                        }
                                        className={cn(
                                            'focus-visible:ring-ring/40 shrink-0 rounded-full px-3.5 py-1 text-[13px] font-medium whitespace-nowrap transition-colors outline-none focus-visible:ring-[3px]',
                                            active
                                                ? 'bg-primary text-primary-foreground'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {tab.label}
                                    </button>
                                );
                            })}
                        </div>

                        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                            <Select
                                value={filters.client_id ?? ALL_CLIENTS}
                                onValueChange={(value) =>
                                    applyFilters({
                                        client_id:
                                            value === ALL_CLIENTS
                                                ? null
                                                : value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="w-full sm:w-44"
                                    aria-label="Filter by client"
                                >
                                    <SelectValue placeholder="All clients" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL_CLIENTS}>
                                        All clients
                                    </SelectItem>
                                    {clients.map((client) => (
                                        <SelectItem
                                            key={client.value}
                                            value={client.value}
                                        >
                                            {client.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <div className="flex items-center gap-1.5">
                                <Input
                                    type="date"
                                    aria-label="Issued from"
                                    className="w-full sm:w-36"
                                    value={filters.from ?? ''}
                                    onChange={(event) =>
                                        applyFilters({
                                            from: event.target.value || null,
                                        })
                                    }
                                />
                                <span className="text-muted-foreground font-mono text-xs">
                                    →
                                </span>
                                <Input
                                    type="date"
                                    aria-label="Issued to"
                                    className="w-full sm:w-36"
                                    min={filters.from ?? undefined}
                                    value={filters.to ?? ''}
                                    onChange={(event) =>
                                        applyFilters({
                                            to: event.target.value || null,
                                        })
                                    }
                                />
                            </div>
                            <form
                                onSubmit={submitSearch}
                                className="relative"
                                role="search"
                            >
                                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                                <Input
                                    type="search"
                                    aria-label="Search invoices"
                                    placeholder="Number, client, notes…"
                                    className="w-full pl-9 sm:w-56"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                            </form>
                            {hasFilters && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        setSearch('');
                                        router.get(
                                            invoicesIndex.url(),
                                            undefined,
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    <X />
                                    Clear
                                </Button>
                            )}
                        </div>
                    </div>

                    {invoices.data.length === 0 ? (
                        <EmptyState
                            compact={hasFilters}
                            icon={Receipt}
                            title={
                                hasFilters
                                    ? 'Nothing matches.'
                                    : 'No invoices yet.'
                            }
                            description={
                                hasFilters
                                    ? 'Try a different status, client or date range.'
                                    : 'Raise your first invoice and it will land here, alongside every client.'
                            }
                            action={
                                !hasFilters &&
                                clients.length > 0 && (
                                    <NewInvoiceDialog
                                        clients={clients}
                                        currencies={currencies}
                                        defaultCurrency={currency}
                                    />
                                )
                            }
                        />
                    ) : (
                        <>
                            <InvoiceLedgerList invoices={invoices.data} />
                            {invoices.last_page > 1 && (
                                <nav
                                    aria-label="Pagination"
                                    className="flex items-center justify-between gap-3"
                                >
                                    <span className="text-muted-foreground font-mono text-xs tabular-nums">
                                        {invoices.from}–{invoices.to} of{' '}
                                        {invoices.total}
                                    </span>
                                    <div className="flex items-center gap-2">
                                        <PageLink
                                            href={invoices.prev_page_url}
                                            label="Previous"
                                            icon="prev"
                                        />
                                        <PageLink
                                            href={invoices.next_page_url}
                                            label="Next"
                                            icon="next"
                                        />
                                    </div>
                                </nav>
                            )}
                        </>
                    )}
                </section>
            </Page>
        </>
    );
}

function PageLink({
    href,
    label,
    icon,
}: {
    href: string | null;
    label: string;
    icon: 'prev' | 'next';
}) {
    const content = (
        <>
            {icon === 'prev' && <ChevronLeft />}
            {label}
            {icon === 'next' && <ChevronRight />}
        </>
    );

    if (!href) {
        return (
            <Button variant="outline" size="sm" disabled>
                {content}
            </Button>
        );
    }

    return (
        <Button variant="outline" size="sm" asChild>
            <Link href={href} preserveScroll preserveState>
                {content}
            </Link>
        </Button>
    );
}

InvoicesIndex.layout = {
    breadcrumbs: [{ title: 'Invoices', href: invoicesIndex() }],
};
