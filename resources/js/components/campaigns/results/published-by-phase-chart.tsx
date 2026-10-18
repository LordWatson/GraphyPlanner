import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    axisTick,
    chartMargin,
    chartTooltipStyle,
    gridProps,
    legendProps,
} from '@/components/analytics/chart-theme';
import { tickInterval } from '@/components/analytics/format';

export type PhaseSeries = {
    granularity: 'day' | 'week';
    buckets: {
        date: string;
        total: number;
        by_phase: Record<string, number>;
    }[];
    phases: { id: number; name: string; colour: string | null }[];
};

/**
 * Brand §3.5 chart tokens in a fixed order, assigned by phase order — never cycled. Phases past
 * the fourth fold into "Other phases"; unphased posts get the neutral series.
 */
const SERIES_COLOURS = [
    'var(--color-chart-1)',
    'var(--color-chart-2)',
    'var(--color-chart-3)',
    'var(--color-chart-5)',
];
const MAX_NAMED = SERIES_COLOURS.length;

const dayLabel = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
});

/**
 * Step C-7.2 — posts published per day (or week) across the campaign, stacked by phase. One axis,
 * 2px surface gaps between stacked segments, legend top-right, popover tooltip.
 */
export function PublishedByPhaseChart({ series }: { series: PhaseSeries }) {
    const named = series.phases.slice(0, MAX_NAMED);
    const overflow = series.phases
        .slice(MAX_NAMED)
        .map((phase) => String(phase.id));
    const hasUnphased = series.buckets.some(
        (bucket) => (bucket.by_phase['0'] ?? 0) > 0,
    );

    const data = series.buckets.map((bucket) => {
        const [y, m, d] = bucket.date.split('-').map(Number);
        const row: Record<string, number | string> = {
            label: dayLabel.format(new Date(y, m - 1, d)),
        };

        named.forEach((phase) => {
            row[`p${phase.id}`] = bucket.by_phase[String(phase.id)] ?? 0;
        });

        if (overflow.length > 0) {
            row.other = overflow.reduce(
                (sum, id) => sum + (bucket.by_phase[id] ?? 0),
                0,
            );
        }

        if (hasUnphased) {
            row.unphased = bucket.by_phase['0'] ?? 0;
        }

        return row;
    });

    const bars = [
        ...named.map((phase, index) => ({
            key: `p${phase.id}`,
            name: phase.name,
            fill: SERIES_COLOURS[index],
        })),
        ...(overflow.length > 0
            ? [
                  {
                      key: 'other',
                      name: 'Other phases',
                      fill: 'var(--color-chart-4)',
                  },
              ]
            : []),
        ...(hasUnphased || named.length === 0
            ? [
                  {
                      key: 'unphased',
                      name: named.length === 0 ? 'Published' : 'No phase',
                      fill: 'var(--color-muted-foreground)',
                  },
              ]
            : []),
    ];

    return (
        <div
            className="h-64 w-full"
            role="img"
            aria-label={`Posts published per ${series.granularity}, by phase`}
        >
            <ResponsiveContainer width="100%" height="100%">
                <BarChart data={data} margin={chartMargin}>
                    <CartesianGrid {...gridProps} />
                    <XAxis
                        dataKey="label"
                        tick={axisTick}
                        tickLine={false}
                        axisLine={false}
                        interval={tickInterval(
                            data.length,
                            series.granularity === 'day',
                        )}
                        dy={6}
                    />
                    <YAxis
                        tick={axisTick}
                        tickLine={false}
                        axisLine={false}
                        allowDecimals={false}
                        width={28}
                    />
                    <Tooltip
                        contentStyle={chartTooltipStyle}
                        cursor={{ fill: 'var(--color-muted)', opacity: 0.5 }}
                    />
                    {bars.length > 1 && <Legend {...legendProps} />}
                    {bars.map((bar, index) => (
                        <Bar
                            key={bar.key}
                            dataKey={bar.key}
                            name={bar.name}
                            stackId="phases"
                            fill={bar.fill}
                            stroke="var(--color-card)"
                            strokeWidth={2}
                            radius={
                                index === bars.length - 1
                                    ? [4, 4, 0, 0]
                                    : [0, 0, 0, 0]
                            }
                            maxBarSize={28}
                        />
                    ))}
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
}
