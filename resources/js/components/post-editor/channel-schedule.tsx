import { Clock4, CopyCheck, Sparkle } from 'lucide-react';
import { DateTimePicker } from '@/components/date-time-picker';
import { PlatformIcon } from '@/components/platform-icon';
import type {
    ScheduleRows,
    TargetAccountData,
} from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { nextWeeklySlot, todayIn } from '@/lib/timezones';
import { cn } from '@/lib/utils';

function Usage({
    used,
    limit,
    unit,
}: {
    used: number;
    limit: number;
    unit: string;
}) {
    const over = used > limit;

    return (
        <span
            className={cn(
                'font-mono tabular-nums',
                over ? 'text-destructive' : 'text-muted-foreground',
            )}
        >
            {used.toLocaleString()}/{limit.toLocaleString()} {unit}
        </span>
    );
}

/**
 * Step M-3.2 — the account's best slots from its own engagement. A click fills the row with the
 * next such slot on the account's clock; the time stays fully editable. While the account has
 * too few measured posts, it says so instead of guessing.
 */
function BestTimes({
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

    if (best.status === 'learning' || best.slots.length === 0) {
        return (
            <span className="text-muted-foreground text-[11px]">
                Best times appear after {best.needed} measured posts (
                {best.sample} so far).
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

/**
 * One row per client social account: tick it to publish there, then give it its own local date
 * and time. A channel with required settings missing (e.g. a Pinterest board) says so. Times are
 * never shared implicitly (spec §4.6). Two explicit, per-click shortcuts: "Same local time" copies
 * one row's date and wall-clock time to the others; "Same moment" (only when the channels are on
 * different clocks) gives each the local time of that one instant. Each row is still stored
 * independently.
 */
export function ChannelSchedule({
    accounts,
    rows,
    onToggle,
    onChange,
    onApplyToAll,
    onSameInstant,
    usage,
    error,
}: {
    accounts: TargetAccountData[];
    rows: ScheduleRows;
    onToggle: (accountId: number) => void;
    onChange: (
        accountId: number,
        field: 'date' | 'time',
        value: string,
    ) => void;
    onApplyToAll: (accountId: number) => void;
    /** Spec §4.6's explicit "same instant" opt-in: converts this row's moment into every other channel's own timezone. */
    onSameInstant: (accountId: number) => void;
    /** Step M-2.2: the caption length (as it publishes there) and hashtag count per channel. */
    usage: Record<number, { chars: number; tags: number }>;
    error?: string;
}) {
    const selectedIds = Object.keys(rows).map(Number);
    const mixedZones =
        new Set(
            accounts
                .filter((account) => selectedIds.includes(account.id))
                .map((account) => account.timezone),
        ).size > 1;

    if (accounts.length === 0) {
        return (
            <p className="text-muted-foreground border-border rounded-xl border border-dashed px-4 py-6 text-center text-sm">
                This client has no social accounts yet. Connect one from the
                client page to start scheduling.
            </p>
        );
    }

    return (
        <div className="grid gap-2">
            <ul className="border-border divide-border/70 divide-y overflow-hidden rounded-xl border">
                {accounts.map((account) => {
                    const row = rows[account.id];
                    const selected = row !== undefined;
                    const canApplyToAll =
                        selected &&
                        selectedIds.length > 1 &&
                        row.date !== '' &&
                        row.time !== '';

                    return (
                        <li
                            key={account.id}
                            className={cn(
                                'flex flex-col gap-3 px-4 py-3 transition-colors sm:flex-row sm:items-center',
                                selected ? 'bg-card' : 'bg-muted/30',
                            )}
                        >
                            <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-3">
                                <Checkbox
                                    checked={selected}
                                    onCheckedChange={() => onToggle(account.id)}
                                    aria-label={`Publish to ${account.handle}`}
                                />
                                <PlatformIcon
                                    platform={account.platform}
                                    className={cn(
                                        'size-8',
                                        !selected && 'opacity-60',
                                    )}
                                />
                                <span className="flex min-w-0 flex-col">
                                    <span
                                        className={cn(
                                            'truncate text-sm font-medium',
                                            !selected &&
                                                'text-muted-foreground',
                                        )}
                                    >
                                        {account.handle}
                                    </span>
                                    <span className="text-muted-foreground truncate text-xs">
                                        {account.platform_label} ·{' '}
                                        {account.timezone}
                                    </span>
                                    {(account.missing_settings?.length ?? 0) >
                                        0 && (
                                        <span className="text-warning text-xs">
                                            Needs its settings finished before
                                            it can publish.
                                        </span>
                                    )}
                                </span>
                            </label>

                            {selected && (
                                <div className="flex flex-col gap-1.5 sm:items-end">
                                    <DateTimePicker
                                        size="sm"
                                        label={account.handle}
                                        timeZone={account.timezone}
                                        min={todayIn(account.timezone)}
                                        value={row}
                                        onChange={(next) => {
                                            if (next.date !== row.date) {
                                                onChange(
                                                    account.id,
                                                    'date',
                                                    next.date,
                                                );
                                            }

                                            if (next.time !== row.time) {
                                                onChange(
                                                    account.id,
                                                    'time',
                                                    next.time,
                                                );
                                            }
                                        }}
                                        className="sm:items-end"
                                    />
                                    <BestTimes
                                        account={account}
                                        onPick={(slot) => {
                                            onChange(
                                                account.id,
                                                'date',
                                                slot.date,
                                            );
                                            onChange(
                                                account.id,
                                                'time',
                                                slot.time,
                                            );
                                        }}
                                    />
                                    <div className="flex items-center gap-3 text-[11px]">
                                        {canApplyToAll && (
                                            <Button
                                                type="button"
                                                variant="link"
                                                className="h-auto text-[11px]"
                                                title="Copy this date and local time to every channel — each keeps its own clock."
                                                onClick={() =>
                                                    onApplyToAll(account.id)
                                                }
                                            >
                                                <CopyCheck className="size-3" />
                                                Same local time
                                            </Button>
                                        )}
                                        {canApplyToAll && mixedZones && (
                                            <Button
                                                type="button"
                                                variant="link"
                                                className="h-auto text-[11px]"
                                                title="Go out at this exact moment everywhere: other channels get the matching time on their own clock."
                                                onClick={() =>
                                                    onSameInstant(account.id)
                                                }
                                            >
                                                <Clock4 className="size-3" />
                                                Same moment
                                            </Button>
                                        )}
                                        <Usage
                                            used={usage[account.id]?.chars ?? 0}
                                            limit={account.caption_limit}
                                            unit="chars"
                                        />
                                        <Usage
                                            used={usage[account.id]?.tags ?? 0}
                                            limit={account.max_hashtags}
                                            unit="tags"
                                        />
                                    </div>
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>

            {/* The target fields are rendered once, with the overrides, by `TargetFields`. */}

            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}
