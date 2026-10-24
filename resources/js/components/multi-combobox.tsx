import { CheckIcon, ChevronsUpDownIcon } from 'lucide-react';
import { useState } from 'react';
import type { ComboboxOption } from '@/components/combobox';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

/**
 * A searchable multi-select built on the same Command/Popover stack as `Combobox`. Toggling an
 * option keeps the popover open so several can be picked in one pass; the trigger reports the
 * count. Pass `max` to cap the selection — unselected options disable once the cap is reached.
 * Use the single-select `Combobox` when only one value may be chosen.
 */
export function MultiCombobox({
    options,
    value,
    onChange,
    max,
    id,
    placeholder = 'Choose…',
    searchPlaceholder = 'Search…',
    emptyText = 'Nothing matches that.',
    disabled,
    className,
}: {
    options: ComboboxOption[];
    value: string[];
    onChange: (value: string[]) => void;
    /** Cap on how many may be selected at once. */
    max?: number;
    id?: string;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    disabled?: boolean;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const atMax = max !== undefined && value.length >= max;

    const toggle = (optionValue: string) => {
        onChange(
            value.includes(optionValue)
                ? value.filter((current) => current !== optionValue)
                : [...value, optionValue],
        );
    };

    return (
        // `modal`: a picker opened inside a Dialog must be its own layer, or the dialog's
        // outside-pointer-events block leaves the list unclickable.
        <Popover open={open} onOpenChange={setOpen} modal>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    disabled={disabled}
                    className={cn(
                        'h-10 justify-between gap-2 px-3 font-normal',
                        value.length === 0 && 'text-muted-foreground',
                        className,
                    )}
                >
                    <span className="truncate">
                        {value.length === 0
                            ? placeholder
                            : `${value.length} selected`}
                    </span>
                    <ChevronsUpDownIcon className="size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-(--radix-popover-trigger-width) min-w-56 p-0"
                align="start"
            >
                <Command>
                    <CommandInput placeholder={searchPlaceholder} />
                    <CommandList>
                        <CommandEmpty>{emptyText}</CommandEmpty>
                        <CommandGroup>
                            {options.map((option) => {
                                const checked = value.includes(option.value);
                                return (
                                    <CommandItem
                                        key={option.value}
                                        value={option.value}
                                        keywords={[
                                            option.label,
                                            ...(option.keywords ?? []),
                                        ]}
                                        // cmdk's `disabled` also drops the item from search;
                                        // keep it findable and block the toggle instead.
                                        aria-disabled={!checked && atMax}
                                        onSelect={() => {
                                            if (!checked && atMax) {
                                                return;
                                            }
                                            toggle(option.value);
                                        }}
                                        className={cn(
                                            !checked &&
                                                atMax &&
                                                'cursor-not-allowed opacity-50',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'flex size-4 shrink-0 items-center justify-center rounded-[4px] border',
                                                checked
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'border-input',
                                            )}
                                            aria-hidden
                                        >
                                            {checked && (
                                                <CheckIcon className="size-3" />
                                            )}
                                        </span>
                                        <span className="truncate">
                                            {option.label}
                                        </span>
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
