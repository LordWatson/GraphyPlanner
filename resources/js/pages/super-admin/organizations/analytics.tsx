import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Page, PageHeader } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import type { FilterState } from '@/components/super-admin/analytics-filter-bar';
import {
    AnalyticsFilterBar,
    filterQuery,
} from '@/components/super-admin/analytics-filter-bar';
import { PlatformAnalyticsView } from '@/components/super-admin/platform-analytics-view';
import type { PlatformSummary } from '@/components/super-admin/types';
import { Button } from '@/components/ui/button';
import { formatDateRange } from '@/lib/dates';
import {
    analytics as organizationAnalytics,
    index as organizationsIndex,
    show as showOrganization,
} from '@/routes/super-admin/organizations';

/**
 * Step 15.4 — the per-org drill-down: the platform analytics scoped to one organization, for
 * support and sales conversations.
 */
export default function OrganizationAnalytics({
    organization,
    summary,
}: {
    organization: {
        id: number;
        name: string;
        is_suspended: boolean;
        is_preview: boolean;
    };
    summary: PlatformSummary;
}) {
    const state: FilterState = {
        range: summary.filters.range,
        from: summary.filters.from,
        to: summary.filters.to,
        org_ids: [],
        metrics: summary.filters.metrics,
    };

    const apply = (next: FilterState) =>
        router.get(
            organizationAnalytics.url(organization.id, {
                query: filterQuery({ ...next, org_ids: [] }),
            }),
            undefined,
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title={`${organization.name} analytics`} />
            <Page>
                <PageHeader
                    eyebrow={formatDateRange(
                        summary.filters.from,
                        summary.filters.to,
                    )}
                    title={
                        <>
                            {organization.name}, in <em>numbers</em>.
                        </>
                    }
                    description="The same figures as platform analytics, for this organization alone."
                    meta={
                        organization.is_suspended ? (
                            <StatusPill kind="organization" value="suspended" />
                        ) : organization.is_preview ? (
                            <StatusPill kind="organization" value="preview" />
                        ) : undefined
                    }
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={showOrganization(organization.id)}>
                                <ArrowLeft />
                                Back to organization
                            </Link>
                        </Button>
                    }
                />

                <AnalyticsFilterBar value={state} onChange={apply} />

                <PlatformAnalyticsView summary={summary} scope="organization" />
            </Page>
        </>
    );
}

OrganizationAnalytics.layout = {
    breadcrumbs: [{ title: 'Organizations', href: organizationsIndex() }],
};
