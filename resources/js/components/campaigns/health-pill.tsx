import type {
    CampaignHealth,
    CampaignReadiness,
} from '@/components/campaigns/types';
import { StatusPill } from '@/components/status-pill';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * Step C-0.4 / C-1.1 — a campaign's derived health as a status pill that always carries its
 * reasons: the headline reason as the accessible label, every finding in the tooltip.
 */
export function HealthPill({
    health,
    className,
}: {
    health: CampaignHealth;
    className?: string;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    tabIndex={0}
                    className="focus-visible:ring-ring/50 rounded-full outline-none focus-visible:ring-[3px]"
                    aria-label={`Health ${health.label}: ${health.reason}`}
                >
                    <StatusPill
                        kind="health"
                        value={health.status}
                        label={health.label}
                        className={className}
                    />
                </span>
            </TooltipTrigger>
            <TooltipContent className="max-w-72">
                {health.findings.length === 0 ? (
                    <p>{health.reason}</p>
                ) : (
                    <ul className="flex flex-col gap-1">
                        {health.findings.map((finding) => (
                            <li key={finding.code}>{finding.reason}</li>
                        ))}
                    </ul>
                )}
            </TooltipContent>
        </Tooltip>
    );
}

/**
 * Step C-4.5 — readiness as a thin meter with its label ("72% ready" / "Not planned"). The track
 * is neutral; the fill is the brand teal — readiness is progress, not a status.
 */
export function ReadinessMeter({
    readiness,
    className,
    showLabel = true,
}: {
    readiness: CampaignReadiness;
    className?: string;
    showLabel?: boolean;
}) {
    const percent = readiness.percent;

    return (
        <div className={cn('flex min-w-0 items-center gap-2.5', className)}>
            <div
                role="meter"
                aria-label="Readiness"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent ?? undefined}
                aria-valuetext={readiness.label}
                className="bg-muted h-1.5 w-full min-w-12 overflow-hidden rounded-full"
            >
                {percent !== null && (
                    <div
                        className="bg-primary h-full rounded-full transition-[width] duration-500"
                        style={{ width: `${Math.max(percent, 2)}%` }}
                    />
                )}
            </div>
            {showLabel && (
                <span className="text-muted-foreground shrink-0 font-mono text-[11px] tabular-nums">
                    {percent === null ? 'Not planned' : `${percent}%`}
                </span>
            )}
        </div>
    );
}
