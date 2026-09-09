import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { calendar } from '@/routes';
import { edit as editPost } from '@/routes/posts';

type Option = { value: string; label: string };
type NamedOption = { id: number; name: string };

type OccurrenceDisplay = {
    date: string | null;
    time: string | null;
    timezone: string | null;
};

type Occurrence = {
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

type Filters = {
    client_id: number | null;
    campaign_id: number | null;
    status: string | null;
    platform: string | null;
};

type CalendarProps = {
    occurrences: Occurrence[];
    orgTimezone: string;
    filters: Filters;
    filterOptions: {
        clients: NamedOption[];
        campaigns: NamedOption[];
        statuses: Option[];
        platforms: Option[];
    };
};

type View = 'month' | 'week' | 'day' | 'list';
type TimezoneMode = 'account' | 'org';

const statusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline' | 'destructive'> = {
    idea: 'outline',
    draft: 'secondary',
    internal_review: 'secondary',
    waiting_client: 'warning',
    changes_requested: 'warning',
    approved: 'success',
    scheduled: 'success',
    publishing: 'warning',
    published: 'success',
    failed: 'destructive',
    archived: 'outline',
};

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

const monthLabelFormatter = new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' });
const weekdayFormatter = new Intl.DateTimeFormat('en-US', { weekday: 'short' });

