import { Head, Link, router } from '@inertiajs/react';
import { Clock, Inbox, Search, Sparkles } from 'lucide-react';
import { useState } from 'react';
import {
    EmptyState,
    Page,
    PageHeader,
    Panel,
    Stat,
    StatStrip,
} from '@/components/page';
import { Pagination, type Paginated } from '@/components/pagination';
import { StatusPill } from '@/components/status-pill';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate, formatDateTime } from '@/lib/dates';
import { index, show } from '@/routes/super-admin/registrations';

type RegistrationRow = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    company_name: string;
    location: string | null;
    country: string | null;
    status: string;
    source: string;
    demo_at: string | null;
    created_at: string | null;
};

type Count = { label: string; count: number };

type Stats = {
    total: number;
    last7Days: number;
    last30Days: number;
    previous30Days: number;
    awaitingContact: number;
    demoRate: number | null;
    onboardedRate: number | null;
    avgHoursToContact: number | null;
    byStatus: (Count & { value: string })[];
    topCountries: Count[];
    topSources: Count[];
    daily: { date: string; count: number }[];
};

type Props = {
    registrations: Paginated<RegistrationRow>;
    stats: Stats;
    filters: { status: string | null; q: string };
    statuses: { value: string; label: string }[];
};

const ALL = 'all';

