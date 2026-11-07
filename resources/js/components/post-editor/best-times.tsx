import { Sparkle } from 'lucide-react';
import type { TargetAccountData } from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';
import { nextWeeklySlot } from '@/lib/timezones';

/**
 * Step M-3.2 — the account's best slots from its own engagement. A click fills the row with the
 * next such slot on the account's clock; the time stays fully editable. While the account has
 * too few measured posts, it says so instead of guessing.
 */
export function BestTimes({
    account,
    onPick,
}: {
    account: TargetAccountData;
    onPick: (slot: { date: string; time: string }) => void;
}) {
    const best = account.best_time;

    if (!best) {
        return null;
    }

    if (best.status === 'learning') {
        return (
            <span className="text-muted-foreground text-[11px]">
                Best times appear after {best.needed} measured posts (
                {best.sample} so far).
            </span>
        );
    }

    if (best.slots.length === 0) {
        return (
            <span className="text-muted-foreground text-[11px]">
                {best.sample} measured posts, but none at the same time of day —
                no slot to compare yet.
            </span>
        );
    }

    return (
        <span className="flex flex-wrap items-center gap-1.5 text-[11px]">
            <span className="text-muted-foreground inline-flex items-center gap-1">
                <Sparkle className="size-3" aria-hidden />
                Best times
            </span>
            {best.slots.map((slot) => (
                <Button
                    key={`${slot.weekday}-${slot.hour}`}
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-6 rounded-full px-2 font-mono text-[11px]"
                    title={`${slot.posts} posts here averaged ${
                        best.ranked_by === 'rate'
                            ? `a ${slot.score}% engagement rate`
                            : `${slot.score} interactions`
                    }. Click to schedule the next one (${account.timezone}).`}
                    onClick={() =>
                        onPick(
                            nextWeeklySlot(
                                slot.weekday,
                                slot.hour,
                                account.timezone,
                            ),
                        )
                    }
                >
                    {slot.label}
                </Button>
            ))}
        </span>
    );
}