export default function Calendar({ occurrences, orgTimezone, filters, filterOptions }: CalendarProps) {
    const [view, setView] = useState<View>('month');
    const [tzMode, setTzMode] = useState<TimezoneMode>('account');
    const [anchor, setAnchor] = useState<Date>(new Date());

    const display = (occurrence: Occurrence): OccurrenceDisplay =>
        tzMode === 'account' ? occurrence.account_local : occurrence.org_timezone;

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
            list.sort((a, b) => (display(a).time ?? '').localeCompare(display(b).time ?? ''));
        }
        return map;
    }, [occurrences, tzMode]);

    const applyFilter = (key: keyof Filters, value: string | null) => {
        router.get(
            calendar().url,
            { ...filters, [key]: value },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const renderOccurrenceChip = (occurrence: Occurrence) => {
        const { time } = display(occurrence);
        return (
            <Link
                key={occurrence.target_id}
                href={editPost(occurrence.post_id)}
                className="flex flex-col gap-0.5 rounded-md border border-border bg-card px-2 py-1 text-xs hover:bg-muted/50"
            >
                <div className="flex items-center justify-between gap-1">
                    <span className="font-medium">{time ?? '—'}</span>
                    <Badge variant={statusVariant[occurrence.status] ?? 'outline'} className="text-[10px]">
                        {occurrence.status_label}
                    </Badge>
                </div>
                <span className="truncate text-muted-foreground">
                    {occurrence.client_name} · {occurrence.handle ?? occurrence.platform ?? '—'}
                </span>
                {occurrence.master_caption && (
                    <span className="truncate">{occurrence.master_caption}</span>
                )}
            </Link>
        );
    };

    const renderMonthView = () => {
        const monthStart = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
        const gridStart = startOfWeek(monthStart);
        const days = Array.from({ length: 42 }, (_, i) => addDays(gridStart, i));

        return (
            <div className="flex flex-col gap-2">
                <div className="flex items-center justify-between">
                    <Button variant="outline" size="sm" onClick={() => setAnchor(addDays(monthStart, -1))}>
                        Previous
                    </Button>
                    <h2 className="text-sm font-semibold">{monthLabelFormatter.format(monthStart)}</h2>
                    <Button variant="outline" size="sm" onClick={() => setAnchor(addDays(monthStart, 32))}>
                        Next
                    </Button>
                </div>
                <div className="grid grid-cols-7 gap-1 text-center text-xs font-medium text-muted-foreground">
                    {days.slice(0, 7).map((day) => (
                        <div key={day.toISOString()}>{weekdayFormatter.format(day)}</div>
                    ))}
                </div>
                <div className="grid grid-cols-7 gap-1">
                    {days.map((day) => {
                        const key = toDateKey(day);
                        const dayOccurrences = occurrencesByDate.get(key) ?? [];
                        const isCurrentMonth = day.getMonth() === monthStart.getMonth();
                        return (
                            <div
                                key={key}
                                className={`flex min-h-24 flex-col gap-1 rounded-md border border-border p-1 ${
                                    isCurrentMonth ? 'bg-card' : 'bg-muted/30 text-muted-foreground'
                                }`}
                            >
                                <span className="text-[11px] font-medium">{day.getDate()}</span>
                                <div className="flex flex-col gap-1">
                                    {dayOccurrences.map(renderOccurrenceChip)}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>
        );
    };

    const renderWeekView = () => {
        const weekStart = startOfWeek(anchor);
        const days = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));

        return (
            <div className="flex flex-col gap-2">
                <div className="flex items-center justify-between">
                    <Button variant="outline" size="sm" onClick={() => setAnchor(addDays(weekStart, -7))}>
                        Previous week
                    </Button>
                    <h2 className="text-sm font-semibold">
                        {dateHeaderFormatter.format(weekStart)} – {dateHeaderFormatter.format(addDays(weekStart, 6))}
                    </h2>
                    <Button variant="outline" size="sm" onClick={() => setAnchor(addDays(weekStart, 7))}>
                        Next week
                    </Button>
                </div>
                <div className="grid grid-cols-7 gap-1">
                    {days.map((day) => {
                        const key = toDateKey(day);
                        const dayOccurrences = occurrencesByDate.get(key) ?? [];
                        return (
                            <div key={key} className="flex min-h-40 flex-col gap-1 rounded-md border border-border bg-card p-1">
                                <span className="text-[11px] font-medium">{weekdayFormatter.format(day)} {day.getDate()}</span>
                                <div className="flex flex-col gap-1">
                                    {dayOccurrences.map(renderOccurrenceChip)}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>
        );
    };

    const renderDayView = () => {
        const key = toDateKey(anchor);
        const dayOccurrences = occurrencesByDate.get(key) ?? [];

        return (
            <div className="flex flex-col gap-2">
                <div className="flex items-center justify-between">
                    <Button variant="outline" size="sm" onClick={() => setAnchor(addDays(anchor, -1))}>
                        Previous day
                    </Button>
                    <h2 className="text-sm font-semibold">{dateHeaderFormatter.format(anchor)}</h2>
                    <Button variant="outline" size="sm" onClick={() => setAnchor(addDays(anchor, 1))}>
                        Next day
                    </Button>
                </div>
                <div className="flex flex-col gap-1">
                    {dayOccurrences.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No posts scheduled for this day.</p>
                    ) : (
                        dayOccurrences.map(renderOccurrenceChip)
                    )}
                </div>
            </div>
        );
    };

    const renderListView = () => {
        const sortedDates = Array.from(occurrencesByDate.keys()).sort();

        return (
            <div className="flex flex-col gap-4">
                {sortedDates.length === 0 && (
                    <p className="text-sm text-muted-foreground">No posts match the current filters.</p>
                )}
                {sortedDates.map((dateKey) => (
                    <div key={dateKey} className="flex flex-col gap-2">
                        <h3 className="text-sm font-semibold">{dateHeaderFormatter.format(parseLocalDate(dateKey))}</h3>
                        <div className="flex flex-col gap-1">
                            {(occurrencesByDate.get(dateKey) ?? []).map(renderOccurrenceChip)}
                        </div>
                    </div>
                ))}
            </div>
        );
    };

    return (
        <>
            <Head title="Calendar" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="relative overflow-hidden rounded-xl border border-border p-6 shadow-lg shadow-primary/10">
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 -z-10 bg-gradient-brand opacity-[0.14]"
                    />
                    <div className="flex flex-col gap-1">
                        <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                            <span className="text-gradient-brand">Content calendar</span>
                        </h1>
                        <p className="max-w-xl text-sm text-muted-foreground">
                            Every scheduled post's targets, filterable and viewable by month, week, day, or list.
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap items-center justify-end gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={tzMode}
                            onValueChange={(value) => value && setTzMode(value as TimezoneMode)}
                        >
                            <ToggleGroupItem value="account">Each account local</ToggleGroupItem>
                            <ToggleGroupItem value="org">My timezone ({orgTimezone})</ToggleGroupItem>
                        </ToggleGroup>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={view}
                            onValueChange={(value) => value && setView(value as View)}
                        >
                            <ToggleGroupItem value="month">Month</ToggleGroupItem>
                            <ToggleGroupItem value="week">Week</ToggleGroupItem>
                            <ToggleGroupItem value="day">Day</ToggleGroupItem>
                            <ToggleGroupItem value="list">List</ToggleGroupItem>
                        </ToggleGroup>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        value={filters.client_id ? String(filters.client_id) : 'all'}
                        onValueChange={(value) => applyFilter('client_id', value === 'all' ? null : value)}
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="Client" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All clients</SelectItem>
                            {filterOptions.clients.map((client) => (
                                <SelectItem key={client.id} value={String(client.id)}>
                                    {client.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.campaign_id ? String(filters.campaign_id) : 'all'}
                        onValueChange={(value) => applyFilter('campaign_id', value === 'all' ? null : value)}
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="Campaign" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All campaigns</SelectItem>
                            {filterOptions.campaigns.map((campaign) => (
                                <SelectItem key={campaign.id} value={String(campaign.id)}>
                                    {campaign.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.status ?? 'all'}
                        onValueChange={(value) => applyFilter('status', value === 'all' ? null : value)}
                    >
                        <SelectTrigger className="w-44">
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
                        onValueChange={(value) => applyFilter('platform', value === 'all' ? null : value)}
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="Platform" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All platforms</SelectItem>
                            {filterOptions.platforms.map((platform) => (
                                <SelectItem key={platform.value} value={platform.value}>
                                    {platform.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
                    {view === 'month' && renderMonthView()}
                    {view === 'week' && renderWeekView()}
                    {view === 'day' && renderDayView()}
                    {view === 'list' && renderListView()}
                </div>
            </div>
        </>
    );
}

Calendar.layout = {
    breadcrumbs: [
        {
            title: 'Calendar',
            href: calendar(),
        },
    ],
};
