import { CheckIcon, ChevronsUpDownIcon } from 'lucide-react';
import { useState } from 'react';
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

export type ComboboxOption = {
    value: string;
    label: string;
    /** Extra text the search matches on (e.g. a client's industry). */
    keywords?: string[];
};

/**
 * Step I-14.2 / M-1.4 — a searchable select for long lists (clients, accounts, staff). Use the
 * plain `Select` for short, fixed lists. `name` adds a hidden input for `<Form>` submissions.
 */
export function Combobox({
    options,
    value,
    onChange,
    id,
    name,
    placeholder = 'Choose…',
    searchPlaceholder = 'Search…',
    emptyText = 'Nothing matches that.',
    disabled,
    className,
}: {
    options: ComboboxOption[];
    value: string | null | undefined;
    onChange: (value: string | null) => void;
    id?: string;
    name?: string;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    disabled?: boolean;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const selected = options.find((option) => option.value === value);

    return (
        // `modal`: a picker opened inside a Dialog must be its own layer, or the dialog's
        // outside-pointer-events block leaves the calendar unclickable.
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
                        !selected && 'text-muted-foreground',
                        className,
                    )}
                >
                    <span className="truncate">
                        {selected?.label ?? placeholder}
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
                            {options.map((option) => (
                                <CommandItem
                                    key={option.value}
                                    value={option.value}
                                    keywords={[
                                        option.label,
                                        ...(option.keywords ?? []),
                                    ]}
                                    onSelect={(current) => {
                                        onChange(
                                            current === value ? null : current,
                                        );
                                        setOpen(false);
                                    }}
                                >
                                    <span className="truncate">
                                        {option.label}
                                    </span>
                                    <CheckIcon
                                        className={cn(
                                            'ml-auto',
                                            option.value === value
                                                ? 'opacity-100'
                                                : 'opacity-0',
                                        )}
                                    />
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
            {name && <input type="hidden" name={name} value={value ?? ''} />}
        </Popover>
    );
}
