import { Building2, ChevronDown, Layers } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { MetricKey, Option, RangeKey } from './types';
import { metricLabels, rangeLabels } from './types';

export type FilterState = {
    range: RangeKey;
    from: string;
    to: string;
    org_ids: number[];
    metrics: MetricKey[];
};

const ranges = Object.keys(rangeLabels) as RangeKey[];
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

function toggle<T>(list: T[], value: T): T[] {
    return list.includes(value)
        ? list.filter((item) => item !== value)
        : [...list, value];
}

/**
 * The analytics filter row (Step 15.4), shared by the analytics page, the per-org drill-down and
 * the report builder: a segmented range selector (teal pill = selected), custom dates, and
 * dropdown pickers for organizations and metric sections. Controlled — the parent decides
 * whether a change re-queries the server or just updates a form.
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

    const orgSummary =
        value.org_ids.length === 0
            ? 'All organizations'
            : value.org_ids.length === 1
              ? (organizationOptions?.find(
                    (option) => option.value === value.org_ids[0],
                )?.label ?? '1 organization')
              : `${value.org_ids.length} organizations`;

    const metricSummary =
        value.metrics.length === 0 || value.metrics.length === metrics.length
            ? 'All metrics'
            : value.metrics.length === 1
              ? metricLabels[value.metrics[0]]
              : `${value.metrics.length} metrics`;

    return (
        <div
            className={cn(
                'flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-center',
                className,
            )}
        >
            <div
                role="radiogroup"
                aria-label="Date range"
                className="bg-muted flex max-w-full gap-1 overflow-x-auto rounded-full p-1"
            >
                {ranges.map((range) => {
                    const active = value.range === range;

                    return (
                        <button
                            key={range}
                            type="button"
                            role="radio"
                            aria-checked={active}
                            // Switching to "custom" starts from the dates currently shown.
                            onClick={() => update({ range })}
                            className={cn(
                                'focus-visible:ring-ring/40 shrink-0 rounded-full px-3.5 py-1 text-[13px] font-medium whitespace-nowrap transition-colors outline-none focus-visible:ring-[3px]',
                                active
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {rangeLabels[range]}
                        </button>
                    );
                })}
            </div>

            {value.range === 'custom' && (
                <div className="flex items-center gap-1.5">
                    <Input
                        type="date"
                        aria-label="From"
                        className="w-full sm:w-38"
                        value={value.from}
                        max={value.to || undefined}
                        onChange={(event) =>
                            event.target.value &&
                            update({ from: event.target.value })
                        }
                    />
                    <span className="text-muted-foreground font-mono text-xs">
                        →
                    </span>
                    <Input
                        type="date"
                        aria-label="To"
                        className="w-full sm:w-38"
                        value={value.to}
                        min={value.from || undefined}
                        onChange={(event) =>
                            event.target.value &&
                            update({ to: event.target.value })
                        }
                    />
                </div>
            )}

            <div className="flex flex-wrap items-center gap-2 lg:ml-auto">
                {organizationOptions && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" size="sm">
                                <Building2 />
                                <span className="max-w-44 truncate">
                                    {orgSummary}
                                </span>
                                <ChevronDown className="opacity-60" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="end"
                            className="max-h-80 w-64 overflow-y-auto"
                        >
                            <DropdownMenuLabel className="eyebrow text-muted-foreground">
                                Organizations
                            </DropdownMenuLabel>
                            <DropdownMenuItem
                                onSelect={(event) => {
                                    event.preventDefault();
                                    update({ org_ids: [] });
                                }}
                            >
                                All organizations
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            {organizationOptions.map((option) => (
                                <DropdownMenuCheckboxItem
                                    key={option.value}
                                    checked={value.org_ids.includes(
                                        option.value,
                                    )}
                                    onSelect={(event) => event.preventDefault()}
                                    onCheckedChange={() =>
                                        update({
                                            org_ids: toggle(
                                                value.org_ids,
                                                option.value,
                                            ),
                                        })
                                    }
                                >
                                    <span className="truncate">
                                        {option.label}
                                    </span>
                                </DropdownMenuCheckboxItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}

                {showMetrics && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" size="sm">
                                <Layers />
                                {metricSummary}
                                <ChevronDown className="opacity-60" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                            <DropdownMenuLabel className="eyebrow text-muted-foreground">
                                Metric sections
                            </DropdownMenuLabel>
                            {metrics.map((metric) => (
                                <DropdownMenuCheckboxItem
                                    key={metric}
                                    checked={
                                        value.metrics.length === 0 ||
                                        value.metrics.includes(metric)
                                    }
                                    onSelect={(event) => event.preventDefault()}
                                    onCheckedChange={() => {
                                        const current =
                                            value.metrics.length === 0
                                                ? metrics
                                                : value.metrics;
                                        const next = toggle(current, metric);

                                        // Never allow an empty run; "all" is stored as [].
                                        if (next.length === 0) {
                                            return;
                                        }

                                        update({
                                            metrics:
                                                next.length === metrics.length
                                                    ? []
                                                    : metrics.filter((key) =>
                                                          next.includes(key),
                                                      ),
                                        });
                                    }}
                                >
                                    {metricLabels[metric]}
                                </DropdownMenuCheckboxItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
        </div>
    );
}