function BarList({ items, empty }: { items: Count[]; empty: string }) {
    const max = Math.max(1, ...items.map((item) => item.count));

    if (items.length === 0) {
        return <p className="text-muted-foreground text-sm">{empty}</p>;
    }

    return (
        <ul className="flex flex-col gap-3">
            {items.map((item) => (
                <li key={item.label} className="flex flex-col gap-1.5">
                    <div className="flex items-baseline justify-between gap-3 text-sm">
                        <span className="truncate">{item.label}</span>
                        <span className="font-mono text-xs tabular-nums">
                            {item.count}
                        </span>
                    </div>
                    <div className="bg-muted h-2 overflow-hidden rounded-full">
                        <div
                            className="bg-primary h-full rounded-full"
                            style={{ width: `${(item.count / max) * 100}%` }}
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}

function DailyTrend({ daily }: { daily: Stats['daily'] }) {
    const max = Math.max(1, ...daily.map((day) => day.count));

    return (
        <div className="flex flex-col gap-2">
            <div
                className="flex h-28 items-end gap-1"
                role="img"
                aria-label={`Registrations per day over the last ${daily.length} days`}
            >
                {daily.map((day) => (
                    <div
                        key={day.date}
                        title={`${formatDate(day.date)}: ${day.count}`}
                        className="bg-primary/85 min-h-[2px] flex-1 rounded-t-sm"
                        style={{ height: `${(day.count / max) * 100}%` }}
                    />
                ))}
            </div>
            <div className="text-muted-foreground flex justify-between font-mono text-[11px]">
                <span>{formatDate(daily[0]?.date)}</span>
                <span>Today</span>
            </div>
        </div>
    );
}

function trendCaption(current: number, previous: number): string {
    if (previous === 0) {
        return current === 0 ? 'Quiet so far' : 'Up from none';
    }

    const change = Math.round(((current - previous) / previous) * 100);

    return `${change >= 0 ? '+' : ''}${change}% vs previous 30 days`;
}

/**
 * The register-interest pipeline: who wants in, how fast we reply, and where they come from.
 * Super admins contact them, book a demo and onboard them by hand from the detail page.
 */
export default function RegistrationsIndex({
    registrations,
    stats,
    filters,
    statuses,
}: Props) {
    const [search, setSearch] = useState(filters.q);

    const visit = (query: Record<string, string | number | null>) => {
        const merged = { status: filters.status, q: filters.q, ...query };

        router.get(
            index.url({
                query: Object.fromEntries(
                    Object.entries(merged).filter(([, value]) => value),
                ),
            }),
            undefined,
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Registrations" />
            <Page>
                <PageHeader
                    eyebrow={`${stats.total} ${stats.total === 1 ? 'registration' : 'registrations'}`}
                    title={
                        <>
                            Who wants <em>in</em>.
                        </>
                    }
                    description="Everyone who registered interest. Reply within 24 hours, book a demo, then onboard them from Organizations."
                />

                <StatStrip
                    items={[
                        {
                            label: 'Last 30 days',
                            value: stats.last30Days,
                            caption: trendCaption(
                                stats.last30Days,
                                stats.previous30Days,
                            ),
                        },
                        {
                            label: 'Last 7 days',
                            value: stats.last7Days,
                            caption: `${stats.total} all time`,
                        },
                        {
                            label: 'Awaiting contact',
                            value: stats.awaitingContact,
                            caption: 'Still marked new',
                            render: (cell) => (
                                <Link
                                    href={index.url({
                                        query: { status: 'new' },
                                    })}
                                    className="block h-full hover:opacity-80"
                                >
                                    {cell}
                                </Link>
                            ),
                        },
                        {
                            label: 'Demo rate',
                            value:
                                stats.demoRate === null
                                    ? '—'
                                    : `${stats.demoRate}%`,
                            caption: 'Booked a demo or onboarded',
                        },
                    ]}
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    <Stat
                        tone="ink"
                        icon={Clock}
                        label="Time to first contact"
                        value={
                            stats.avgHoursToContact === null
                                ? '—'
                                : `${stats.avgHoursToContact}h`
                        }
                        hint="Average from registering to leaving “new”"
                    />
                    <Stat
                        icon={Sparkles}
                        label="Onboarded"
                        value={
                            stats.onboardedRate === null
                                ? '—'
                                : `${stats.onboardedRate}%`
                        }
                        hint="Of every registration so far"
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel title="Last 30 days" eyebrow="Per day">
                        <DailyTrend daily={stats.daily} />
                    </Panel>
                    <Panel title="Pipeline" eyebrow="By status">
                        <BarList items={stats.byStatus} empty="Nothing yet." />
                    </Panel>
                    <Panel title="Where they come from" eyebrow="Source">
                        <BarList
                            items={stats.topSources}
                            empty="No sources yet."
                        />
                    </Panel>
                    <Panel title="Where they are" eyebrow="Country, from IP">
                        <BarList
                            items={stats.topCountries}
                            empty="No countries yet."
                        />
                    </Panel>
                </div>

                <Panel
                    title="Registrations"
                    actions={
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    visit({ q: search, page: null });
                                }}
                                className="relative"
                            >
                                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                                <Input
                                    type="search"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Name, email or company"
                                    aria-label="Search registrations"
                                    className="w-full pl-9 sm:w-56"
                                />
                            </form>
                            <Select
                                value={filters.status ?? ALL}
                                onValueChange={(value) =>
                                    visit({
                                        status: value === ALL ? null : value,
                                        page: null,
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="w-full sm:w-44"
                                    aria-label="Filter by status"
                                >
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        All statuses
                                    </SelectItem>
                                    {statuses.map((status) => (
                                        <SelectItem
                                            key={status.value}
                                            value={status.value}
                                        >
                                            {status.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    }
                    bodyClassName="p-0 sm:p-0"
                >
                    {registrations.data.length === 0 ? (
                        <EmptyState
                            compact
                            icon={Inbox}
                            title="No registrations here."
                            description="When someone registers interest on the website, they land here and in your inbox."
                            className="m-5 sm:m-6"
                        />
                    ) : (
                        <ul className="divide-border/70 divide-y">
                            {registrations.data.map((row) => (
                                <li key={row.id}>
                                    <Link
                                        href={show(row.id)}
                                        className="hover:bg-muted/50 flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6"
                                    >
                                        <div className="flex min-w-0 flex-col gap-1">
                                            <span className="truncate font-medium">
                                                {row.company_name}
                                                <span className="text-muted-foreground font-normal">
                                                    {' '}
                                                    · {row.name}
                                                </span>
                                            </span>
                                            <span className="text-muted-foreground flex flex-wrap gap-x-2 text-xs">
                                                <span>{row.email}</span>
                                                {(row.location ||
                                                    row.country) && (
                                                    <>
                                                        <span aria-hidden>
                                                            ·
                                                        </span>
                                                        <span>
                                                            {row.location ??
                                                                row.country}
                                                        </span>
                                                    </>
                                                )}
                                                <span aria-hidden>·</span>
                                                <span>{row.source}</span>
                                            </span>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-3">
                                            {row.demo_at && (
                                                <span className="text-muted-foreground font-mono text-xs">
                                                    Demo{' '}
                                                    {formatDateTime(
                                                        row.demo_at,
                                                    )}
                                                </span>
                                            )}
                                            <StatusPill
                                                kind="registration"
                                                value={row.status}
                                            />
                                            <span className="text-muted-foreground font-mono text-xs">
                                                {formatDate(row.created_at)}
                                            </span>
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Pagination
                    page={registrations}
                    onPageChange={(page) => visit({ page })}
                    label="registrations"
                />
            </Page>
        </>
    );
}
