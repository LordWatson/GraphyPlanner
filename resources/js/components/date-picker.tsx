import { CalendarIcon } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatDate, toDateValue } from '@/lib/dates';
import { cn } from '@/lib/utils';

/**
 * Step I-14.2 / M-1.4 — the shared date field: a button showing "7 Oct 2026" that opens a
 * calendar. Works in `YYYY-MM-DD` strings (what the API and form fields use), never `Date`
 * objects, so a date can't shift a day across timezones.
 *
 * Controlled (`value` + `onChange`) or, inside an uncontrolled `<Form>`, with `defaultValue`.
 * `name` adds a hidden input so the value submits with the form.
 */
export type DatePickerProps = {
    value?: string | null;
    defaultValue?: string | null;
    onChange?: (value: string | null) => void;
    id?: string;
    name?: string;
    placeholder?: string;
    disabled?: boolean;
    /** Earliest / latest selectable day, `YYYY-MM-DD`. */
    min?: string;
    max?: string;
    clearable?: boolean;
    className?: string;
    'aria-invalid'?: boolean;
    'aria-label'?: string;
};

export function DatePicker({
    value: controlled,
    defaultValue,
    onChange,
    id,
    name,
    placeholder = 'Pick a date',
    disabled,
    min,
    max,
    clearable = false,
    className,
    'aria-invalid': ariaInvalid,
    'aria-label': ariaLabel,
}: DatePickerProps) {
    const [open, setOpen] = useState(false);
    const [uncontrolled, setUncontrolled] = useState(defaultValue ?? null);
    const value = controlled !== undefined ? controlled : uncontrolled;
    const selected = value ? fromDateValue(value) : undefined;

    const change = (next: string | null) => {
        setUncontrolled(next);
        onChange?.(next);
    };

    return (
        // `modal`: a picker opened inside a Dialog must be its own layer, or the dialog's
        // outside-pointer-events block leaves the calendar unclickable.
        <Popover open={open} onOpenChange={setOpen} modal>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    disabled={disabled}
                    aria-invalid={ariaInvalid}
                    aria-label={
                        ariaLabel && value
                            ? `${ariaLabel}: ${formatDate(value)}`
                            : ariaLabel
                    }
                    className={cn(
                        'h-10 w-full justify-start gap-2 px-3 font-normal',
                        !value && 'text-muted-foreground',
                        className,
                    )}
                >
                    <CalendarIcon className="size-4 opacity-60" />
                    {value ? formatDate(value) : placeholder}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-3" align="start">
                <Calendar
                    mode="single"
                    selected={selected}
                    defaultMonth={selected}
                    disabled={[
                        ...(min ? [{ before: fromDateValue(min) }] : []),
                        ...(max ? [{ after: fromDateValue(max) }] : []),
                    ]}
                    onSelect={(day) => {
                        change(day ? toDateValue(day) : null);
                        setOpen(false);
                    }}
                />
                {clearable && value && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="mt-2 w-full"
                        onClick={() => {
                            change(null);
                            setOpen(false);
                        }}
                    >
                        Clear date
                    </Button>
                )}
            </PopoverContent>
            {name && <input type="hidden" name={name} value={value ?? ''} />}
        </Popover>
    );
}

function fromDateValue(value: string): Date {
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return new Date(year, month - 1, day);
}
