import type { InertiaLinkProps } from '@inertiajs/react';
import { Link, router } from '@inertiajs/react';
import {
    DndContext,
    DragOverlay,
    PointerSensor,
    TouchSensor,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
    type DragEndEvent,
    type DragStartEvent,
} from '@dnd-kit/core';
import {
    CalendarX2,
    ChevronLeft,
    ChevronRight,
    Grid3x3,
    Plus,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type {
    CalendarGhost,
    CampaignLayer,
} from '@/components/campaigns/calendar-layer';
import {
    CampaignDayBands,
    GhostChip,
    useCampaignLayerToggle,
} from '@/components/campaigns/calendar-layer';
import { CampaignSwatch } from '@/components/campaigns/campaign-chip';
import { ReschedulePopover } from '@/components/calendar/reschedule-popover';
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
    /** Campaigns enhancement C-1.3: the campaign chip. */
    campaign_code?: string | null;
    campaign_colour?: string | null;
    /** Step 18.11: the grid chip, when the post was made from a grid tile. */
    grid_plan_id?: number | null;
    grid_tile_position?: number | null;
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
    /** Step 18.11: "Show this grid" (internal calendar only). */
    grid_id?: number | null;
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
        /** Step 18.11: grids with posts (internal calendar only). */
        grids?: NamedOption[];
    };
    /** Posts the viewer may drag to another day — internal calendar only. */
    reschedulablePostIds?: number[];
    /** Campaigns enhancement C-3.5 — bands + ghost deliverables, internal calendar only. */
    campaignLayer?: CampaignLayer;
};

/** A whole-post move (month view / month popover): every channel shifts by the same days. */
export type PostMove = { from: string; to: string; days: number };
/** A single-channel move (week/day time grid): one target lands on an exact day + time. */
export type TargetMove = { date: string; time: string | null };

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
    /** Whether an occurrence's chip can be moved (month: whole post; week/day: that channel). */
    canReschedule?: (occurrence: Occurrence) => boolean;
    /**
     * Month view: the whole post shifts by whole days. `days` is the shift from the chip's
     * displayed date; the caller confirms and persists.
     */
    onReschedule?: (occurrence: Occurrence, move: PostMove) => void;
    /**
     * Week/day time grid: a single channel moves to an exact day + time, leaving its siblings
     * where they are. The caller confirms and persists.
     */
    onRescheduleTarget?: (occurrence: Occurrence, move: TargetMove) => void;
    /**
     * "Click an empty slot to create a post there": month cells pass a date, the time grid passes
     * a date + `HH:00`. With no handler, empty slots offer nothing.
     */
    onCreateAt?: (slot: TargetMove) => void;
};

type View = 'month' | 'week' | 'day' | 'list';
type TimezoneMode = 'account' | 'org';

/** The hours shown in the week/day time grid (a row each). */
const GRID_HOURS = Array.from({ length: 24 }, (_, hour) => hour);
/** Scroll the time grid here on open, so the working day is in view without hunting. */
const GRID_SCROLL_HOUR = 7;

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

/** The hour (0–23) of an `HH:MM` display time, or `null` for an untimed target. */
function hourOf(time: string | null): number | null {
    if (!time) return null;
    const hour = Number(time.slice(0, 2));
    return Number.isFinite(hour) ? hour : null;
}

const hourFormatter = new Intl.DateTimeFormat('en-US', {
    hour: 'numeric',
    hour12: true,
});

