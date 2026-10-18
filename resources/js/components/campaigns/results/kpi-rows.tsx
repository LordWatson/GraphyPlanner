import { Target } from 'lucide-react';
import { StatusPill } from '@/components/status-pill';
import { cn } from '@/lib/utils';

export type KpiResult = {
    metric: string;
    label: string;
    unit: 'count' | 'percent' | 'hours';
    lower_is_better: boolean;
    target: number;
    actual: number | null;
    available: boolean;
    note: string | null;
    detail?: string | null;
    progress: number | null;
    met: boolean | null;
};

const number = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 1 });

export function formatKpi(
    unit: KpiResult['unit'],
    value: number | null,
): string {
    if (value === null) {
        return '—';
    }

    if (unit === 'percent') {
        return `${number.format(value)}%`;
    }

    if (unit === 'hours') {
        return value >= 48
            ? `${number.format(value / 24)} days`
            : `${number.format(value)} h`;
    }

    return number.format(value);
}

/**
 * Step C-7.1/C-7.2 — each KPI as target vs actual: the track is the target, the fill the actual
 * (capped at the target), with a labelled status. Unavailable metrics say so in words and draw no
 * bar — never a zero that looks like a result.
 */
export function KpiRows({ kpis }: { kpis: KpiResult[] }) {
    return (
        <ul className="flex flex-col gap-5">
            {kpis.map((kpi) => {
                const progress = Math.min(1, Math.max(0, kpi.progress ?? 0));
                const status = !kpi.available
                    ? { value: 'undated', label: 'Not tracked yet' }
                    : kpi.met
                      ? { value: 'live', label: 'Hit' }
                      : kpi.actual === null
                        ? { value: 'undated', label: 'No data yet' }
                        : { value: 'upcoming', label: 'Not there yet' };

                return (
                    <li key={kpi.metric} className="flex flex-col gap-2">
                        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <span className="flex items-center gap-2 text-sm font-medium">
                                <Target className="text-muted-foreground size-4" />
                                {kpi.label}
                            </span>
                            <span className="flex items-center gap-3">
                                <span className="font-mono text-[13px] tabular-nums">
                                    <span className="font-semibold">
                                        {kpi.available
                                            ? formatKpi(kpi.unit, kpi.actual)
                                            : '—'}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        / {kpi.lower_is_better ? 'under ' : ''}
                                        {formatKpi(kpi.unit, kpi.target)}
                                    </span>
                                </span>
                                <StatusPill
                                    kind="timeline"
                                    value={status.value}
                                    label={status.label}
                                />
                            </span>
                        </div>
                        {kpi.available ? (
                            <div
                                role="meter"
                                aria-label={kpi.label}
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-valuenow={Math.round(progress * 100)}
                                className="bg-muted h-2 overflow-hidden rounded-full"
                            >
                                <div
                                    className={cn(
                                        'h-full rounded-full transition-[width] duration-700',
                                        kpi.met ? 'bg-success' : 'bg-chart-1',
                                    )}
                                    style={{
                                        width: `${Math.max(progress * 100, kpi.actual ? 2 : 0)}%`,
                                    }}
                                />
                            </div>
                        ) : (
                            <p className="text-muted-foreground text-xs">
                                {kpi.note}
                            </p>
                        )}
                        {kpi.available && kpi.detail && (
                            <p className="text-muted-foreground text-xs">
                                {kpi.detail}
                            </p>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
