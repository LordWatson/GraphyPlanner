import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { RangePicker } from '@/components/analytics/filter-controls';
import { OrganizationAnalyticsView } from '@/components/analytics/organization-analytics-view';
import type { OrgSummary } from '@/components/analytics/types';
import { orgFilterQuery } from '@/components/analytics/types';
import { Page, PageHeader } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { formatDateRange } from '@/lib/dates';
import {
    analytics as organizationAnalytics,
    index as organizationsIndex,
    show as showOrganization,
} from '@/routes/super-admin/organizations';

/**
 * Step 15.4 / 17.9 — the per-org drill-down. It renders the organization's own analytics (every
 * section, in the org's timezone), so support and sales see exactly what the customer's Owner
 * sees on their 06 Analytics page.
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
    summary: OrgSummary;
}) {
    return (
        <>
            <Head title={`${organization.name} analytics`} />
            <Page>
                <PageHeader
                    eyebrow={`${formatDateRange(summary.filters.from, summary.filters.to)} · ${summary.filters.timezone}`}
                    title={
                        <>
                            {organization.name}, in <em>numbers</em>.
                        </>
                    }
                    description="The organization's own analytics — exactly what its Owner sees, in their timezone."
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

                <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <RangePicker
                        range={summary.filters.range}
                        from={summary.filters.from}
                        to={summary.filters.to}
                        onChange={(range) =>
                            router.get(
                                organizationAnalytics.url(organization.id, {
                                    query: orgFilterQuery(range),
                                }),
                                undefined,
                                {
                                    preserveState: true,
                                    preserveScroll: true,
                                    replace: true,
                                },
                            )
                        }
                    />
                </div>

                <OrganizationAnalyticsView summary={summary} links={false} />
            </Page>
        </>
    );
}

OrganizationAnalytics.layout = {
    breadcrumbs: [{ title: 'Organizations', href: organizationsIndex() }],
};
