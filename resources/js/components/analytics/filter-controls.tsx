import type { LucideIcon } from 'lucide-react';
import { ChevronDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { RangeKey } from './format';
import { rangeLabels } from './format';
import { DatePicker } from '@/components/date-picker';

const ranges = Object.keys(rangeLabels) as RangeKey[];

export function toggle<T>(list: T[], value: T): T[] {
    return list.includes(value)
        ? list.filter((item) => item !== value)
        : [...list, value];
}

/**
 * The segmented range selector (teal pill = selected, book §7.4 B) plus the custom date inputs.
 * Scrolls rather than wraps on narrow screens (Poppins is wide).
 */
export function RangePicker({
    range,
    from,
    to,
    onChange,
}: {
    range: RangeKey;
    from: string;
    to: string;
    onChange: (next: { range: RangeKey; from: string; to: string }) => void;
}) {
    return (
        <>
            <div
                role="radiogroup"
                aria-label="Date range"
                className="bg-muted flex max-w-full gap-1 overflow-x-auto rounded-full p-1"
            >
                {ranges.map((key) => {
                    const active = range === key;

                    return (
                        <button
                            key={key}
                            type="button"
                            role="radio"
                            aria-checked={active}
                            // Switching to "custom" starts from the dates currently shown.
                            onClick={() => onChange({ range: key, from, to })}
                            className={cn(
                                'focus-visible:ring-ring/40 min-h-8 shrink-0 rounded-full px-3.5 py-1 text-[13px] font-medium whitespace-nowrap transition-colors outline-none focus-visible:ring-[3px]',
                                active
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {rangeLabels[key]}
                        </button>
                    );
                })}
            </div>

            {range === 'custom' && (
                <div className="flex items-center gap-1.5">
                    <DatePicker
                        aria-label="From"
                        className="w-full sm:w-38"
                        value={from}
                        max={to || undefined}
                        onChange={(value) =>
                            value && onChange({ range, from: value, to })
                        }
                    />
                    <span className="text-muted-foreground font-mono text-xs">
                        →
                    </span>
                    <DatePicker
                        aria-label="To"
                        className="w-full sm:w-38"
                        value={to}
                        min={from || undefined}
                        onChange={(value) =>
                            value && onChange({ range, from, to: value })
                        }
                    />
                </div>
            )}
        </>
    );
}

export type FilterOption<T extends string | number> = {
    value: T;
    label: string;
    /** A muted second line, e.g. a campaign's client. */
    hint?: string;
};

/**
 * A dropdown of checkboxes over a list of options. An empty selection means "all" (`allLabel`).
 * In `sections` mode the empty selection shows every box ticked, the last box can't be unticked
 * (never an empty run) and ticking every box stores `[]` again.
 */
export function MultiSelectFilter<T extends string | number>({
    icon: Icon,
    label,
    allLabel,
    noun,
    options,
    value,
    onChange,
    mode = 'filter',
    disabled = false,
}: {
    icon: LucideIcon;
    /** The menu's eyebrow. */
    label: string;
    allLabel: string;
    /** Plural noun for the "3 clients" summary. */
    noun: string;
    options: FilterOption<T>[];
    value: T[];
    onChange: (next: T[]) => void;
    mode?: 'filter' | 'sections';
    disabled?: boolean;
}) {
    const all = options.map((option) => option.value);
    const sections = mode === 'sections';
    const everything = value.length === 0 || value.length === all.length;

    const summary = everything
        ? allLabel
        : value.length === 1
          ? (options.find((option) => option.value === value[0])?.label ??
            `1 ${noun.replace(/s$/, '')}`)
          : `${value.length} ${noun}`;

    const change = (option: T) => {
        if (!sections) {
            onChange(toggle(value, option));

            return;
        }

        const next = toggle(value.length === 0 ? all : value, option);

        if (next.length === 0) {
            return;
        }

        onChange(
            next.length === all.length
                ? []
                : all.filter((key) => next.includes(key)),
        );
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild disabled={disabled}>
                <Button
                    variant="outline"
                    size="sm"
                    aria-label={`${label}: ${summary}`}
                >
                    <Icon />
                    <span className="max-w-44 truncate">{summary}</span>
                    <ChevronDown className="opacity-60" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="max-h-80 w-64 overflow-y-auto"
            >
                <DropdownMenuLabel className="eyebrow text-muted-foreground">
                    {label}
                </DropdownMenuLabel>
                {!sections && (
                    <>
                        <DropdownMenuItem
                            onSelect={(event) => {
                                event.preventDefault();
                                onChange([]);
                            }}
                        >
                            {allLabel}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                    </>
                )}
                {options.length === 0 && (
                    <p className="text-muted-foreground px-2 py-1.5 text-sm">
                        Nothing to pick yet.
                    </p>
                )}
                {options.map((option) => (
                    <DropdownMenuCheckboxItem
                        key={String(option.value)}
                        checked={
                            (sections && value.length === 0) ||
                            value.includes(option.value)
                        }
                        onSelect={(event) => event.preventDefault()}
                        onCheckedChange={() => change(option.value)}
                    >
                        <span className="flex min-w-0 flex-col">
                            <span className="truncate">{option.label}</span>
                            {option.hint && (
                                <span className="text-muted-foreground truncate text-xs">
                                    {option.hint}
                                </span>
                            )}
                        </span>
                    </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
