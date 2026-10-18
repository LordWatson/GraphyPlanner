import type { InertiaLinkProps } from '@inertiajs/react';
import { Link, router } from '@inertiajs/react';
import { CalendarX2, ChevronLeft, ChevronRight } from 'lucide-react';
import type { DragEvent, ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';
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

/** Step I-2.3: the date range (inclusive, `Y-m-d`) the server loaded occurrences for. */
export type CalendarWindow = { from: string; to: string };

/** The server payload shared by `CalendarController` and `PortalCalendarController`. */
export type CalendarPayload = {
    occurrences: Occurrence[];
    window: CalendarWindow;
    orgTimezone: string;
    filters: CalendarFilters;
    filterOptions: {
        clients: NamedOption[];
        campaigns: NamedOption[];
        statuses: Option[];
        platforms: Option[];
    };
    /** Posts the viewer may drag to another day — internal calendar only. */
    reschedulablePostIds?: number[];
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
    /** Whether an occurrence's chip can be dragged to another day (month/week views). */
    canReschedule?: (occurrence: Occurrence) => boolean;
    /**
     * Called when a chip is dropped on a different, non-past day. `days` is the whole-day shift
     * from the chip's displayed date; the caller confirms and persists the move.
     */
    onReschedule?: (
        occurrence: Occurrence,
        move: { from: string; to: string; days: number },
    ) => void;
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

function daysBetween(fromKey: string, toKey: string): number {
    return Math.round(
        (parseLocalDate(toKey).getTime() - parseLocalDate(fromKey).getTime()) /
            86_400_000,
    );
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

/**
 * The 6-week, Sunday-first month grid containing `date` — the same window
 * `App\Support\Calendar\CalendarWindow::monthGrid()` serves. It also contains every week and day
 * view anchored in that month, so only a month change needs new data.
 */
function gridWindow(date: Date): CalendarWindow {
    const gridStart = startOfWeek(
        new Date(date.getFullYear(), date.getMonth(), 1),
    );

    return {
        from: toDateKey(gridStart),
        to: toDateKey(addDays(gridStart, 41)),
    };
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
    window: loadedWindow,
    orgTimezone,
    filters,
    filterOptions,
    baseUrl,
    postHref,
    multiClient = true,
    canReschedule,
    onReschedule,
}: ContentCalendarProps) {
    // Phones open on the list view — the month grid needs ~760px and would scroll sideways.
    const [view, setView] = useState<View>(() =>
        typeof window !== 'undefined' &&
        window.matchMedia('(max-width: 767px)').matches
            ? 'list'
            : 'month',
    );
    const [tzMode, setTzMode] = useState<TimezoneMode>('account');
    // Start inside the month the server loaded (its grid begins at most 6 days before the 1st).
    const [anchor, setAnchor] = useState<Date>(() =>
        addDays(parseLocalDate(loadedWindow.from), 15),
    );
    const [loadingWindow, setLoadingWindow] = useState(false);
    const visibleWindow = gridWindow(anchor);
    const [dragging, setDragging] = useState<Occurrence | null>(null);
    const [dropKey, setDropKey] = useState<string | null>(null);

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
            { ...filters, ...visibleWindow, [key]: value },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    // Step I-2.3: moving into another month fetches just that month's grid — a partial reload
    // that keeps the filters and the rest of the page as they are.
    useEffect(() => {
        if (
            visibleWindow.from === loadedWindow.from &&
            visibleWindow.to === loadedWindow.to
        ) {
            return;
        }

        router.get(
            baseUrl,
            { ...filters, ...visibleWindow },
            {
                only: ['occurrences', 'window', 'reschedulablePostIds'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setLoadingWindow(true),
                onFinish: () => setLoadingWindow(false),
            },
        );
        // Re-run only when the visible month changes, not on every render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [visibleWindow.from, visibleWindow.to]);

    const today = toDateKey(new Date());

    // Drag-to-reschedule: only the month/week grids have day cells to drop on, and a post can't
    // be dropped on its own day or into the past (the server re-checks the exact time).
    const dragEnabled =
        onReschedule !== undefined && (view === 'month' || view === 'week');

    const canDropOn = (key: string): boolean => {
        const from = dragging ? display(dragging).date : null;

        return from !== null && key !== from && key >= today;
    };

    const endDrag = () => {
        setDragging(null);
        setDropKey(null);
    };

    const dropZoneProps = (key: string) =>
        dragEnabled
            ? {
                  onDragOver: (event: DragEvent<HTMLDivElement>) => {
                      if (!canDropOn(key)) return;
                      event.preventDefault();
                      event.dataTransfer.dropEffect = 'move';
                      setDropKey(key);
                  },
                  onDragLeave: (event: DragEvent<HTMLDivElement>) => {
                      if (
                          !event.currentTarget.contains(
                              event.relatedTarget as Node | null,
                          )
                      ) {
                          setDropKey((current) =>
                              current === key ? null : current,
                          );
                      }
                  },
                  onDrop: (event: DragEvent<HTMLDivElement>) => {
                      event.preventDefault();
                      const occurrence = dragging;
                      const from = occurrence ? display(occurrence).date : null;
                      const allowed = canDropOn(key);
                      endDrag();
                      if (occurrence && from && allowed) {
                          onReschedule?.(occurrence, {
                              from,
                              to: key,
                              days: daysBetween(from, key),
                          });
                      }
                  },
              }
            : {};

    const dropZoneClass = (key: string) =>
        dropKey === key && 'ring-primary bg-primary/5 ring-2 ring-inset';

    const renderOccurrenceChip = (occurrence: Occurrence) => {
        const time = display(occurrence).time?.slice(0, 5);
        const tone = resolveStatus('post', occurrence.status).tone;
        const draggable = dragEnabled && (canReschedule?.(occurrence) ?? false);

        return (
            <Link
                key={occurrence.target_id}
                href={postHref(occurrence.post_id)}
                title={`${occurrence.status_label} — ${(multiClient ? occurrence.client_name : occurrence.master_caption) ?? ''}${draggable ? ' · drag to move' : ''}`}
                draggable={draggable}
                onDragStart={
                    draggable
                        ? (event: DragEvent<HTMLAnchorElement>) => {
                              event.dataTransfer.effectAllowed = 'move';
                              event.dataTransfer.setData(
                                  'text/plain',
                                  String(occurrence.post_id),
                              );
                              setDragging(occurrence);
                          }
                        : undefined
                }
                onDragEnd={draggable ? endDrag : undefined}
                className={cn(
                    'group/chip bg-card hover:border-foreground/20 relative flex min-w-0 flex-col gap-0.5 overflow-hidden rounded-lg border py-1.5 pr-2 pl-3 text-xs transition-colors',
                    draggable && 'cursor-grab active:cursor-grabbing',
                    // Every target of the dragged post moves together, so dim them all.
                    dragging?.post_id === occurrence.post_id && 'opacity-50',
                )}
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
            <h2 className="[&_em]:text-muted-foreground font-display text-3xl leading-none [&_em]:not-italic">
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
                                        {...dropZoneProps(key)}
                                        className={cn(
                                            'flex min-h-28 flex-col gap-1 p-1.5 transition-colors',
                                            // Opaque canvas, not a tint: a /50 tint over the
                                            // hairline grid's border colour read heavier than
                                            // the real days.
                                            isCurrentMonth
                                                ? 'bg-card'
                                                : 'bg-background',
                                            dropZoneClass(key),
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
                                    {...dropZoneProps(key)}
                                    className={cn(
                                        'bg-card flex min-h-72 flex-col gap-1.5 p-2 transition-colors',
                                        dropZoneClass(key),
                                    )}
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
        // Step I-2.3: the list pages by month, like the grid, instead of listing all history.
        const monthStart = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
        const monthPrefix = toDateKey(monthStart).slice(0, 7);
        const sortedDates = Array.from(occurrencesByDate.keys())
            .filter((key) => key.startsWith(monthPrefix))
            .sort();

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
                {sortedDates.length === 0 && (
                    <EmptyState
                        compact
                        icon={CalendarX2}
                        title="No posts this month."
                        description="Nothing matches the current filters in this month."
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
                                        'font-display text-3xl leading-none font-bold tabular-nums',
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
            {/* Two deliberate rows: views + timezone, then a ruled filter strip. */}
            <div className="flex flex-wrap items-center justify-between gap-3">
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

                <ToggleGroup
                    type="single"
                    variant="outline"
                    size="sm"
                    value={tzMode}
                    onValueChange={(value) =>
                        value && setTzMode(value as TimezoneMode)
                    }
                    aria-label="Timezone"
                >
                    <ToggleGroupItem value="account" className="px-3">
                        Account local
                    </ToggleGroupItem>
                    <ToggleGroupItem value="org" className="px-3">
                        {orgTimezone}
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            <div className="border-border flex flex-wrap items-center gap-2 border-y py-3">
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
            </div>

            <div
                aria-busy={loadingWindow}
                className={cn(
                    'bg-card animate-rise rounded-2xl border p-4 transition-opacity sm:p-5',
                    loadingWindow && 'opacity-60',
                )}
            >
                {view === 'month' && renderMonthView()}
                {view === 'week' && renderWeekView()}
                {view === 'day' && renderDayView()}
                {view === 'list' && renderListView()}
                {dragEnabled &&
                    canReschedule &&
                    occurrences.some(canReschedule) && (
                        <p className="text-muted-foreground pt-3 text-xs">
                            Drag a post to another day to move it — you’ll be
                            asked to confirm first.
                        </p>
                    )}
            </div>
        </div>
    );
}
