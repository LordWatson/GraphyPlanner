import type { InertiaLinkProps } from '@inertiajs/react';
import { Link, router } from '@inertiajs/react';
import { CalendarX2, ChevronLeft, ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/page';
import { Button } from '@/components/ui/button';
import { resolveStatus, toneBgClass, toneTextClass } from '@/lib/status';
import { cn } from '@/lib/utils';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

type Option = { value: string; label: string };
type NamedOption = { id: number; name: string };

type OccurrenceDisplay = {
    date: string | null;
    time: string | null;
    timezone: string | null;
};

export type Occurrence = {
    post_id: number;
    target_id: number;
    client_id: number;
    client_name: string | null;
    campaign_id: number | null;
    campaign_name: string | null;
    status: string;
    status_label: string;
    master_caption: string | null;
    platform: string | null;
    handle: string | null;
    account_local: OccurrenceDisplay;
    org_timezone: OccurrenceDisplay;
    scheduled_at_utc: string | null;
};

export type CalendarFilters = {
    client_id: number | null;
    campaign_id: number | null;
    status: string | null;
    platform: string | null;
};

/** The server payload shared by `CalendarController` and `PortalCalendarController`. */
export type CalendarPayload = {
    occurrences: Occurrence[];
    orgTimezone: string;
    filters: CalendarFilters;
    filterOptions: {
        clients: NamedOption[];
        campaigns: NamedOption[];
        statuses: Option[];
        platforms: Option[];
    };
};

type ContentCalendarProps = CalendarPayload & {
    /** URL the filter selects re-request (with the filters as query params). */
    baseUrl: string;
    /** Where an occurrence chip links — the internal editor or the portal post page. */
    postHref: (postId: number) => NonNullable<InertiaLinkProps['href']>;
    /**
     * Multi-client (internal) vs single-client (portal) mode: shows the client filter and leads
     * each chip with the client name; in portal mode chips lead with the caption instead.
     */
    multiClient?: boolean;
};

type View = 'month' | 'week' | 'day' | 'list';
type TimezoneMode = 'account' | 'org';

function parseLocalDate(dateStr: string): Date {
    const [year, month, day] = dateStr.split('-').map(Number);
    return new Date(year, month - 1, day);
}

function toDateKey(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function startOfWeek(date: Date): Date {
    const result = new Date(date);
    result.setDate(result.getDate() - result.getDay());
    return result;
}

function addDays(date: Date, days: number): Date {
    const result = new Date(date);
    result.setDate(result.getDate() + days);
    return result;
}

const dateHeaderFormatter = new Intl.DateTimeFormat('en-US', {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
    year: 'numeric',
});

const monthLabelFormatter = new Intl.DateTimeFormat('en-US', {
    month: 'long',
    year: 'numeric',
});
const weekdayFormatter = new Intl.DateTimeFormat('en-US', { weekday: 'short' });
const weekdayLongFormatter = new Intl.DateTimeFormat('en-US', {
    weekday: 'long',
});
const shortDateFormatter = new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
});

/**
 * The content calendar (month / week / day / list, account-local vs org timezone, filters) used
 * by both the internal staff calendar and the client portal calendar. All timezone maths happens
 * server-side (`BuildCalendarOccurrencesAction`); this only groups and renders.
 */
