import { Building2, Layers } from 'lucide-react';
import {
    MultiSelectFilter,
    RangePicker,
} from '@/components/analytics/filter-controls';
import { cn } from '@/lib/utils';
import type { MetricKey, Option, RangeKey } from './types';
import { metricLabels } from './types';

export type FilterState = {
    range: RangeKey;
    from: string;
    to: string;
    org_ids: number[];
    metrics: MetricKey[];
};

const metrics = Object.keys(metricLabels) as MetricKey[];

/** Builds the query string the analytics endpoints read (`AnalyticsFilterRequest`). */
export function filterQuery(
    state: FilterState,
): Record<string, string | number | (string | number)[]> {
    return {
        range: state.range,
        ...(state.range === 'custom' ? { from: state.from, to: state.to } : {}),
        ...(state.org_ids.length ? { org_ids: state.org_ids } : {}),
        ...(state.metrics.length ? { metrics: state.metrics } : {}),
    };
}

/**
 * The analytics filter row (Step 15.4), shared by the analytics page, the per-org drill-down and
 * the report builder: a segmented range selector (teal pill = selected), custom dates, and
 * dropdown pickers for organizations and metric sections. Controlled — the parent decides
 * whether a change re-queries the server or just updates a form. Built from the shared
 * `components/analytics` controls (Step 17.4) so it matches the studio's own analytics.
 */
export function AnalyticsFilterBar({
    value,
    onChange,
    organizationOptions,
    showMetrics = true,
    className,
}: {
    value: FilterState;
    onChange: (next: FilterState) => void;
    /** Omit to hide the organization picker (e.g. a single org's drill-down). */
    organizationOptions?: Option[];
    showMetrics?: boolean;
    className?: string;
}) {
    const update = (next: Partial<FilterState>) =>
        onChange({ ...value, ...next });

    return (
        <div
            className={cn(
                'flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-center',
                className,
            )}
        >
            <RangePicker
                range={value.range}
                from={value.from}
                to={value.to}
                onChange={(range) => update(range)}
            />

            <div className="flex flex-wrap items-center gap-2 lg:ml-auto">
                {organizationOptions && (
                    <MultiSelectFilter
                        icon={Building2}
                        label="Organizations"
                        allLabel="All organizations"
                        noun="organizations"
                        options={organizationOptions}
                        value={value.org_ids}
                        onChange={(org_ids) => update({ org_ids })}
                    />
                )}

                {showMetrics && (
                    <MultiSelectFilter
                        icon={Layers}
                        label="Metric sections"
                        allLabel="All metrics"
                        noun="metrics"
                        mode="sections"
                        options={metrics.map((metric) => ({
                            value: metric,
                            label: metricLabels[metric],
                        }))}
                        value={value.metrics}
                        onChange={(next) => update({ metrics: next })}
                    />
                )}
            </div>
        </div>
    );
}
