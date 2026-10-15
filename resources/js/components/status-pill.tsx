import { Badge } from '@/components/ui/badge';
import type { StatusKind } from '@/lib/status';
import { resolveStatus, toneBadgeVariant, toneBgClass } from '@/lib/status';
import { cn } from '@/lib/utils';

/**
 * Colour **and** label, always (guidelines §1.5): a soft tinted pill with a solid tone dot and the
 * status text. Pass `label` when the controller already sends a human label.
 */
export function StatusPill({
    kind,
    value,
    label,
    className,
    title,
}: {
    kind: StatusKind;
    value: string;
    label?: string | null;
    className?: string;
    title?: string;
}) {
    const status = resolveStatus(kind, value);

    return (
        <Badge
            variant={toneBadgeVariant[status.tone]}
            className={className}
            title={title}
        >
            <span
                aria-hidden
                className={cn(
                    'size-1.5 shrink-0 rounded-full',
                    toneBgClass[status.tone],
                    value === 'publishing' && 'animate-pulse',
                )}
            />
            {label ?? status.label}
        </Badge>
    );
}