function hourLabel(hour: number): string {
    return hourFormatter.format(new Date(2026, 0, 1, hour));
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
const monthTitleFormatter = new Intl.DateTimeFormat('en-US', { month: 'long' });

/** A droppable `day:` (month) or `slot:` (time grid) id. */
function dayDropId(key: string): string {
    return `day:${key}`;
}
function slotDropId(key: string, hour: number): string {
    return `slot:${key}:${hour}`;
}

/**
 * The content calendar (month / week / day / list, account-local vs org timezone, filters) used
 * by both the internal staff calendar and the client portal calendar. All timezone maths happens
 * server-side (`BuildCalendarOccurrencesAction`); this only groups and renders.
 *
 * Step M-5.7 / I-5.1: drag-and-drop runs on `@dnd-kit` (pointer + touch), and every movable chip
 * also carries a keyboard-accessible "Reschedule" popover. The week and day views are real time
 * grids, so a single channel can be dropped on an exact hour; the month view still shifts the
 * whole post by whole days. Empty slots offer "New post" here.
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
    onRescheduleTarget,
    onCreateAt,
    campaignLayer,
}: ContentCalendarProps) {
    const [showCampaigns, setShowCampaigns] = useCampaignLayerToggle();
    const bands = showCampaigns ? (campaignLayer?.bands ?? []) : [];
    const ghostsByDate = useMemo(() => {
        const map = new Map<string, CalendarGhost[]>();

        if (!showCampaigns) {
            return map;
        }

        for (const ghost of campaignLayer?.ghosts ?? []) {
            if (ghost.date) {
                map.set(ghost.date, [...(map.get(ghost.date) ?? []), ghost]);
            }
        }

        return map;
    }, [campaignLayer, showCampaigns]);
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

    const today = toDateKey(new Date());

    const display = (occurrence: Occurrence): OccurrenceDisplay =>
        tzMode === 'account'
            ? occurrence.account_local
            : occurrence.org_timezone;

    const occurrencesByDate = useMemo(() => {
        const map = new Map<string, Occurrence[]>();
        for (const occurrence of occurrences) {
            const date =
                tzMode === 'account'
                    ? occurrence.account_local.date
                    : occurrence.org_timezone.date;
            if (!date) continue;
            const list = map.get(date) ?? [];
            list.push(occurrence);
            map.set(date, list);
        }
        const timeOf = (occurrence: Occurrence) =>
            (tzMode === 'account'
                ? occurrence.account_local.time
                : occurrence.org_timezone.time) ?? '';
        for (const list of map.values()) {
            list.sort((a, b) => timeOf(a).localeCompare(timeOf(b)));
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
                only: [
                    'occurrences',
                    'window',
                    'reschedulablePostIds',
                    'campaignLayer',
                ],
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

    // A click/tap that doesn't travel 8px stays a link click; past that it's a drag. Touch waits
    // a beat so the page can still be scrolled with a finger.
    const sensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: { distance: 8 },
        }),
        useSensor(TouchSensor, {
            activationConstraint: { delay: 220, tolerance: 6 },
        }),
    );

    const movable = (occurrence: Occurrence): boolean =>
        canReschedule?.(occurrence) ?? false;
    const isTimeGrid = view === 'week' || view === 'day';
    // A move either shifts the whole post by days (month) or drops one channel on a slot (grid).
    const dragEnabled =
        (view === 'month' && onReschedule !== undefined) ||
        (isTimeGrid && onRescheduleTarget !== undefined);

    const handleDragStart = (event: DragStartEvent) => {
        setDragging(
            (event.active.data.current?.occurrence as Occurrence) ?? null,
        );
    };

    const handleDragEnd = (event: DragEndEvent) => {
        const occurrence = event.active.data.current?.occurrence as
            | Occurrence
            | undefined;
        const overId = event.over?.id;
        setDragging(null);
        if (!occurrence || typeof overId !== 'string') return;

        const from = display(occurrence).date;
        if (!from) return;

        if (overId.startsWith('day:')) {
            const to = overId.slice(4);
            if (to === from || to < today) return;
            onReschedule?.(occurrence, {
                from,
                to,
                days: daysBetween(from, to),
            });
            return;
        }

        if (overId.startsWith('slot:')) {
            const [, date, hourStr] = overId.split(':');
            if (date < today) return;
            const time = `${String(Number(hourStr)).padStart(2, '0')}:00`;
            // Dropping on the exact same slot is a no-op.
            if (
                date === from &&
                hourOf(display(occurrence).time) === Number(hourStr)
            ) {
                return;
            }
            onRescheduleTarget?.(occurrence, { date, time });
        }
    };

    const chipProps = {
        display: (o: Occurrence) => display(o),
        postHref,
        multiClient,
        today,
        view,
        movable,
        draggingPostId: dragging?.post_id ?? null,
        onReschedule,
        onRescheduleTarget,
    } as const;

    const renderOccurrenceChip = (occurrence: Occurrence) => (
        <CalendarChip
            key={occurrence.target_id}
            occurrence={occurrence}
            {...chipProps}
        />
    );

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
                                    <MonthDayCell
                                        key={key}
                                        dateKey={key}
                                        dropEnabled={dragEnabled}
                                        isCurrentMonth={isCurrentMonth}
                                        isPast={key < today}
                                        canCreate={onCreateAt !== undefined}
                                        onCreate={() =>
                                            onCreateAt?.({
                                                date: key,
                                                time: null,
                                            })
                                        }
                                    >
                                        {renderDayNumber(day, !isCurrentMonth)}
                                        <CampaignDayBands
                                            bands={bands}
                                            day={key}
                                            labelled={day.getDay() === 0}
                                        />
                                        <div className="flex flex-col gap-1">
                                            {dayOccurrences.map(
                                                renderOccurrenceChip,
                                            )}
                                            {(ghostsByDate.get(key) ?? []).map(
                                                (ghost) => (
                                                    <GhostChip
                                                        key={`g-${ghost.id}`}
                                                        ghost={ghost}
                                                    />
                                                ),
                                            )}
                                        </div>
                                    </MonthDayCell>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </div>
        );
    };

    const renderTimeGrid = (days: Date[]) => (
        <TimeGrid
            days={days}
            today={today}
            bands={bands}
            ghostsByDate={ghostsByDate}
            occurrencesByDate={occurrencesByDate}
            displayTime={(o) => display(o).time}
            renderChip={renderOccurrenceChip}
            dropEnabled={dragEnabled}
            canCreate={onCreateAt !== undefined}
            onCreate={(date, hour) =>
                onCreateAt?.({
                    date,
                    time: `${String(hour).padStart(2, '0')}:00`,
                })
            }
        />
    );

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
                {renderTimeGrid(days)}
            </div>
        );
    };

    const renderDayView = () => (
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
            {renderTimeGrid([anchor])}
        </div>
    );

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

                {campaignLayer && (
                    <ToggleGroup
                        type="multiple"
                        variant="outline"
                        size="sm"
                        value={showCampaigns ? ['campaigns'] : []}
                        onValueChange={(value) =>
                            setShowCampaigns(value.includes('campaigns'))
                        }
                        aria-label="Layers"
                    >
                        <ToggleGroupItem value="campaigns" className="px-3">
                            Campaigns & plan
                        </ToggleGroupItem>
                    </ToggleGroup>
                )}
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

                {filterOptions.grids && filterOptions.grids.length > 0 && (
                    <Select
                        value={
                            filters.grid_id ? String(filters.grid_id) : 'all'
                        }
                        onValueChange={(value) =>
                            applyFilter(
                                'grid_id',
                                value === 'all' ? null : value,
                            )
                        }
                    >
                        <SelectTrigger size="sm" className="w-40">
                            <SelectValue placeholder="Grid" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All posts</SelectItem>
                            {filterOptions.grids.map((grid) => (
                                <SelectItem
                                    key={grid.id}
                                    value={String(grid.id)}
                                >
                                    Show grid: {grid.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}

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
                <DndContext
                    sensors={sensors}
                    onDragStart={handleDragStart}
                    onDragEnd={handleDragEnd}
                    onDragCancel={() => setDragging(null)}
                >
                    {view === 'month' && renderMonthView()}
                    {view === 'week' && renderWeekView()}
                    {view === 'day' && renderDayView()}
                    {view === 'list' && renderListView()}
                    <DragOverlay dropAnimation={null}>
                        {dragging ? (
                            <ChipBody
                                occurrence={dragging}
                                time={display(dragging).time}
                                multiClient={multiClient}
                                className="shadow-lg"
                            />
                        ) : null}
                    </DragOverlay>
                </DndContext>
                {dragEnabled &&
                    canReschedule &&
                    occurrences.some(canReschedule) && (
                        <p className="text-muted-foreground pt-3 text-xs">
                            {isTimeGrid
                                ? 'Drag a post to another day or hour, or use the clock button on it. One channel moves at a time.'
                                : 'Drag a post to another day, or use the clock button on it. The whole post moves together.'}
                        </p>
                    )}
            </div>
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/* Chips                                                                       */
/* -------------------------------------------------------------------------- */

/** The visual body of an occurrence chip — shared by the live chip and the drag overlay. */
function ChipBody({
    occurrence,
    time,
    multiClient,
    className,
}: {
    occurrence: Occurrence;
    time: string | null;
    multiClient: boolean;
    className?: string;
}) {
    const tone = resolveStatus('post', occurrence.status).tone;

    return (
        <div
            className={cn(
                'bg-card relative flex min-w-0 flex-1 flex-col gap-0.5 overflow-hidden rounded-lg border py-1.5 pr-2 pl-3 text-xs',
                className,
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
                    {time?.slice(0, 5) ?? '—'}
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
            <span className="truncate font-medium">
                {multiClient
                    ? occurrence.client_name
                    : (occurrence.master_caption ?? 'Untitled post')}
            </span>
            <span className="text-muted-foreground flex min-w-0 items-center gap-1.5">
                {occurrence.grid_plan_id && (
                    <span
                        className="inline-flex shrink-0 items-center gap-0.5 font-mono text-[10px]"
                        title={`From a grid · tile ${occurrence.grid_tile_position}`}
                    >
                        <Grid3x3 className="size-2.5" aria-hidden />
                        <span className="sr-only">Grid tile</span>
                        {occurrence.grid_tile_position}
                    </span>
                )}
                {occurrence.campaign_id !== null && (
                    <span
                        className="inline-flex shrink-0 items-center gap-1"
                        title={occurrence.campaign_name ?? undefined}
                    >
                        <CampaignSwatch
                            colour={occurrence.campaign_colour}
                            className="size-2"
                        />
                        {occurrence.campaign_code && (
                            <span className="font-mono text-[10px]">
                                {occurrence.campaign_code}
                            </span>
                        )}
                    </span>
                )}
                <span className="truncate">
                    {occurrence.handle ?? occurrence.platform ?? '—'}
                    {multiClient && occurrence.master_caption
                        ? ` · ${occurrence.master_caption}`
                        : ''}
                </span>
            </span>
        </div>
    );
}

type ChipSharedProps = {
    display: (o: Occurrence) => OccurrenceDisplay;
    postHref: (postId: number) => NonNullable<InertiaLinkProps['href']>;
    multiClient: boolean;
    today: string;
    view: View;
    movable: (o: Occurrence) => boolean;
    draggingPostId: number | null;
    onReschedule?: (occurrence: Occurrence, move: PostMove) => void;
    onRescheduleTarget?: (occurrence: Occurrence, move: TargetMove) => void;
};

/**
 * One occurrence chip: a link to the post, draggable by pointer/touch (`@dnd-kit`), with a
 * keyboard-accessible reschedule popover. The month view moves the whole post by whole days; the
 * week/day time grid moves this one channel to an exact slot.
 */
function CalendarChip({
    occurrence,
    display,
    postHref,
    multiClient,
    today,
    view,
    movable,
    draggingPostId,
    onReschedule,
    onRescheduleTarget,
}: ChipSharedProps & { occurrence: Occurrence }) {
    const canMove = movable(occurrence);
    const slot = display(occurrence);
    const isTimeGrid = view === 'week' || view === 'day';

    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        isDragging,
    } = useDraggable({
        id: `occ:${occurrence.target_id}`,
        data: { occurrence },
        disabled: !canMove || view === 'list',
    });

    const movePost = (move: TargetMove) => {
        if (!slot.date) return;
        onReschedule?.(occurrence, {
            from: slot.date,
            to: move.date,
            days: daysBetween(slot.date, move.date),
        });
    };

    const title = `${occurrence.status_label} — ${
        (multiClient ? occurrence.client_name : occurrence.master_caption) ?? ''
    }${canMove ? ' · drag to move' : ''}`;

    return (
        <div
            ref={setNodeRef}
            className={cn(
                'group/chip hover:border-foreground/20 relative flex min-w-0 items-stretch gap-1 rounded-lg transition-colors',
                // Every target of the dragged post dims together.
                draggingPostId === occurrence.post_id && 'opacity-50',
                isDragging && 'opacity-40',
            )}
        >
            <Link
                ref={canMove ? setActivatorNodeRef : undefined}
                href={postHref(occurrence.post_id)}
                title={title}
                {...(canMove ? listeners : {})}
                {...(canMove ? attributes : {})}
                // dnd-kit sets role=button on the activator; keep it a link for the a11y tree.
                role={undefined}
                className={cn(
                    'focus-visible:ring-primary min-w-0 flex-1 rounded-lg outline-none focus-visible:ring-2',
                    canMove && 'cursor-grab touch-none active:cursor-grabbing',
                )}
            >
                <ChipBody
                    occurrence={occurrence}
                    time={slot.time}
                    multiClient={multiClient}
                />
            </Link>
            {canMove && (
                <div className="absolute top-1 right-1">
                    <ReschedulePopover
                        label={`Reschedule — ${
                            (multiClient
                                ? occurrence.client_name
                                : occurrence.master_caption) ?? 'post'
                        }`}
                        withTime={isTimeGrid}
                        defaultDate={slot.date}
                        defaultTime={slot.time}
                        minDate={today}
                        onSubmit={(move) =>
                            isTimeGrid
                                ? onRescheduleTarget?.(occurrence, move)
                                : movePost(move)
                        }
                    />
                </div>
            )}
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/* Drop cells                                                                  */
/* -------------------------------------------------------------------------- */

/** A month day cell: a drop target for whole-post day moves, with a hover "New post" button. */
function MonthDayCell({
    dateKey,
    dropEnabled,
    isCurrentMonth,
    isPast,
    canCreate,
    onCreate,
    children,
}: {
    dateKey: string;
    dropEnabled: boolean;
    isCurrentMonth: boolean;
    isPast: boolean;
    canCreate: boolean;
    onCreate: () => void;
    children: ReactNode;
}) {
    const { setNodeRef, isOver, active } = useDroppable({
        id: dayDropId(dateKey),
        disabled: !dropEnabled,
    });
    // Only glow for a drop that would actually land (not the chip's own past day).
    const canDrop = active !== null && !isPast;

    return (
        <div
            ref={setNodeRef}
            className={cn(
                'group/cell relative flex min-h-28 flex-col gap-1 p-1.5 transition-colors',
                isCurrentMonth ? 'bg-card' : 'bg-background',
                isOver &&
                    canDrop &&
                    'ring-primary bg-primary/5 ring-2 ring-inset',
            )}
        >
            {children}
            {canCreate && !isPast && (
                <button
                    type="button"
                    aria-label={`New post on ${dateKey}`}
                    onClick={onCreate}
                    className="text-muted-foreground hover:bg-primary/10 hover:text-primary absolute top-1.5 right-1.5 flex size-6 items-center justify-center rounded-md opacity-0 transition-opacity group-focus-within/cell:opacity-100 group-hover/cell:opacity-100 focus-visible:opacity-100"
                >
                    <Plus className="size-4" />
                </button>
            )}
        </div>
    );
}

/** One (day, hour) cell in the week/day time grid — a drop target with a hover "New post". */
function TimeSlotCell({
    dateKey,
    hour,
    dropEnabled,
    isPast,
    canCreate,
    onCreate,
    children,
}: {
    dateKey: string;
    hour: number;
    dropEnabled: boolean;
    isPast: boolean;
    canCreate: boolean;
    onCreate: () => void;
    children: ReactNode;
}) {
    const { setNodeRef, isOver, active } = useDroppable({
        id: slotDropId(dateKey, hour),
        disabled: !dropEnabled,
    });
    const canDrop = active !== null && !isPast;

    return (
        <div
            ref={setNodeRef}
            className={cn(
                'group/slot relative flex min-h-12 flex-col gap-1 border-t p-1',
                isOver &&
                    canDrop &&
                    'bg-primary/5 ring-primary ring-2 ring-inset',
            )}
        >
            {children}
            {canCreate && !isPast && (
                <button
                    type="button"
                    aria-label={`New post at ${hourLabel(hour)} on ${dateKey}`}
                    onClick={onCreate}
                    className="text-muted-foreground hover:bg-primary/10 hover:text-primary absolute top-1 right-1 flex size-5 items-center justify-center rounded-md opacity-0 transition-opacity group-focus-within/slot:opacity-100 group-hover/slot:opacity-100 focus-visible:opacity-100"
                >
                    <Plus className="size-3.5" />
                </button>
            )}
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/* Time grid (week / day)                                                      */
/* -------------------------------------------------------------------------- */

function TimeGrid({
    days,
    today,
    bands,
    ghostsByDate,
    occurrencesByDate,
    displayTime,
    renderChip,
    dropEnabled,
    canCreate,
    onCreate,
}: {
    days: Date[];
    today: string;
    bands: CampaignLayer['bands'];
    ghostsByDate: Map<string, CalendarGhost[]>;
    occurrencesByDate: Map<string, Occurrence[]>;
    displayTime: (o: Occurrence) => string | null;
    renderChip: (occurrence: Occurrence) => ReactNode;
    dropEnabled: boolean;
    canCreate: boolean;
    onCreate: (date: string, hour: number) => void;
}) {
    const scrollRef = useRef<HTMLDivElement>(null);
    const columns = days.length;

    // Open with the working day in view rather than at midnight.
    useEffect(() => {
        const node = scrollRef.current;
        if (!node) return;
        const row = node.querySelector<HTMLElement>(
            `[data-hour="${GRID_SCROLL_HOUR}"]`,
        );
        if (row) node.scrollTop = row.offsetTop;
    }, []);

    // Untimed targets (a date but no time) can't sit on an hour row, so they get an "All day" rail.
    const untimedByDate = (key: string) =>
        (occurrencesByDate.get(key) ?? []).filter(
            (o) => hourOf(displayTime(o)) === null,
        );
    const timedAt = (key: string, hour: number) =>
        (occurrencesByDate.get(key) ?? []).filter(
            (o) => hourOf(displayTime(o)) === hour,
        );

    const hasUntimed = days.some(
        (day) => untimedByDate(toDateKey(day)).length > 0,
    );

    return (
        <div className="overflow-hidden rounded-xl border">
            {/* Day headers */}
            <div
                className="bg-muted/40 grid border-b"
                style={{ gridTemplateColumns: `4rem repeat(${columns}, 1fr)` }}
            >
                <div aria-hidden className="border-r" />
                {days.map((day) => {
                    const key = toDateKey(day);
                    return (
                        <div
                            key={key}
                            className="flex items-center justify-between gap-1 px-2 py-2"
                        >
                            <span className="eyebrow text-muted-foreground">
                                {weekdayFormatter.format(day)}
                            </span>
                            <span
                                className={cn(
                                    'flex size-7 items-center justify-center rounded-full font-mono text-[11px] tabular-nums',
                                    key === today &&
                                        'bg-primary text-primary-foreground font-medium',
                                )}
                            >
                                {day.getDate()}
                            </span>
                        </div>
                    );
                })}
            </div>

            {/* All-day / untimed rail and campaign bands */}
            {(hasUntimed || bands.length > 0) && (
                <div
                    className="bg-card grid border-b"
                    style={{
                        gridTemplateColumns: `4rem repeat(${columns}, 1fr)`,
                    }}
                >
                    <div className="text-muted-foreground border-r px-2 py-1 text-[10px] uppercase">
                        All day
                    </div>
                    {days.map((day) => {
                        const key = toDateKey(day);
                        return (
                            <div key={key} className="flex flex-col gap-1 p-1">
                                <CampaignDayBands
                                    bands={bands}
                                    day={key}
                                    labelled
                                />
                                {untimedByDate(key).map(renderChip)}
                                {(ghostsByDate.get(key) ?? []).map((ghost) => (
                                    <GhostChip
                                        key={`g-${ghost.id}`}
                                        ghost={ghost}
                                    />
                                ))}
                            </div>
                        );
                    })}
                </div>
            )}

            {/* Hour rows */}
            <div ref={scrollRef} className="max-h-[32rem] overflow-y-auto">
                {GRID_HOURS.map((hour) => (
                    <div
                        key={hour}
                        data-hour={hour}
                        className="grid"
                        style={{
                            gridTemplateColumns: `4rem repeat(${columns}, 1fr)`,
                        }}
                    >
                        <div className="text-muted-foreground border-t border-r px-2 py-1 text-right font-mono text-[10px]">
                            {hourLabel(hour)}
                        </div>
                        {days.map((day) => {
                            const key = toDateKey(day);
                            return (
                                <TimeSlotCell
                                    key={key}
                                    dateKey={key}
                                    hour={hour}
                                    dropEnabled={dropEnabled}
                                    isPast={key < today}
                                    canCreate={canCreate}
                                    onCreate={() => onCreate(key, hour)}
                                >
                                    {timedAt(key, hour).map(renderChip)}
                                </TimeSlotCell>
                            );
                        })}
                    </div>
                ))}
            </div>
        </div>
    );
}
