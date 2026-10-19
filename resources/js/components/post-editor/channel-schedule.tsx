import { CopyCheck } from 'lucide-react';
import { PlatformIcon } from '@/components/platform-icon';
import type {
    ScheduleRows,
    TargetAccountData,
} from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
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
 * and time. A channel with required settings missing (e.g. a Pinterest board) says so. Times are never shared implicitly (spec §4.6) — "Use for all" is an explicit, per-click
 * copy of one row's values into the other selected rows, each still stored independently.
 */
export function ChannelSchedule({
    accounts,
    rows,
    onToggle,
    onChange,
    onApplyToAll,
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
    /** Step M-2.2: the caption length (as it publishes there) and hashtag count per channel. */
    usage: Record<number, { chars: number; tags: number }>;
    error?: string;
}) {
    const selectedIds = Object.keys(rows).map(Number);

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
                                    <div className="flex items-center gap-2">
                                        <Input
                                            type="date"
                                            aria-label={`Local date for ${account.handle}`}
                                            value={row.date}
                                            onChange={(e) =>
                                                onChange(
                                                    account.id,
                                                    'date',
                                                    e.target.value,
                                                )
                                            }
                                            className="h-8 w-[9.5rem] font-mono text-xs"
                                        />
                                        <Input
                                            type="time"
                                            aria-label={`Local time for ${account.handle}`}
                                            value={row.time}
                                            onChange={(e) =>
                                                onChange(
                                                    account.id,
                                                    'time',
                                                    e.target.value,
                                                )
                                            }
                                            className="h-8 w-[6.5rem] font-mono text-xs"
                                        />
                                    </div>
                                    <div className="flex items-center gap-3 text-[11px]">
                                        {canApplyToAll && (
                                            <Button
                                                type="button"
                                                variant="link"
                                                className="h-auto text-[11px]"
                                                onClick={() =>
                                                    onApplyToAll(account.id)
                                                }
                                            >
                                                <CopyCheck className="size-3" />
                                                Use for all channels
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
