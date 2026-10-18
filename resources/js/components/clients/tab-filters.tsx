import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type TabFilterValue = string | number | null;

export type TabFilterOption = { value: string; label: string };

/**
 * Every filter or page change on a server-paginated client tab is a GET round-trip to the tab's
 * own route: empty values and `page=1` are dropped from the query string, and only the listed
 * props reload (the header and its stats stay as they are).
 */
export function visitClientTab(
    url: string,
    filters: Record<string, TabFilterValue>,
    changes: Record<string, TabFilterValue | undefined>,
    only: string[],
) {
    const next: Record<string, TabFilterValue | undefined> = {
        ...filters,
        page: 1,
        ...changes,
    };

    router.get(
        url,
        Object.fromEntries(
            Object.entries(next).filter(
                ([key, value]) =>
                    value !== null &&
                    value !== undefined &&
                    value !== '' &&
                    !(key === 'page' && value === 1),
            ),
        ) as Record<string, string | number>,
        { only, preserveState: true, preserveScroll: true, replace: true },
    );
}

/** A debounced search box, so typing doesn't fire a request per keystroke. */
export function TabSearchFilter({
    id,
    label,
    placeholder,
    value,
    onSearch,
}: {
    id: string;
    label: string;
    placeholder: string;
    value: string | null;
    onSearch: (search: string | null) => void;
}) {
    const [search, setSearch] = useState(value ?? '');
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }

        const timeout = window.setTimeout(
            () => onSearch(search.trim() || null),
            300,
        );

        return () => window.clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <div className="grid gap-1">
            <Label htmlFor={id}>{label}</Label>
            <div className="relative w-full sm:w-56">
                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input
                    id={id}
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder={placeholder}
                    className="pl-9"
                />
            </div>
        </div>
    );
}

/** A labelled select with an "All …" entry that clears the filter. */
export function TabSelectFilter({
    id,
    label,
    allLabel,
    value,
    options,
    onChange,
}: {
    id: string;
    label: string;
    allLabel: string;
    value: string | null;
    options: TabFilterOption[];
    onChange: (value: string | null) => void;
}) {
    return (
        <div className="grid gap-1">
            <Label htmlFor={id}>{label}</Label>
            <Select
                value={value ?? '__all'}
                onValueChange={(next) =>
                    onChange(next === '__all' ? null : next)
                }
            >
                <SelectTrigger id={id} className="w-48">
                    <SelectValue placeholder={allLabel} />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="__all">{allLabel}</SelectItem>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

/** A labelled segmented toggle; the first option (`null`) means "no filter". */
export function TabSegmentedFilter({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string | null;
    options: { value: string | null; label: string }[];
    onChange: (value: string | null) => void;
}) {
    return (
        <div className="grid gap-1">
            <Label>{label}</Label>
            <div
                role="group"
                aria-label={label}
                className="border-border flex flex-wrap items-center gap-1 rounded-md border p-0.5"
            >
                {options.map((option) => {
                    const active = value === option.value;

                    return (
                        <button
                            key={option.value ?? '__all'}
                            type="button"
                            aria-pressed={active}
                            onClick={() => onChange(option.value)}
                            className={`rounded-sm px-2.5 py-1 text-xs transition-colors ${
                                active
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {option.label}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
