import { Globe2 } from 'lucide-react';
import { DatePicker } from '@/components/date-picker';
import { TimeField } from '@/components/time-field';
import {
    cityOf,
    formatInstant,
    viewerTimeZone,
    zonedToInstant,
} from '@/lib/timezones';
import type { LocalSlot } from '@/lib/timezones';
import { cn } from '@/lib/utils';

/**
 * Step I-4.6 / M-1.6 — the shared "when" control: a date (calendar popover) and a local time,
 * labelled with the timezone they're in. When `timeZone` differs from the viewer's own, it adds
 * what that moment is for the viewer ("Your time: Tue 20 Oct, 13:00, Dubai"), so a strategist in
 * Dubai scheduling a London account can see both clocks. Values stay a local `YYYY-MM-DD` +
 * `HH:MM` pair — never collapsed to UTC (spec §4.6).
 */
export function DateTimePicker({
    value,
    onChange,
    timeZone,
    label,
    size = 'default',
    min,
    className,
}: {
    value: LocalSlot;
    onChange: (next: LocalSlot) => void;
    /** The timezone the date/time are in (the account's). Omit for a plain local pair. */
    timeZone?: string;
    /** Names the field for screen readers, e.g. "@studio on Instagram". */
    label: string;
    size?: 'sm' | 'default';
    min?: string;
    className?: string;
}) {
    const viewer = viewerTimeZone();
    const instant = timeZone ? zonedToInstant(value, timeZone) : null;
    const showViewer = timeZone !== undefined && timeZone !== viewer;

    return (
        <div className={cn('flex flex-col gap-1', className)}>
            <div className="flex items-center gap-2">
                <DatePicker
                    aria-label={`Date for ${label}`}
                    value={value.date || null}
                    min={min}
                    onChange={(date) =>
                        onChange({ ...value, date: date ?? '' })
                    }
                    className={cn(
                        'font-mono text-xs',
                        size === 'sm' ? 'h-8 w-[9.5rem]' : 'w-40',
                    )}
                />
                <TimeField
                    aria-label={`Time for ${label}`}
                    value={value.time}
                    onChange={(time) => onChange({ ...value, time })}
                    className={size === 'sm' ? 'h-8 w-[6.5rem]' : 'w-28'}
                />
            </div>
            {timeZone && (
                <p className="text-muted-foreground flex flex-wrap items-center gap-x-1.5 font-mono text-[11px]">
                    <Globe2 className="size-3" aria-hidden />
                    <span>{cityOf(timeZone)} time</span>
                    {showViewer && instant !== null && (
                        <span>
                            · Your time: {formatInstant(instant, viewer)},{' '}
                            {cityOf(viewer)}
                        </span>
                    )}
                </p>
            )}
        </div>
    );
}
