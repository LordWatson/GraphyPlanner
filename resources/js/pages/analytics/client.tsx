import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    BookmarkPlus,
    Download,
    FileBarChart,
    FileText,
} from 'lucide-react';
import { useState } from 'react';
import { OrganizationAnalyticsView } from '@/components/analytics/organization-analytics-view';
import { OrganizationFilterBar } from '@/components/analytics/organization-filter-bar';
import type {
    AnalyticsAbilities,
    OrgFilterOptions,
    OrgFilterState,
    OrgFilters,
    OrgSummary,
} from '@/components/analytics/types';
import {
    orgFilterQuery,
    sectionKeys,
    stateFromFilters,
} from '@/components/analytics/types';
import { Page, PageHeader } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDateRange } from '@/lib/dates';
import {
    exportMethod as exportAnalytics,
    pdf as analyticsPdf,
} from '@/routes/analytics';
import { create as createReport } from '@/routes/analytics/reports';
import {
    analytics as clientAnalytics,
    index as clientsIndex,
    show as showClient,
} from '@/routes/clients';

type Props = {
    client: { id: number; name: string; status: string };
    summary: OrgSummary;
    filters: OrgFilters;
    filterOptions: OrgFilterOptions;
    can: AnalyticsAbilities;
};

/**
 * Step 17.5 — one client's analytics: the studio's analytics scoped to this client, with its
 * campaigns in place of the busiest-clients list. Phase 12 adds engagement here.
 */
export default function ClientAnalytics({
    client,
    summary,
    filters,
    filterOptions,
    can,
}: Props) {
    const [loading, setLoading] = useState(false);
    const state = stateFromFilters(filters);
    const query = orgFilterQuery(state);
    const sections = sectionKeys.filter(
        (section) => section !== 'revenue' || can.viewRevenue,
    );

    const apply = (next: OrgFilterState) =>
        router.get(
            clientAnalytics.url(client.id, {
                query: orgFilterQuery({ ...next, client_ids: [] }),
            }),
            undefined,
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );

    return (
        <>
            <Head title={`${client.name} analytics`} />
            <Page>
                <PageHeader
                    eyebrow={`${formatDateRange(filters.from, filters.to)} · ${filters.timezone}`}
                    title={
                        <>
                            {client.name}, in <em>numbers</em>.
                        </>
                    }
                    description="What went out, where approvals stalled and what was billed — for this client alone."
                    meta={
                        summary.client && (
                            <>
                                <StatusPill
                                    kind="client"
                                    value={client.status}
                                />
                                <StatusPill
                                    kind="health"
                                    value={summary.client.health}
                                    title={summary.client.reason}
                                />
                            </>
                        )
                    }
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={showClient(client.id)}>
                                    <ArrowLeft />
                                    Back to client
                                </Link>
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="outline">
                                        <Download />
                                        Export
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem asChild>
                                        <a
                                            href={exportAnalytics.url({
                                                query,
                                            })}
                                        >
                                            <FileBarChart />
                                            CSV spreadsheet
                                        </a>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem asChild>
                                        <a href={analyticsPdf.url({ query })}>
                                            <FileText />
                                            Branded PDF
                                        </a>
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                            {can.saveReport && (
                                <Button asChild>
                                    <Link href={createReport({ query })}>
                                        <BookmarkPlus />
                                        Save as report
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <OrganizationFilterBar
                    value={state}
                    onChange={apply}
                    options={filterOptions}
                    sections={sections}
                    showClients={false}
                />

                <div
                    aria-busy={loading}
                    className="transition-opacity duration-200 aria-busy:opacity-60"
                >
                    <OrganizationAnalyticsView summary={summary} />
                </div>
            </Page>
        </>
    );
}

ClientAnalytics.layout = {
    breadcrumbs: [{ title: 'Clients', href: clientsIndex() }],
};
