import { Head, Link, router, useForm } from '@inertiajs/react';
import { FileBarChart, SlidersHorizontal, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import ReportController from '@/actions/App/Http/Controllers/SuperAdmin/ReportController';
import { confirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Page, PageHeader, Panel } from '@/components/page';
import type { FilterState } from '@/components/super-admin/analytics-filter-bar';
import { AnalyticsFilterBar } from '@/components/super-admin/analytics-filter-bar';
import type { Option, ResolvedFilters } from '@/components/super-admin/types';
import { describeRange } from '@/components/super-admin/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    index as reportsIndex,
    show as showReport,
} from '@/routes/super-admin/reports';

type Report = {
    id: number;
    name: string;
};

type Props = {
    report: Report | null;
    defaults: { name: string; description: string; filters: ResolvedFilters };
    organizationOptions: Option[];
};

type ReportForm = {
    name: string;
    description: string;
    filters: FilterState;
};

/**
 * Step 15.5 — the report builder, for both a new report and an existing one: a name, an optional
 * note, and the same filter row as the analytics page. Relative ranges stay relative when saved.
 */
export default function EditReport({
    report,
    defaults,
    organizationOptions,
}: Props) {
    const form = useForm<ReportForm>({
        name: defaults.name,
        description: defaults.description,
        filters: {
            range: defaults.filters.range,
            from: defaults.filters.from,
            to: defaults.filters.to,
            org_ids: defaults.filters.org_ids,
            metrics: defaults.filters.metrics,
        },
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

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
                    'Only the saved view goes — no analytics data is touched.',
                confirmLabel: 'Delete report',
                tone: 'destructive',
            }))
        ) {
            return;
        }

        router.delete(ReportController.destroy.url(report.id));
    };

    const filterError =
        form.errors['filters.range' as keyof ReportForm] ??
        form.errors['filters.from' as keyof ReportForm] ??
        form.errors['filters.to' as keyof ReportForm] ??
        form.errors['filters.org_ids' as keyof ReportForm] ??
        form.errors['filters.metrics' as keyof ReportForm];

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
                    description="Choose what it covers once; re-run or export it whenever you need it."
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
                                    placeholder="e.g. Monthly board update"
                                    maxLength={120}
                                    required
                                    autoFocus={!report}
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
                        description={describeRange({
                            ...form.data.filters,
                            granularity: defaults.filters.granularity,
                        })}
                    >
                        <AnalyticsFilterBar
                            value={form.data.filters}
                            onChange={(filters) =>
                                form.setData('filters', filters)
                            }
                            organizationOptions={organizationOptions}
                        />
                        <InputError message={filterError} className="mt-3" />
                        <p className="text-muted-foreground mt-4 text-xs text-pretty">
                            Preset ranges move with the calendar — “30 days”
                            always means the 30 days up to the day you run it.
                            Pick Custom to pin exact dates.
                        </p>
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
    breadcrumbs: [{ title: 'Reports', href: reportsIndex() }],
};
