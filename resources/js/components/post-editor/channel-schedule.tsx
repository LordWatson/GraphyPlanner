import { Clock4, CopyCheck } from 'lucide-react';
import { DateTimePicker } from '@/components/date-time-picker';
import { PlatformIcon } from '@/components/platform-icon';
import { BestTimes } from '@/components/post-editor/best-times';
import type {
    ScheduleRows,
    TargetAccountData,
} from '@/components/post-editor/types';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { todayIn } from '@/lib/timezones';
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
    byHand = [],
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
    /** Step S-5.1: channels that are a Story with extras, so posted by hand at their time. */
    byHand?: number[];
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
                                'grid gap-3 px-4 py-3.5 transition-colors',
                                selected ? 'bg-card' : 'bg-muted/30',
                            )}
                        >
                            <label className="flex min-w-0 cursor-pointer items-center gap-3">
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
                                    {selected &&
                                        byHand.includes(account.id) && (
                                            <StatusPill
                                                kind="storyHandoff"
                                                value="needs_hand_posting"
                                                className="mt-1"
                                                title="A story with a link or stickers is posted by hand from the Instagram app. We’ll remind whoever is posting when it’s due."
                                            />
                                        )}
                                    {(account.missing_settings?.length ?? 0) >
                                        0 && (
                                        <span className="text-warning text-xs">
                                            Needs its settings finished before
                                            it can publish.
                                        </span>
                                    )}
                                </span>
                            </label>

                            {/* Everything about "when" sits under the handle on one left edge
                                (indented past the checkbox + icon from `sm`). */}
                            {selected && (
                                <div className="grid gap-2 sm:ps-[4.625rem]">
                                    <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                                        <DateTimePicker
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
                                            className="w-full sm:w-auto"
                                        />
                                        <span className="flex items-center gap-3 text-[11px] sm:h-10">
                                            <Usage
                                                used={
                                                    usage[account.id]?.chars ??
                                                    0
                                                }
                                                limit={account.caption_limit}
                                                unit="chars"
                                            />
                                            <Usage
                                                used={
                                                    usage[account.id]?.tags ?? 0
                                                }
                                                limit={account.max_hashtags}
                                                unit="tags"
                                            />
                                        </span>
                                    </div>
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
                                    {canApplyToAll && (
                                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                                            <Button
                                                type="button"
                                                variant="link"
                                                className="h-auto py-1 text-xs has-[>svg]:px-0"
                                                title="Copy this date and local time to every channel — each keeps its own clock."
                                                onClick={() =>
                                                    onApplyToAll(account.id)
                                                }
                                            >
                                                <CopyCheck className="size-3.5" />
                                                Same local time
                                            </Button>
                                            {mixedZones && (
                                                <Button
                                                    type="button"
                                                    variant="link"
                                                    className="h-auto py-1 text-xs has-[>svg]:px-0"
                                                    title="Go out at this exact moment everywhere: other channels get the matching time on their own clock."
                                                    onClick={() =>
                                                        onSameInstant(
                                                            account.id,
                                                        )
                                                    }
                                                >
                                                    <Clock4 className="size-3.5" />
                                                    Same moment
                                                </Button>
                                            )}
                                        </div>
                                    )}
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
