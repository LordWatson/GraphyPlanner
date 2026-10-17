import type { ReactNode } from 'react';
import type { CurrencyTotal } from '@/lib/currency';
import { formatCurrencyTotals } from '@/lib/currency';
import { cn } from '@/lib/utils';
import { integer } from './format';

/**
 * Small, flat figure layouts shared by every analytics surface: per-currency money rows (never
 * summed), label/value lists, and proportional bars. Data screen — hairlines only, no graphics.
 */

export function MoneyRow({
    label,
    totals,
    currency,
    hint,
}: {
    label: string;
    totals: CurrencyTotal[];
    currency: string;
    hint?: string;
}) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-3 first:pt-0">
            <dt className="flex flex-col">
                <span className="eyebrow text-muted-foreground">{label}</span>
                {hint && (
                    <span className="text-muted-foreground text-xs">
                        {hint}
                    </span>
                )}
            </dt>
            <dd className="font-display text-right text-lg font-bold tabular-nums">
                {formatCurrencyTotals(totals, currency)}
            </dd>
        </div>
    );
}

export function FigureList({
    rows,
    className,
}: {
    rows: [ReactNode, ReactNode][];
    className?: string;
}) {
    return (
        <dl
            className={cn('divide-border/70 flex flex-col divide-y', className)}
        >
            {rows.map(([label, value], index) => (
                <div
                    key={index}
                    className="flex items-baseline justify-between gap-4 py-2.5 first:pt-0 last:pb-0"
                >
                    <dt className="text-muted-foreground text-sm">{label}</dt>
                    <dd className="font-mono text-sm tabular-nums">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

/**
 * Labelled horizontal bars, each a share of `total` (or of the largest value). `barClassName`
 * colours the fill — a chart token, or a status tone class when the bars carry meaning.
 */
export function ShareBars({
    rows,
    total,
    emptyLabel = 'Nothing yet.',
}: {
    rows: {
        key: string;
        label: ReactNode;
        value: number;
        barClassName?: string;
        hint?: ReactNode;
    }[];
    total?: number;
    emptyLabel?: string;
}) {
    const max = total ?? Math.max(0, ...rows.map((row) => row.value));

    if (rows.length === 0) {
        return <p className="text-muted-foreground text-sm">{emptyLabel}</p>;
    }

    return (
        <ul className="flex flex-col gap-3">
            {rows.map((row) => {
                const share = max > 0 ? (row.value / max) * 100 : 0;

                return (
                    <li key={row.key} className="flex flex-col gap-1.5">
                        <div className="flex items-baseline justify-between gap-3 text-sm">
                            <span className="min-w-0 truncate">
                                {row.label}
                            </span>
                            <span className="text-muted-foreground shrink-0 font-mono text-xs tabular-nums">
                                {row.hint ?? integer.format(row.value)}
                            </span>
                        </div>
                        <div
                            className="bg-muted h-1.5 overflow-hidden rounded-full"
                            role="presentation"
                        >
                            <div
                                className={cn(
                                    'h-full rounded-full transition-[width] duration-500',
                                    row.barClassName ?? 'bg-chart-1',
                                )}
                                style={{ width: `${share}%` }}
                            />
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
