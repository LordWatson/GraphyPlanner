import { Head, Link, router } from '@inertiajs/react';
import { BookmarkPlus, Download, FileBarChart, FileText } from 'lucide-react';
import { useState } from 'react';
import { describeRange, pad } from '@/components/analytics/format';
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
import { ChapterHero, Page } from '@/components/page';
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
    index as analyticsIndex,
    pdf as analyticsPdf,
} from '@/routes/analytics';
import {
    create as createReport,
    index as reportsIndex,
} from '@/routes/analytics/reports';

type Props = {
    summary: OrgSummary;
    filters: OrgFilters;
    filterOptions: OrgFilterOptions;
    can: AnalyticsAbilities;
};

/**
 * Phase 17 — chapter 06 Analytics: the studio's own figures, in its own timezone. Filters re-query
 * the server (state is preserved, so it feels instant); "Save as report" carries them into the
 * report builder; exports download exactly what's on screen for this viewer.
 */
export default function Analytics({
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
            analyticsIndex.url({ query: orgFilterQuery(next) }),
            undefined,
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );

    const published = summary.posts?.published ?? null;

    return (
        <>
            <Head title="Analytics" />
            <Page>
                <ChapterHero
                    eyebrow={`${formatDateRange(filters.from, filters.to)} · ${filters.timezone}`}
                    title={
                        <>
                            How the studio is <em>tracking</em>.
                        </>
                    }
                    description="Output, approvals, workload, billing and AI — every figure counted live, in your studio's own timezone."
                    actions={
                        <>
                            {can.saveReport && (
                                <Button variant="spark" size="lg" asChild>
                                    <Link href={createReport({ query })}>
                                        <BookmarkPlus />
                                        Save as report
                                    </Link>
                                </Button>
                            )}
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="on-ink" size="lg">
                                        <Download />
                                        Export
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="start">
                                    {/* File downloads, not Inertia visits. */}
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
                            <Button variant="on-ink" size="lg" asChild>
                                <Link href={reportsIndex()}>Saved reports</Link>
                            </Button>
                        </>
                    }
                    aside={
                        published !== null && (
                            <div className="flex flex-col items-start gap-2 lg:items-end">
                                <span className="eyebrow text-cream/70">
                                    Published · {describeRange(filters)}
                                </span>
                                <span className="font-display text-lime text-[6.5rem] leading-[0.85] font-bold tracking-[-0.05em] tabular-nums sm:text-[8.5rem]">
                                    {pad(published)}
                                </span>
                                <span className="text-cream/80 text-sm">
                                    {published === 1
                                        ? 'Post out the door'
                                        : 'Posts out the door'}
                                </span>
                            </div>
                        )
                    }
                />

                <OrganizationFilterBar
                    value={state}
                    onChange={apply}
                    options={filterOptions}
                    sections={sections}
                    className="animate-rise [animation-delay:80ms]"
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

Analytics.layout = {
    breadcrumbs: [{ title: 'Analytics', href: analyticsIndex() }],
};
