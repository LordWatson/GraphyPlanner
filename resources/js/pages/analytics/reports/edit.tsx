import { Head, Link, router, useForm } from '@inertiajs/react';
import { FileBarChart, Mail, SlidersHorizontal, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import ReportController from '@/actions/App/Http/Controllers/Analytics/ReportController';
import { toggle } from '@/components/analytics/filter-controls';
import { describeRange } from '@/components/analytics/format';
import { OrganizationFilterBar } from '@/components/analytics/organization-filter-bar';
import type {
    OrgFilterOptions,
    OrgFilterState,
    OrgFilters,
} from '@/components/analytics/types';
import { stateFromFilters } from '@/components/analytics/types';
import { confirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Page, PageHeader, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { index as analyticsIndex } from '@/routes/analytics';
import {
    index as reportsIndex,
    show as showReport,
} from '@/routes/analytics/reports';

type Report = { id: number; name: string };

type Props = {
    report: Report | null;
    defaults: {
        name: string;
        description: string;
        filters: OrgFilters;
        schedule: 'weekly' | 'monthly' | null;
        recipient_ids: number[];
    };
    filterOptions: OrgFilterOptions;
    recipientOptions: { value: number; label: string; role: string }[];
    scheduleOptions: { value: 'weekly' | 'monthly'; label: string }[];
};

type ReportForm = {
    name: string;
    description: string;
    filters: OrgFilterState;
    schedule: '' | 'weekly' | 'monthly';
    recipient_ids: number[];
};

/**
 * Step 17.6 — the report builder (new and existing): a name, an optional note, the analytics
 * page's own filter row, and (Step 17.8) an optional email schedule. Presets stay relative.
 * Every section is offered here — when the report runs, each person only sees what their role
 * allows, so one report works for the whole team.
 */
export default function EditReport({
    report,
    defaults,
    filterOptions,
    recipientOptions,
    scheduleOptions,
}: Props) {
    const form = useForm<ReportForm>({
        name: defaults.name,
        description: defaults.description,
        filters: stateFromFilters(defaults.filters),
        schedule: defaults.schedule ?? '',
        recipient_ids: defaults.recipient_ids,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            schedule: data.schedule === '' ? null : data.schedule,
            recipient_ids: data.schedule === '' ? [] : data.recipient_ids,
        }));

        if (report) {
            form.put(ReportController.update.url(report.id), {
                preserveScroll: true,
            });
        } else {
            form.post(ReportController.store.url(), { preserveScroll: true });
        }
    };

    const remove = async () => {
        if (!report) {
            return;
        }

        if (
            !(await confirmDialog({
                title: `Delete “${report.name}”?`,
                description:
                    'Only the saved view goes — no analytics data is touched, and any email schedule stops.',
                confirmLabel: 'Delete report',
                tone: 'destructive',
            }))
        ) {
            return;
        }

        router.delete(ReportController.destroy.url(report.id));
    };

    const errors = form.errors as Record<string, string | undefined>;
    const filterError = Object.entries(errors).find(([key]) =>
        key.startsWith('filters.'),
    )?.[1];
    const recipientError =
        errors.recipient_ids ??
        Object.entries(errors).find(([key]) =>
            key.startsWith('recipient_ids.'),
        )?.[1];

    return (
        <>
            <Head title={report ? `Edit ${report.name}` : 'Build a report'} />
            <Page width="narrow">
                <PageHeader
                    eyebrow={report ? 'Edit report' : 'New report'}
                    title={
                        report ? (
                            <>
                                Tune <em>{report.name}</em>.
                            </>
                        ) : (
                            <>
                                Build a <em>report</em>.
                            </>
                        )
                    }
                    description="Choose what it covers once; re-run, export or email it whenever you need it."
                />

                <form onSubmit={submit} className="flex flex-col gap-5">
                    <Panel eyebrow="01" title="Name it" icon={FileBarChart}>
                        <div className="grid gap-5">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Report name</Label>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    placeholder="e.g. Monday studio check-in"
                                    maxLength={120}
                                    required
                                    autoFocus={!report}
                                    aria-invalid={!!form.errors.name}
                                />
                                <InputError message={form.errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="description">
                                    Note{' '}
                                    <span className="text-muted-foreground font-normal">
                                        (optional)
                                    </span>
                                </Label>
                                <Textarea
                                    id="description"
                                    rows={2}
                                    maxLength={500}
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Who it's for, or what to look out for."
                                />
                                <InputError message={form.errors.description} />
                            </div>
                        </div>
                    </Panel>

                    <Panel
                        eyebrow="02"
                        title="What it covers"
                        icon={SlidersHorizontal}
                        description={describeRange(form.data.filters)}
                    >
                        <OrganizationFilterBar
                            value={form.data.filters}
                            onChange={(filters) =>
                                form.setData('filters', filters)
                            }
                            options={filterOptions}
                        />
                        <InputError message={filterError} className="mt-3" />
                        <p className="text-muted-foreground mt-4 text-xs text-pretty">
                            Preset ranges move with the calendar — “30 days”
                            always means the 30 days up to the day it runs, in
                            your studio’s timezone. Pick Custom to pin exact
                            dates.
                        </p>
                    </Panel>

                    <Panel
                        eyebrow="03"
                        title="Email it"
                        icon={Mail}
                        description="Optional. We'll send the headline figures with the CSV and PDF attached."
                    >
                        <div className="flex flex-col gap-5">
                            <div
                                role="radiogroup"
                                aria-label="Email schedule"
                                className="bg-muted flex max-w-full gap-1 self-start overflow-x-auto rounded-full p-1"
                            >
                                {(
                                    [
                                        { value: '', label: 'Don’t email' },
                                        ...scheduleOptions,
                                    ] as {
                                        value: ReportForm['schedule'];
                                        label: string;
                                    }[]
                                ).map((option) => {
                                    const active =
                                        form.data.schedule === option.value;

                                    return (
                                        <button
                                            key={option.value || 'off'}
                                            type="button"
                                            role="radio"
                                            aria-checked={active}
                                            onClick={() =>
                                                form.setData(
                                                    'schedule',
                                                    option.value,
                                                )
                                            }
                                            className={cn(
                                                'focus-visible:ring-ring/40 min-h-8 shrink-0 rounded-full px-3.5 py-1 text-[13px] font-medium whitespace-nowrap transition-colors outline-none focus-visible:ring-[3px]',
                                                active
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            {option.label}
                                        </button>
                                    );
                                })}
                            </div>
                            <InputError message={errors.schedule} />

                            {form.data.schedule !== '' && (
                                <fieldset className="flex flex-col gap-3">
                                    <legend className="eyebrow text-muted-foreground mb-3">
                                        Send to
                                    </legend>
                                    <ul className="divide-border/70 border-border/70 flex flex-col divide-y rounded-xl border">
                                        {recipientOptions.map((person) => {
                                            const id = `recipient-${person.value}`;

                                            return (
                                                <li key={person.value}>
                                                    <label
                                                        htmlFor={id}
                                                        className="hover:bg-muted/50 flex min-h-11 cursor-pointer items-center gap-3 px-4 py-2.5 transition-colors"
                                                    >
                                                        <Checkbox
                                                            id={id}
                                                            checked={form.data.recipient_ids.includes(
                                                                person.value,
                                                            )}
                                                            onCheckedChange={() =>
                                                                form.setData(
                                                                    'recipient_ids',
                                                                    toggle(
                                                                        form
                                                                            .data
                                                                            .recipient_ids,
                                                                        person.value,
                                                                    ),
                                                                )
                                                            }
                                                        />
                                                        <span className="flex-1 text-sm font-medium">
                                                            {person.label}
                                                        </span>
                                                        <span className="text-muted-foreground font-mono text-[11px]">
                                                            {person.role}
                                                        </span>
                                                    </label>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                    <InputError message={recipientError} />
                                    <p className="text-muted-foreground text-xs text-pretty">
                                        Each person gets their own run — a
                                        Designer’s email never includes billing.
                                        Weekly reports go out on Mondays,
                                        monthly on the 1st.
                                    </p>
                                </fieldset>
                            )}
                        </div>
                    </Panel>

                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center">
                        {report && (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-destructive sm:mr-auto"
                                onClick={remove}
                            >
                                <Trash2 />
                                Delete report
                            </Button>
                        )}
                        <div className="flex flex-col-reverse gap-2 sm:ml-auto sm:flex-row">
                            <Button variant="outline" asChild>
                                <Link
                                    href={
                                        report
                                            ? showReport(report.id)
                                            : reportsIndex()
                                    }
                                >
                                    Cancel
                                </Link>
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && <Spinner />}
                                {report ? 'Save changes' : 'Save and run'}
                            </Button>
                        </div>
                    </div>
                </form>
            </Page>
        </>
    );
}

EditReport.layout = {
    breadcrumbs: [
        { title: 'Analytics', href: analyticsIndex() },
        { title: 'Reports', href: reportsIndex() },
    ],
};
