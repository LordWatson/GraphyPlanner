import { ChevronDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatDate } from '@/lib/dates';
import { resolveStatus, toneBgClass } from '@/lib/status';
import { cn } from '@/lib/utils';

/** Step M-3.4 — one weighted health reason (`ClientHealthService`), already filtered per viewer. */
export type HealthReason = {
    key: string;
    tone: 'red' | 'amber' | 'green';
    weight: number;
    label: string;
};

export type HealthTrendPoint = { status: string; at: string };

/**
 * The full, weight-ordered list behind a client's headline health reason, plus its last 90 days
 * as a strip of coloured spans (each with a label for assistive tech). Advisory (green) reasons
 * are listed under "Also worth a look" — they never change the health on their own.
 */
export function HealthReasons({
    reasons,
    trend = [],
    className,
}: {
    reasons: HealthReason[];
    trend?: HealthTrendPoint[];
    className?: string;
}) {
    const issues = reasons.filter((reason) => reason.tone !== 'green');
    const advisory = reasons.filter((reason) => reason.tone === 'green');

    if (reasons.length <= 1 && trend.length <= 1) {
        return null;
    }

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    variant="link"
                    size="sm"
                    className={cn('h-auto px-0 text-[13px]', className)}
                >
                    {issues.length > 1
                        ? `+${issues.length - 1} more`
                        : 'Details'}
                    <ChevronDown className="size-3.5" aria-hidden />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-80">
                <div className="flex flex-col gap-4">
                    {issues.length > 0 && (
                        <ReasonList
                            title="What’s affecting health"
                            reasons={issues}
                        />
                    )}
                    {advisory.length > 0 && (
                        <ReasonList
                            title="Also worth a look"
                            reasons={advisory}
                        />
                    )}
                    {trend.length > 0 && <HealthTrend points={trend} />}
                </div>
            </PopoverContent>
        </Popover>
    );
}

function ReasonList({
    title,
    reasons,
}: {
    title: string;
    reasons: HealthReason[];
}) {
    return (
        <div className="flex flex-col gap-2">
            <span className="eyebrow text-muted-foreground">{title}</span>
            <ul className="flex flex-col gap-2">
                {reasons.map((reason) => {
                    const status = resolveStatus('health', reason.tone);

                    return (
                        <li key={reason.key} className="flex gap-2 text-sm">
                            <span
                                aria-hidden
                                className={cn(
                                    'mt-1.5 size-1.5 shrink-0 rounded-full',
                                    toneBgClass[status.tone],
                                )}
                            />
                            <span>
                                <span className="sr-only">
                                    {status.label}:{' '}
                                </span>
                                {reason.label}
                            </span>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

/** The last 90 days of stored health as proportional coloured spans, oldest first. */
function HealthTrend({ points }: { points: HealthTrendPoint[] }) {
    const now = Date.now();
    const start = new Date(points[0].at).getTime();
    const span = Math.max(1, now - start);
    const segments = points.map((point, index) => {
        const from = new Date(point.at).getTime();
        const to =
            index + 1 < points.length
                ? new Date(points[index + 1].at).getTime()
                : now;

        return {
            ...point,
            share: Math.max(0.02, (to - from) / span),
            status: resolveStatus('health', point.status),
        };
    });

    return (
        <div className="flex flex-col gap-2">
            <span className="eyebrow text-muted-foreground">Last 90 days</span>
            <div
                className="flex h-2 w-full overflow-hidden rounded-full"
                role="img"
                aria-label={segments
                    .map(
                        (segment) =>
                            `${segment.status.label} from ${formatDate(segment.at)}`,
                    )
                    .join(', ')}
            >
                {segments.map((segment, index) => (
                    <span
                        key={`${segment.at}-${index}`}
                        className={toneBgClass[segment.status.tone]}
                        style={{ flexGrow: segment.share }}
                    />
                ))}
            </div>
            <span className="text-muted-foreground flex justify-between font-mono text-[11px]">
                <span>{formatDate(points[0].at)}</span>
                <span>Today</span>
            </span>
        </div>
    );
}
