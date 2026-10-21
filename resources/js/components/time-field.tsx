import { useState } from 'react';
import { cn } from '@/lib/utils';

const HOURS = Array.from({ length: 24 }, (_, h) => String(h).padStart(2, '0'));
const MINUTES = ['00', '15', '30', '45'];

const selectClasses =
    'border-input bg-card focus-visible:border-ring focus-visible:ring-ring/25 aria-invalid:border-destructive hover:border-foreground/60 min-w-0 flex-1 rounded-lg border-2 px-2 text-center font-mono text-xs transition-[color,box-shadow,border-color] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50';

/**
 * The shared local-time field (`HH:MM`, 24-hour), split into two dropdowns — hour and quarter-hour —
 * so it's quick to click and type-ahead through on desktop, with native wheels on phones, instead of
 * the fiddly native time spinner. Picking an hour defaults the minutes to `:00`. A stored minute
 * that isn't on the quarter-hour (a best-time or an older post) is added so it still shows.
 * Controlled (`value` + `onChange`) or, inside an uncontrolled `<Form>`, with `defaultValue` + `name`.
 */
export function TimeField({
    value,
    defaultValue,
    onChange,
    id,
    name,
    className,
    disabled,
    required,
    size = 'default',
    'aria-label': ariaLabel,
    'aria-invalid': ariaInvalid,
}: {
    value?: string;
    defaultValue?: string;
    onChange?: (value: string) => void;
    id?: string;
    name?: string;
    className?: string;
    disabled?: boolean;
    required?: boolean;
    size?: 'sm' | 'default';
    'aria-label'?: string;
    'aria-invalid'?: boolean;
}) {
    const controlled = value !== undefined;
    const [internal, setInternal] = useState(defaultValue ?? '');
    const current = (controlled ? value : internal).slice(0, 5);
    const hour = current ? current.slice(0, 2) : '';
    const minute = current ? current.slice(3, 5) : '';

    const minuteOptions =
        minute && !MINUTES.includes(minute)
            ? [...MINUTES, minute].sort()
            : MINUTES;

    const emit = (nextHour: string, nextMinute: string) => {
        const next =
            nextHour === '' && nextMinute === ''
                ? ''
                : `${nextHour || '00'}:${nextMinute || '00'}`;

        if (!controlled) {
            setInternal(next);
        }

        onChange?.(next);
    };

    const heightClass = size === 'sm' ? 'h-8' : 'h-10';

    return (
        <div className={cn('flex items-center gap-1', className)}>
            <select
                id={id}
                aria-label={ariaLabel ? `${ariaLabel} hour` : undefined}
                aria-invalid={ariaInvalid}
                disabled={disabled}
                required={required}
                value={controlled ? hour : undefined}
                defaultValue={controlled ? undefined : hour}
                onChange={(event) => emit(event.target.value, minute)}
                className={cn(selectClasses, heightClass)}
            >
                <option value="" disabled>
                    Hr
                </option>
                {HOURS.map((h) => (
                    <option key={h} value={h}>
                        {h}
                    </option>
                ))}
            </select>
            <span
                className="text-muted-foreground font-mono text-xs"
                aria-hidden
            >
                :
            </span>
            <select
                id={id ? `${id}-minutes` : undefined}
                aria-label={ariaLabel ? `${ariaLabel} minutes` : 'Minutes'}
                aria-invalid={ariaInvalid}
                disabled={disabled}
                required={required}
                value={controlled ? minute : undefined}
                defaultValue={controlled ? undefined : minute}
                onChange={(event) => emit(hour, event.target.value)}
                className={cn(selectClasses, heightClass)}
            >
                <option value="" disabled>
                    Min
                </option>
                {minuteOptions.map((m) => (
                    <option key={m} value={m}>
                        {m}
                    </option>
                ))}
            </select>
            {name && <input type="hidden" name={name} value={current} />}
        </div>
    );
}
