import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowUpRight,
    CalendarCog,
    FileBarChart,
    Mail,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import { reported, signed } from '@/components/analytics/engagement-section';
import type { ClientReportRow } from '@/components/client-reports/client-report-document';
import { EmptyState, Page, PageHeader, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate } from '@/lib/dates';
import { show as showReport } from '@/routes/client-reports';
import { index as clientsIndex, show as showClient } from '@/routes/clients';
import { update as updateSchedule } from '@/routes/clients/report-schedule';
import { store as prepareReport } from '@/routes/clients/reports';

type Option = { value: string; label: string };

type Props = {
    client: { id: number; name: string; report_schedule: string };
    reports: ClientReportRow[];
    months: Option[];
    suggestedMonth: string;
    schedules: Option[];
    recipients: { name: string | null; email: string }[];
    can: { prepare: boolean; manageSchedule: boolean };
};

/**
 * Step M-3.3 — a client's monthly reports in the studio: how they're produced (schedule), who
 * receives them, every report so far, and "Prepare a report" for a month that doesn't have one.
 * Data screen: ruled rows, status as pill + label.
 */
export default function ClientReportsIndex({
    client,
    reports,
    months,
    suggestedMonth,
    schedules,
    recipients,
    can,
}: Props) {
    const [month, setMonth] = useState(
        months.find((option) => option.value === suggestedMonth)?.value ??
            months[0]?.value ??
            '',
    );
    const [preparing, setPreparing] = useState(false);
    const [schedule, setSchedule] = useState(client.report_schedule);

    const prepare = () =>
        router.post(
            prepareReport.url(client.id),
            { month },
            {
                onStart: () => setPreparing(true),
                onFinish: () => setPreparing(false),
            },
        );

    const saveSchedule = (value: string) => {
        setSchedule(value);
        router.put(
            updateSchedule.url(client.id),
            { report_schedule: value },
            {
                preserveScroll: true,
                onError: () => setSchedule(client.report_schedule),
            },
        );
    };

    return (
        <>
            <Head title={`${client.name} reports`} />
            <Page>
                <PageHeader
                    eyebrow="Monthly reports"
                    title={
                        <>
                            {client.name}’s <em>reports</em>.
                        </>
                    }
                    description="A branded report each month — posts shipped, engagement, audience growth and approvals — in the client’s portal and inbox. Never any billing."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={showClient(client.id)}>
                                <ArrowLeft />
                                Back to client
                            </Link>
                        </Button>
                    }
                />

                <div className="grid gap-5 lg:grid-cols-2">
                    <Panel
                        eyebrow="On the 1st of each month"
                        title="How reports are made"
                        icon={CalendarCog}
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="report-schedule">Schedule</Label>
                            <Select
                                value={schedule}
                                onValueChange={saveSchedule}
                                disabled={!can.manageSchedule}
                            >
                                <SelectTrigger
                                    id="report-schedule"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {schedules.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-muted-foreground text-xs">
                                {schedule === 'automatic'
                                    ? 'Last month’s report is published and emailed on the 1st, without commentary.'
                                    : schedule === 'off'
                                      ? 'No report is prepared. You can still prepare one by hand below.'
                                      : 'A draft is ready on the 1st. You add a note and publish it.'}
                            </p>
                        </div>
                    </Panel>

                    <Panel eyebrow="Recipients" title="Who gets it" icon={Mail}>
                        {recipients.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No client contacts yet. Published reports still
                                appear in the portal; invite a contact from
                                Portal access to email them too.
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-1.5 text-sm">
                                {recipients.map((recipient) => (
                                    <li
                                        key={recipient.email}
                                        className="flex flex-wrap items-baseline gap-x-2"
                                    >
                                        {recipient.name && (
                                            <span className="font-medium">
                                                {recipient.name}
                                            </span>
                                        )}
                                        <span className="text-muted-foreground font-mono text-xs">
                                            {recipient.email}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>

                <Panel
                    eyebrow={`${reports.length} so far`}
                    title="Reports"
                    icon={FileBarChart}
                    bodyClassName="p-0 sm:p-0"
                    actions={
                        can.prepare &&
                        months.length > 0 && (
                            <div className="flex flex-wrap items-center gap-2">
                                <Select value={month} onValueChange={setMonth}>
                                    <SelectTrigger
                                        className="w-44"
                                        aria-label="Month to report on"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {months.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Button
                                    onClick={prepare}
                                    disabled={preparing || month === ''}
                                >
                                    <Plus />
                                    Prepare report
                                </Button>
                            </div>
                        )
                    }
                >
                    {reports.length === 0 ? (
                        <div className="p-5 sm:p-6">
                            <EmptyState
                                compact
                                icon={FileBarChart}
                                title="No reports yet."
                                description="The first one is drafted on the 1st, or prepare one now."
                            />
                        </div>
                    ) : (
                        <ul className="divide-border/70 divide-y">
                            {reports.map((report) => (
                                <li key={report.id}>
                                    <Link
                                        href={showReport(report.id)}
                                        className="hover:bg-muted/50 group flex flex-col gap-2 px-5 py-4 transition-colors sm:flex-row sm:items-center sm:justify-between sm:px-6"
                                    >
                                        <span className="flex items-center gap-3">
                                            <span className="font-display text-base font-semibold">
                                                {report.period_label}
                                            </span>
                                            <StatusPill
                                                kind="clientReport"
                                                value={report.status}
                                                label={report.status_label}
                                            />
                                        </span>
                                        <span className="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 font-mono text-xs tabular-nums">
                                            <span>
                                                {reported(
                                                    report.posts_published,
                                                )}{' '}
                                                posts
                                            </span>
                                            <span>
                                                {reported(report.engagement)}{' '}
                                                interactions
                                            </span>
                                            <span>
                                                {signed(report.follower_growth)}{' '}
                                                followers
                                            </span>
                                            <span>
                                                {report.published_at
                                                    ? `Sent ${formatDate(report.published_at)} · ${report.recipients_count} ${report.recipients_count === 1 ? 'contact' : 'contacts'}`
                                                    : 'Not sent yet'}
                                            </span>
                                            <ArrowUpRight
                                                className="group-hover:text-foreground size-4"
                                                aria-hidden
                                            />
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </Page>
        </>
    );
}

ClientReportsIndex.layout = {
    breadcrumbs: [{ title: 'Clients', href: clientsIndex() }],
};
