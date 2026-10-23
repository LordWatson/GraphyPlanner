import { CalendarClock } from 'lucide-react';
import { useState } from 'react';
import { DatePicker } from '@/components/date-picker';
import { TimeField } from '@/components/time-field';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

/**
 * Step M-5.7 / I-5.1 — the keyboard-accessible way to move a post from the calendar, sitting
 * alongside pointer/touch drag-and-drop (`@dnd-kit`). A small clock button on each movable chip
 * opens this popover; a date (and, on the week/day time grid, a time) is picked and submitted
 * entirely from the keyboard, so rescheduling never depends on a mouse.
 *
 * `withTime` is off in the month view (the whole post shifts by whole days) and on in the
 * week/day views (a single channel moves to an exact slot).
 */
export function ReschedulePopover({
    label,
    withTime,
    defaultDate,
    defaultTime,
    minDate,
    onSubmit,
}: {
    /** Accessible name for the trigger, e.g. "Reschedule — Acme on Instagram". */
    label: string;
    withTime: boolean;
    defaultDate: string | null;
    defaultTime: string | null;
    /** Earliest selectable day (today), so the form can't choose the past. */
    minDate: string;
    onSubmit: (move: { date: string; time: string | null }) => void;
}) {
    const [open, setOpen] = useState(false);
    const [date, setDate] = useState<string | null>(defaultDate);
    const [time, setTime] = useState<string>(defaultTime ?? '');

    // Re-seed from the chip each time the popover opens, so it always reflects the current slot.
    const onOpenChange = (next: boolean) => {
        if (next) {
            setDate(defaultDate);
            setTime(defaultTime ?? '');
        }
        setOpen(next);
    };

    const submit = () => {
        if (!date) return;
        onSubmit({ date, time: withTime ? time || null : null });
        setOpen(false);
    };

    return (
        <Popover open={open} onOpenChange={onOpenChange}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={label}
                    // Hidden until the chip is hovered or anything inside it is focused, so the
                    // dense grid stays calm but the control is always keyboard-reachable.
                    className="size-6 shrink-0 opacity-0 transition-opacity group-focus-within/chip:opacity-100 group-hover/chip:opacity-100 focus-visible:opacity-100"
                    onClick={(event) => event.stopPropagation()}
                >
                    <CalendarClock className="size-3.5" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                className="w-64 space-y-3"
                // Keep clicks inside from bubbling to the chip's link/drag handlers.
                onClick={(event) => event.stopPropagation()}
            >
                <p className="text-sm font-medium">Move post</p>
                <div className="grid gap-1.5">
                    <Label htmlFor="reschedule-date">Date</Label>
                    <DatePicker
                        id="reschedule-date"
                        aria-label="New date"
                        value={date}
                        min={minDate}
                        onChange={setDate}
                    />
                </div>
                {withTime && (
                    <div className="grid gap-1.5">
                        <Label htmlFor="reschedule-time">Time (local)</Label>
                        <TimeField
                            id="reschedule-time"
                            aria-label="New time"
                            value={time}
                            onChange={setTime}
                        />
                    </div>
                )}
                <Button
                    type="button"
                    size="sm"
                    className="w-full"
                    disabled={!date}
                    onClick={submit}
                >
                    Move
                </Button>
            </PopoverContent>
        </Popover>
    );
}
