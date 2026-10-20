import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * Step I-4.6 / M-1.6 — the shared local-time field (`HH:MM`, 24-hour). The browser's time
 * control is kept on purpose: it's keyboard- and screen-reader-friendly and native on phones.
 * Use it through `DateTimePicker` where a date goes with it. Controlled (`value` + `onChange`) or
 * uncontrolled with `defaultValue` inside a `<Form>`.
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
    'aria-label'?: string;
    'aria-invalid'?: boolean;
}) {
    return (
        <Input
            id={id}
            name={name}
            type="time"
            value={value}
            defaultValue={defaultValue}
            disabled={disabled}
            required={required}
            aria-label={ariaLabel}
            aria-invalid={ariaInvalid}
            onChange={(event) => onChange?.(event.target.value)}
            className={cn('font-mono text-xs', className)}
        />
    );
}