export function ContentCalendar({
    occurrences,
    orgTimezone,
    filters,
    filterOptions,
    baseUrl,
    postHref,
    multiClient = true,
}: ContentCalendarProps) {
    // Phones open on the list view — the month grid needs ~760px and would scroll sideways.
    const [view, setView] = useState<View>(() =>
        typeof window !== 'undefined' &&
        window.matchMedia('(max-width: 767px)').matches
            ? 'list'
            : 'month',
    );
    const [tzMode, setTzMode] = useState<TimezoneMode>('account');
    const [anchor, setAnchor] = useState<Date>(new Date());

    const display = (occurrence: Occurrence): OccurrenceDisplay =>
        tzMode === 'account'
            ? occurrence.account_local
            : occurrence.org_timezone;

    const occurrencesByDate = useMemo(() => {
        const map = new Map<string, Occurrence[]>();
        for (const occurrence of occurrences) {
            const date = display(occurrence).date;
            if (!date) continue;
            const list = map.get(date) ?? [];
            list.push(occurrence);
            map.set(date, list);
        }
        for (const list of map.values()) {
            list.sort((a, b) =>
                (display(a).time ?? '').localeCompare(display(b).time ?? ''),
            );
        }
        return map;
    }, [occurrences, tzMode]);

    const applyFilter = (key: keyof CalendarFilters, value: string | null) => {
        router.get(
            baseUrl,
            { ...filters, [key]: value },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const today = toDateKey(new Date());

    const renderOccurrenceChip = (occurrence: Occurrence) => {
        const time = display(occurrence).time?.slice(0, 5);
        const tone = resolveStatus('post', occurrence.status).tone;

        return (
            <Link
                key={occurrence.target_id}
                href={postHref(occurrence.post_id)}
                title={`${occurrence.status_label} — ${(multiClient ? occurrence.client_name : occurrence.master_caption) ?? ''}`}
                className="group/chip bg-card hover:border-foreground/20 relative flex min-w-0 flex-col gap-0.5 overflow-hidden rounded-lg border py-1.5 pr-2 pl-3 text-xs transition-colors"
            >
                <span
                    aria-hidden
                    className={cn(
                        'absolute inset-y-1.5 left-1 w-[3px] rounded-full',
                        toneBgClass[tone],
                    )}
                />
                <span className="flex items-center justify-between gap-1.5">
                    <span className="font-mono text-[11px] tabular-nums">
                        {time ?? '—'}
                    </span>
                    <span
                        className={cn(
                            'truncate text-[10.5px] font-medium',
                            toneTextClass[tone],
                        )}
                    >
                        {occurrence.status_label}
                    </span>
                </span>
                <span className="group-hover/chip:text-primary truncate font-medium">
                    {multiClient
                        ? occurrence.client_name
                        : (occurrence.master_caption ?? 'Untitled post')}
                </span>
                <span className="text-muted-foreground truncate">
                    {occurrence.handle ?? occurrence.platform ?? '—'}
                    {multiClient && occurrence.master_caption
                        ? ` · ${occurrence.master_caption}`
                        : ''}
                </span>
            </Link>
        );
    };

    const renderNav = (
        label: ReactNode,
        onPrev: () => void,
        onNext: () => void,
        unit: string,
    ) => (
        <div className="flex flex-wrap items-end justify-between gap-3 pb-4">
            <h2 className="[&_em]:text-muted-foreground font-serif text-4xl leading-none tracking-[-0.02em] [&_em]:italic">
                {label}
            </h2>
            <div className="flex items-center gap-1.5">
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setAnchor(new Date())}
                >
                    Today
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    className="size-8"
                    aria-label={`Previous ${unit}`}
                    onClick={onPrev}
                >
                    <ChevronLeft />
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    className="size-8"
                    aria-label={`Next ${unit}`}
                    onClick={onNext}
                >
                    <ChevronRight />
                </Button>
            </div>
        </div>
    );

    const renderDayNumber = (day: Date, muted = false) => {
        const isToday = toDateKey(day) === today;

        return (
            <span
                className={cn(
                    'flex size-7 items-center justify-center rounded-full font-mono text-[11px] tabular-nums',
                    isToday && 'bg-primary text-primary-foreground font-medium',
                    !isToday && muted && 'text-muted-foreground/60',
                )}
            >
                {day.getDate()}
            </span>
        );
    };

    const monthTitleFormatter = new Intl.DateTimeFormat('en-US', {
        month: 'long',
    });

    const renderMonthView = () => {
        const monthStart = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
        const gridStart = startOfWeek(monthStart);
        const days = Array.from({ length: 42 }, (_, i) =>
            addDays(gridStart, i),
        );

        return (
            <div className="flex flex-col">
                {renderNav(
                    <>
                        {monthTitleFormatter.format(monthStart)}{' '}
                        <em>{monthStart.getFullYear()}</em>
                    </>,
                    () => setAnchor(addDays(monthStart, -1)),
                    () => setAnchor(addDays(monthStart, 32)),
                    'month',
                )}
                <div className="overflow-x-auto">
                    <div className="min-w-[760px]">
                        <div className="eyebrow text-muted-foreground grid grid-cols-7 pb-2">
                            {days.slice(0, 7).map((day) => (
                                <div key={day.toISOString()} className="px-2">
                                    {weekdayFormatter.format(day)}
                                </div>
                            ))}
                        </div>
                        <div className="bg-border grid grid-cols-7 gap-px overflow-hidden rounded-xl border">
                            {days.map((day) => {
                                const key = toDateKey(day);
                                const dayOccurrences =
                                    occurrencesByDate.get(key) ?? [];
                                const isCurrentMonth =
                                    day.getMonth() === monthStart.getMonth();
                                return (
                                    <div
                                        key={key}
                                        className={cn(
                                            'flex min-h-28 flex-col gap-1 p-1.5',
                                            isCurrentMonth
                                                ? 'bg-card'
                                                : 'bg-muted/50',
                                        )}
                                    >
                                        {renderDayNumber(day, !isCurrentMonth)}
                                        <div className="flex flex-col gap-1">
                                            {dayOccurrences.map(
                                                renderOccurrenceChip,
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </div>
        );
    };

    const renderWeekView = () => {
        const weekStart = startOfWeek(anchor);
        const days = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));

        return (
            <div className="flex flex-col">
                {renderNav(
                    <>
                        {shortDateFormatter.format(weekStart)}{' '}
                        <em>
                            – {shortDateFormatter.format(addDays(weekStart, 6))}
                        </em>
                    </>,
                    () => setAnchor(addDays(weekStart, -7)),
                    () => setAnchor(addDays(weekStart, 7)),
                    'week',
                )}
                <div className="overflow-x-auto">
                    <div className="bg-border grid min-w-[760px] grid-cols-7 gap-px overflow-hidden rounded-xl border">
                        {days.map((day) => {
                            const key = toDateKey(day);
                            const dayOccurrences =
                                occurrencesByDate.get(key) ?? [];
                            return (
                                <div
                                    key={key}
                                    className="bg-card flex min-h-72 flex-col gap-1.5 p-2"
                                >
                                    <div className="flex items-center justify-between pb-1">
                                        <span className="eyebrow text-muted-foreground">
                                            {weekdayFormatter.format(day)}
                                        </span>
                                        {renderDayNumber(day)}
                                    </div>
                                    {dayOccurrences.map(renderOccurrenceChip)}
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        );
    };

    const renderDayView = () => {
        const key = toDateKey(anchor);
        const dayOccurrences = occurrencesByDate.get(key) ?? [];

        return (
            <div className="flex flex-col">
                {renderNav(
                    <>
                        {weekdayLongFormatter.format(anchor)}{' '}
                        <em>{shortDateFormatter.format(anchor)}</em>
                    </>,
                    () => setAnchor(addDays(anchor, -1)),
                    () => setAnchor(addDays(anchor, 1)),
                    'day',
                )}
                {dayOccurrences.length === 0 ? (
                    <EmptyState
                        compact
                        icon={CalendarX2}
                        title="Nothing scheduled."
                        description="No posts are going out on this day."
                    />
                ) : (
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {dayOccurrences.map(renderOccurrenceChip)}
                    </div>
                )}
            </div>
        );
    };

    const renderListView = () => {
        const sortedDates = Array.from(occurrencesByDate.keys()).sort();

        return (
            <div className="flex flex-col">
                {sortedDates.length === 0 && (
                    <EmptyState
                        compact
                        icon={CalendarX2}
                        title="No posts found."
                        description="Nothing matches the current filters."
                    />
                )}
                {sortedDates.map((dateKey) => {
                    const date = parseLocalDate(dateKey);

                    return (
                        <div
                            key={dateKey}
                            className="grid grid-cols-1 gap-3 border-b py-4 last:border-b-0 sm:grid-cols-[7rem_1fr]"
                        >
                            <div className="flex items-baseline gap-2 sm:flex-col sm:gap-0">
                                <span
                                    className={cn(
                                        'font-serif text-4xl leading-none tabular-nums',
                                        dateKey === today && 'text-primary',
                                    )}
                                >
                                    {String(date.getDate()).padStart(2, '0')}
                                </span>
                                <span className="eyebrow text-muted-foreground">
                                    {
                                        dateHeaderFormatter
                                            .format(date)
                                            .split(',')[0]
                                    }{' '}
                                    · {monthLabelFormatter.format(date)}
                                </span>
                            </div>
                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                {(occurrencesByDate.get(dateKey) ?? []).map(
                                    renderOccurrenceChip,
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>
        );
    };

    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-wrap items-center gap-2">
                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={view}
                    onValueChange={(value) => value && setView(value as View)}
                    aria-label="Calendar view"
                    className="mr-2"
                >
                    <ToggleGroupItem value="month" className="px-3.5">
                        Month
                    </ToggleGroupItem>
                    <ToggleGroupItem value="week" className="px-3.5">
                        Week
                    </ToggleGroupItem>
                    <ToggleGroupItem value="day" className="px-3.5">
                        Day
                    </ToggleGroupItem>
                    <ToggleGroupItem value="list" className="px-3.5">
                        List
                    </ToggleGroupItem>
                </ToggleGroup>

                <span className="eyebrow text-muted-foreground mr-1">
                    Filter
                </span>
                {multiClient && (
                    <Select
                        value={
                            filters.client_id
                                ? String(filters.client_id)
                                : 'all'
                        }
                        onValueChange={(value) =>
                            applyFilter(
                                'client_id',
                                value === 'all' ? null : value,
                            )
                        }
                    >
                        <SelectTrigger size="sm" className="w-40">
                            <SelectValue placeholder="Client" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All clients</SelectItem>
                            {filterOptions.clients.map((client) => (
                                <SelectItem
                                    key={client.id}
                                    value={String(client.id)}
                                >
                                    {client.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}

                <Select
                    value={
                        filters.campaign_id
                            ? String(filters.campaign_id)
                            : 'all'
                    }
                    onValueChange={(value) =>
                        applyFilter(
                            'campaign_id',
                            value === 'all' ? null : value,
                        )
                    }
                >
                    <SelectTrigger size="sm" className="w-40">
                        <SelectValue placeholder="Campaign" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All campaigns</SelectItem>
                        {filterOptions.campaigns.map((campaign) => (
                            <SelectItem
                                key={campaign.id}
                                value={String(campaign.id)}
                            >
                                {campaign.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Select
                    value={filters.status ?? 'all'}
                    onValueChange={(value) =>
                        applyFilter('status', value === 'all' ? null : value)
                    }
                >
                    <SelectTrigger size="sm" className="w-40">
                        <SelectValue placeholder="Status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All statuses</SelectItem>
                        {filterOptions.statuses.map((status) => (
                            <SelectItem key={status.value} value={status.value}>
                                {status.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Select
                    value={filters.platform ?? 'all'}
                    onValueChange={(value) =>
                        applyFilter('platform', value === 'all' ? null : value)
                    }
                >
                    <SelectTrigger size="sm" className="w-40">
                        <SelectValue placeholder="Platform" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All platforms</SelectItem>
                        {filterOptions.platforms.map((platform) => (
                            <SelectItem
                                key={platform.value}
                                value={platform.value}
                            >
                                {platform.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <ToggleGroup
                    type="single"
                    variant="outline"
                    size="sm"
                    value={tzMode}
                    onValueChange={(value) =>
                        value && setTzMode(value as TimezoneMode)
                    }
                    aria-label="Timezone"
                    className="lg:ml-auto"
                >
                    <ToggleGroupItem value="account" className="px-3">
                        Account local
                    </ToggleGroupItem>
                    <ToggleGroupItem value="org" className="px-3">
                        {orgTimezone}
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            <div className="bg-card animate-rise rounded-2xl border p-4 sm:p-5">
                {view === 'month' && renderMonthView()}
                {view === 'week' && renderWeekView()}
                {view === 'day' && renderDayView()}
                {view === 'list' && renderListView()}
            </div>
        </div>
    );
}
