import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatCurrency, formatCurrencyCompact } from '@/lib/currency';
import { cn } from '@/lib/utils';
import {
    activeDot,
    axisTick,
    chartMargin,
    chartTooltipStyle,
    gridProps,
    legendProps,
} from './chart-theme';
import { tickInterval } from './format';

/**
 * The analytics series charts, shared by the platform console and the studio's analytics
 * (brand guidelines §3.5): status-meaning series use `success`/`destructive` with a dashed failure
 * line; two-series comparisons use `chart-1`/`chart-2`; one light area fill at most; never two
 * y-axes. Each takes the series as-is and a `daily` flag for tick density.
 */

type Bucket = { label: string } & Record<string, number | string | null>;

function Frame({
    children,
    className,
    label,
}: {
    children: React.ReactElement;
    className?: string;
    label: string;
}) {
    return (
        <div
            className={cn('h-64 w-full', className)}
            role="img"
            aria-label={label}
        >
            <ResponsiveContainer width="100%" height="100%">
                {children}
            </ResponsiveContainer>
        </div>
    );
}

function Axes({
    length,
    daily,
    yWidth = 36,
    yFormatter,
    allowDecimals = false,
}: {
    length: number;
    daily: boolean;
    yWidth?: number;
    yFormatter?: (value: number) => string;
    allowDecimals?: boolean;
}) {
    return (
        <>
            <CartesianGrid {...gridProps} />
            <XAxis
                dataKey="label"
                tick={axisTick}
                tickLine={false}
                axisLine={false}
                interval={tickInterval(length, daily)}
                dy={6}
            />
            <YAxis
                tick={axisTick}
                tickLine={false}
                axisLine={false}
                allowDecimals={allowDecimals}
                width={yWidth}
                tickFormatter={yFormatter}
            />
        </>
    );
}

/** Published vs failed per bucket. */
export function PublishingChart({
    series,
    daily,
    className,
}: {
    series: Bucket[];
    daily: boolean;
    className?: string;
}) {
    return (
        <Frame
            className={className}
            label="Published and failed posts over time"
        >
            <LineChart data={series} margin={chartMargin}>
                {Axes({ length: series.length, daily })}
                <Tooltip
                    contentStyle={chartTooltipStyle}
                    cursor={{
                        stroke: 'var(--color-foreground)',
                        strokeOpacity: 0.2,
                        strokeWidth: 1,
                    }}
                />
                <Legend {...legendProps} />
                <Line
                    type="monotone"
                    dataKey="published"
                    name="Published"
                    stroke="var(--color-success)"
                    strokeWidth={2}
                    dot={false}
                    activeDot={activeDot}
                />
                <Line
                    type="monotone"
                    dataKey="failed"
                    name="Failed"
                    stroke="var(--color-destructive)"
                    strokeWidth={2}
                    strokeDasharray="5 4"
                    dot={false}
                    activeDot={activeDot}
                />
            </LineChart>
        </Frame>
    );
}

/** Step M-3.1 — one running total per bucket as a line (followers); gaps stay gaps. */
export function TotalLineChart({
    series,
    daily,
    dataKey,
    name,
    className,
}: {
    series: Bucket[];
    daily: boolean;
    dataKey: string;
    name: string;
    className?: string;
}) {
    return (
        <Frame className={className} label={`${name} over time`}>
            <LineChart data={series} margin={chartMargin}>
                {Axes({
                    length: series.length,
                    daily,
                    yWidth: 44,
                    yFormatter: (value) =>
                        new Intl.NumberFormat('en-GB', {
                            notation: 'compact',
                        }).format(value),
                })}
                <Tooltip
                    contentStyle={chartTooltipStyle}
                    cursor={{
                        stroke: 'var(--color-foreground)',
                        strokeOpacity: 0.2,
                        strokeWidth: 1,
                    }}
                />
                <Line
                    type="monotone"
                    dataKey={dataKey}
                    name={name}
                    stroke="var(--color-chart-2)"
                    strokeWidth={2}
                    dot={false}
                    activeDot={activeDot}
                    connectNulls={false}
                />
            </LineChart>
        </Frame>
    );
}

/** One count per bucket as bars (e.g. new organizations). */
export function CountBarChart({
    series,
    daily,
    dataKey,
    name,
    className,
}: {
    series: Bucket[];
    daily: boolean;
    dataKey: string;
    name: string;
    className?: string;
}) {
    return (
        <Frame className={className} label={`${name} over time`}>
            <BarChart data={series} margin={chartMargin}>
                {Axes({ length: series.length, daily })}
                <Tooltip
                    contentStyle={chartTooltipStyle}
                    cursor={{ fill: 'var(--color-muted)', opacity: 0.5 }}
                />
                <Bar
                    dataKey={dataKey}
                    name={name}
                    fill="var(--color-chart-1)"
                    radius={[4, 4, 0, 0]}
                    maxBarSize={28}
                />
            </BarChart>
        </Frame>
    );
}

/** Two counts side by side per bucket (chart-1 vs chart-2), e.g. sent for approval vs approved. */
export function PairedBarChart({
    series,
    daily,
    first,
    second,
    className,
}: {
    series: Bucket[];
    daily: boolean;
    first: { key: string; name: string };
    second: { key: string; name: string };
    className?: string;
}) {
    return (
        <Frame
            className={className}
            label={`${first.name} and ${second.name} over time`}
        >
            <BarChart data={series} margin={chartMargin} barGap={2}>
                {Axes({ length: series.length, daily })}
                <Tooltip
                    contentStyle={chartTooltipStyle}
                    cursor={{ fill: 'var(--color-muted)', opacity: 0.5 }}
                />
                <Legend {...legendProps} />
                <Bar
                    dataKey={first.key}
                    name={first.name}
                    fill="var(--color-chart-1)"
                    radius={[3, 3, 0, 0]}
                    maxBarSize={14}
                />
                <Bar
                    dataKey={second.key}
                    name={second.name}
                    fill="var(--color-chart-2)"
                    radius={[3, 3, 0, 0]}
                    maxBarSize={14}
                />
            </BarChart>
        </Frame>
    );
}

/** An amount per bucket in one currency, as a light area. */
export function AmountAreaChart({
    series,
    daily,
    dataKey,
    name,
    currency,
    gradientId,
    className,
}: {
    series: Bucket[];
    daily: boolean;
    dataKey: string;
    name: string;
    currency: string;
    /** Unique per page — two charts sharing an id would share a gradient. */
    gradientId: string;
    className?: string;
}) {
    return (
        <Frame
            className={cn('h-60', className)}
            label={`${name} in ${currency} over time`}
        >
            <AreaChart data={series} margin={chartMargin}>
                <defs>
                    <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                        <stop
                            offset="0%"
                            stopColor="var(--color-chart-1)"
                            stopOpacity={0.22}
                        />
                        <stop
                            offset="100%"
                            stopColor="var(--color-chart-1)"
                            stopOpacity={0}
                        />
                    </linearGradient>
                </defs>
                {Axes({
                    length: series.length,
                    daily,
                    yWidth: 56,
                    allowDecimals: true,
                    yFormatter: (amount: number) =>
                        formatCurrencyCompact(amount, currency),
                })}
                <Tooltip
                    contentStyle={chartTooltipStyle}
                    formatter={(amount) =>
                        formatCurrency(Number(amount), currency)
                    }
                />
                <Area
                    type="monotone"
                    dataKey={dataKey}
                    name={name}
                    stroke="var(--color-chart-1)"
                    strokeWidth={2}
                    fill={`url(#${gradientId})`}
                    activeDot={activeDot}
                />
            </AreaChart>
        </Frame>
    );
}
