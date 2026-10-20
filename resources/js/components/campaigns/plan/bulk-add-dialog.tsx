import { useForm } from '@inertiajs/react';
import type { PlanData } from '@/components/campaigns/plan/types';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { bulk as bulkDeliverables } from '@/routes/campaigns/deliverables';
import { TimeField } from '@/components/time-field';

const WHOLE = '__whole';

/**
 * Step C-3.2 — "Add N per week on {platforms} through {phase}". Reads as a sentence so it's
 * obvious what will be created.
 */
export function BulkAddDialog({
    campaignId,
    plan,
    defaultPlatforms,
    open,
    onOpenChange,
}: {
    campaignId: number;
    plan: PlanData;
    defaultPlatforms: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({
        per_week: '2',
        platforms: defaultPlatforms,
        campaign_phase_id: plan.phases[0] ? String(plan.phases[0].id) : WHOLE,
        planned_time: '09:00',
        title_prefix: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            per_week: Number(data.per_week),
            campaign_phase_id:
                data.campaign_phase_id === WHOLE
                    ? null
                    : Number(data.campaign_phase_id),
            planned_time: data.planned_time || null,
            title_prefix: data.title_prefix || null,
        }));
        form.post(bulkDeliverables.url(campaignId), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>Fill the plan</DialogTitle>
                        <DialogDescription>
                            Lay down a steady rhythm, then fine-tune each slot.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4">
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <span>Add</span>
                            <Input
                                type="number"
                                min={1}
                                max={14}
                                value={form.data.per_week}
                                onChange={(event) =>
                                    form.setData('per_week', event.target.value)
                                }
                                className="w-20"
                                aria-label="Per week"
                            />
                            <span>per week, through</span>
                            <Select
                                value={form.data.campaign_phase_id}
                                onValueChange={(value) =>
                                    form.setData('campaign_phase_id', value)
                                }
                            >
                                <SelectTrigger
                                    className="w-48"
                                    aria-label="Phase"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={WHOLE}>
                                        the whole campaign
                                    </SelectItem>
                                    {plan.phases.map((phase) => (
                                        <SelectItem
                                            key={phase.id}
                                            value={String(phase.id)}
                                        >
                                            {phase.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <InputError
                            message={
                                form.errors.per_week ??
                                form.errors.campaign_phase_id
                            }
                        />

                        <div className="grid gap-1.5">
                            <Label>On</Label>
                            <ToggleGroup
                                type="multiple"
                                value={form.data.platforms}
                                onValueChange={(value) =>
                                    form.setData('platforms', value)
                                }
                                className="flex-wrap justify-start"
                            >
                                {plan.platformOptions.map((option) => (
                                    <ToggleGroupItem
                                        key={option.value}
                                        value={option.value}
                                        className="px-3"
                                    >
                                        {option.label}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                            <InputError message={form.errors.platforms} />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="bulk-time">At</Label>
                                <TimeField
                                    id="bulk-time"
                                    value={form.data.planned_time}
                                    onChange={(value) =>
                                        form.setData('planned_time', value)
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="bulk-prefix">
                                    Title starts with
                                </Label>
                                <Input
                                    id="bulk-prefix"
                                    value={form.data.title_prefix}
                                    onChange={(event) =>
                                        form.setData(
                                            'title_prefix',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Phase name"
                                />
                            </div>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                form.data.platforms.length === 0
                            }
                        >
                            Add to plan
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
