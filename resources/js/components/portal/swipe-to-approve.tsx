import {
    CheckCircle2,
    ChevronsRight,
    MessageSquareWarning,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export type SwipeOutcome = 'approve' | 'changes' | null;

/**
 * Step M-6.6 — which decision a release at `offset` (px, right = positive) makes, given the
 * threshold. Pure so the gesture logic is unit-tested without simulating a real drag.
 */
export function decideFromOffset(
    offset: number,
    threshold: number,
): SwipeOutcome {
    if (offset >= threshold) {
        return 'approve';
    }
    if (offset <= -threshold) {
        return 'changes';
    }
    return null;
}

/**
 * Step M-6.6 (I-8.6) — swipe to approve on a phone: drag the thumb right to approve, left to
 * request changes. Additive to the tap buttons below it (keyboard and non-touch users use those).
 */
export function SwipeToApprove({
    onApprove,
    onRequestChanges,
    disabled = false,
}: {
    onApprove: () => void;
    onRequestChanges: () => void;
    disabled?: boolean;
}) {
    const trackRef = useRef<HTMLDivElement>(null);
    const startX = useRef(0);
    const [offset, setOffset] = useState(0);
    const [dragging, setDragging] = useState(false);

    const maxTravel = () => {
        const width = trackRef.current?.clientWidth ?? 280;
        return Math.max(60, width / 2 - 28);
    };

    const threshold = () => maxTravel() * 0.75;

    const onPointerDown = (event: React.PointerEvent) => {
        if (disabled) {
            return;
        }
        startX.current = event.clientX;
        setDragging(true);
        (event.target as HTMLElement).setPointerCapture?.(event.pointerId);
    };

    const onPointerMove = (event: React.PointerEvent) => {
        if (!dragging) {
            return;
        }
        const travel = maxTravel();
        setOffset(
            Math.max(-travel, Math.min(travel, event.clientX - startX.current)),
        );
    };

    const onPointerUp = () => {
        if (!dragging) {
            return;
        }
        setDragging(false);
        const outcome = decideFromOffset(offset, threshold());
        setOffset(0);
        if (outcome === 'approve') {
            onApprove();
        } else if (outcome === 'changes') {
            onRequestChanges();
        }
    };

    const progress = offset / (maxTravel() || 1);

    return (
        <div
            ref={trackRef}
            className="bg-muted/70 relative flex h-14 items-center justify-center overflow-hidden rounded-full select-none"
            aria-hidden={false}
        >
            <span
                className={cn(
                    'pointer-events-none absolute inset-0 rounded-full transition-colors',
                    progress > 0.1 && 'bg-success/20',
                    progress < -0.1 && 'bg-destructive/15',
                )}
            />
            <span className="text-muted-foreground pointer-events-none z-10 flex items-center gap-1.5 text-sm font-medium">
                <MessageSquareWarning className="size-4 opacity-60" />
                Swipe to decide
                <ChevronsRight className="size-4 opacity-60" />
            </span>
            <button
                type="button"
                disabled={disabled}
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={onPointerUp}
                onPointerCancel={onPointerUp}
                style={{ transform: `translateX(${offset}px)` }}
                aria-label="Swipe right to approve, left to request changes"
                className={cn(
                    'absolute z-20 flex size-12 touch-none items-center justify-center rounded-full text-white shadow-md',
                    dragging ? '' : 'transition-transform',
                    progress >= 0 ? 'bg-success' : 'bg-destructive',
                )}
            >
                {progress >= 0 ? (
                    <CheckCircle2 className="size-5" />
                ) : (
                    <MessageSquareWarning className="size-5" />
                )}
            </button>
        </div>
    );
}
