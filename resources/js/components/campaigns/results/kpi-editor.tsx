import { useForm } from '@inertiajs/react';
import { Plus, Target, X } from 'lucide-react';
import { useState } from 'react';
import type { KpiResult } from '@/components/campaigns/results/kpi-rows';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { update as updateKpis } from '@/routes/campaigns/kpis';

export type MetricOption = {
    value: string;
    label: string;
    unit: 'count' | 'percent' | 'hours';
    available: boolean;
};

const UNIT_HINT: Record<MetricOption['unit'], string> = {
    count: '',
    percent: '%',
    hours: 'hours',
};

/**
 * Step C-7.1 — set the campaign's targets. Engagement metrics can be targeted now; they're marked
 * as tracked later so nobody is surprised by an empty result.
 */
export function KpiEditor({
    campaignId,
    kpis,
    metrics,
}: {
    campaignId: number;
    kpis: KpiResult[];
    metrics: MetricOption[];
}) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ kpis: { metric: string; target: string }[] }>({
        kpis: kpis.map((kpi) => ({
            metric: kpi.metric,
            target: String(kpi.target),
        })),
    });
    const unused = metrics.filter(
        (metric) => !form.data.kpis.some((row) => row.metric === metric.value),
    );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            kpis: data.kpis.map((row) => ({
                metric: row.metric,
                target: Number(row.target),
            })),
        }));
        form.put(updateKpis.url(campaignId), {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <>
            <Button
                variant={kpis.length === 0 ? 'default' : 'outline'}
                onClick={() => setOpen(true)}
            >
                <Target />
                {kpis.length === 0 ? 'Set targets' : 'Edit targets'}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <form onSubmit={submit} className="flex flex-col gap-5">
                        <DialogHeader>
                            <DialogTitle>Targets</DialogTitle>
                            <DialogDescription>
                                Pick what success means for this campaign. We
                                track the rest.
                            </DialogDescription>
                        </DialogHeader>

                        <ul className="flex flex-col gap-2.5">
                            {form.data.kpis.map((row, index) => {
                                const metric = metrics.find(
                                    (option) => option.value === row.metric,
                                );

                                return (
                                    <li
                                        key={row.metric}
                                        className="flex items-center gap-3"
                                    >
                                        <span className="flex min-w-0 flex-1 flex-col">
                                            <span className="truncate text-sm font-medium">
                                                {metric?.label}
                                            </span>
                                            {metric && !metric.available && (
                                                <span className="text-muted-foreground text-[11px]">
                                                    Tracked once engagement data
                                                    is on
                                                </span>
                                            )}
                                        </span>
                                        <Input
                                            type="number"
                                            min={0}
                                            step="any"
                                            value={row.target}
                                            onChange={(event) =>
                                                form.setData(
                                                    'kpis',
                                                    form.data.kpis.map(
                                                        (item, i) =>
                                                            i === index
                                                                ? {
                                                                      ...item,
                                                                      target: event
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                            className="w-28 text-right"
                                            aria-label={`${metric?.label} target`}
                                            required
                                        />
                                        <span className="text-muted-foreground w-10 text-xs">
                                            {metric
                                                ? UNIT_HINT[metric.unit]
                                                : ''}
                                        </span>
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            className="size-8"
                                            aria-label={`Remove ${metric?.label}`}
                                            onClick={() =>
                                                form.setData(
                                                    'kpis',
                                                    form.data.kpis.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <X className="size-4" />
                                        </Button>
                                    </li>
                                );
                            })}
                        </ul>

                        {unused.length > 0 && (
                            <div className="flex flex-wrap gap-1.5">
                                {unused.map((metric) => (
                                    <Button
                                        key={metric.value}
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            form.setData('kpis', [
                                                ...form.data.kpis,
                                                {
                                                    metric: metric.value,
                                                    target: '',
                                                },
                                            ])
                                        }
                                    >
                                        <Plus />
                                        {metric.label}
                                    </Button>
                                ))}
                            </div>
                        )}
                        <InputError message={Object.values(form.errors)[0]} />

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Save targets
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
