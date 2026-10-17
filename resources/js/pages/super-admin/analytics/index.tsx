import { Head, Link, router } from '@inertiajs/react';
import { BookmarkPlus } from 'lucide-react';
import { Page, PageHeader } from '@/components/page';
import type { FilterState } from '@/components/super-admin/analytics-filter-bar';
import {
    AnalyticsFilterBar,
    filterQuery,
} from '@/components/super-admin/analytics-filter-bar';
import { PlatformAnalyticsView } from '@/components/super-admin/platform-analytics-view';
import type { Option, PlatformSummary } from '@/components/super-admin/types';
import { Button } from '@/components/ui/button';
import { formatDateRange } from '@/lib/dates';
import { index as analyticsIndex } from '@/routes/super-admin/analytics';
import { create as createReport } from '@/routes/super-admin/reports';

/**
 * Step 15.4 — the platform-wide counterpart to a studio's own dashboard: every figure across every
 * organization, filterable by range, organizations and metric sections. "Save as report" carries
 * the current filters into the report builder (Step 15.5).
 */
export default function PlatformAnalytics({
    summary,
    organizationOptions,
}: {
    summary: PlatformSummary;
    organizationOptions: Option[];
}) {
    const state: FilterState = {
        range: summary.filters.range,
        from: summary.filters.from,
        to: summary.filters.to,
        org_ids: summary.filters.org_ids,
        metrics: summary.filters.metrics,
    };

    const apply = (next: FilterState) =>
        router.get(
            analyticsIndex.url({ query: filterQuery(next) }),
            undefined,
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="Analytics" />
            <Page>
                <PageHeader
                    eyebrow={formatDateRange(
                        summary.filters.from,
                        summary.filters.to,
                    )}
                    title={
                        <>
                            The platform, in <em>numbers</em>.
                        </>
                    }
                    description="Signups, publishing, billing and AI usage across every organization — demo data from the preview studio left out."
                    actions={
                        <Button variant="outline" asChild>
                            <Link
                                href={createReport({
                                    query: filterQuery(state),
                                })}
                            >
                                <BookmarkPlus />
                                Save as report
                            </Link>
                        </Button>
                    }
                />

                <AnalyticsFilterBar
                    value={state}
                    onChange={apply}
                    organizationOptions={organizationOptions}
                />

                <PlatformAnalyticsView summary={summary} />
            </Page>
        </>
    );
}

PlatformAnalytics.layout = {
    breadcrumbs: [{ title: 'Analytics', href: analyticsIndex() }],
};
