import { useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import type { PlanData } from '@/components/campaigns/plan/types';
import type {
    AssetRequirement,
    Deliverable,
} from '@/components/campaigns/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Textarea } from '@/components/ui/textarea';
import {
    store as storeDeliverable,
    update as updateDeliverable,
} from '@/routes/campaigns/deliverables';

const NONE = '__none';

type FormShape = {
    title: string;
    platform: string;
    campaign_phase_id: string;
    planned_for: string;
    planned_time: string;
    format: string;
    pillar: string;
    assignee_id: string;
    notes: string;
    asset_requirements: AssetRequirement[];
};

/**
 * Step C-3.2 (+ C-4.1 asset requirements, C-4.4 assignee) — plan or edit one deliverable. A
 * Designer (`checklistOnly`) sees the same card but may only tick asset requirements; the server
 * enforces that too.
 */
export function DeliverableDialog({
    campaignId,
    deliverable,
    plan,
    open,
    onOpenChange,
    checklistOnly = false,
    defaults,
}: {
    campaignId: number;
    deliverable: Deliverable | null;
    plan: PlanData;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    checklistOnly?: boolean;
    defaults?: Partial<FormShape>;
}) {
    const form = useForm<FormShape>({
        title: deliverable?.title ?? '',
        platform:
            deliverable?.platform ??
            plan.platformOptions[0]?.value ??
            'instagram',
        campaign_phase_id: deliverable?.phase_id
            ? String(deliverable.phase_id)
            : NONE,
        planned_for: deliverable?.planned_for ?? '',
        planned_time: deliverable?.planned_time ?? '',
        format: deliverable?.format ?? '',
        pillar: deliverable?.pillar ?? NONE,
        assignee_id: deliverable?.assignee
            ? String(deliverable.assignee.id)
            : NONE,
        notes: deliverable?.notes ?? '',
        asset_requirements: deliverable?.asset_requirements ?? [],
        ...defaults,
    });
    const [newRequirement, setNewRequirement] = useState('');

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (checklistOnly && deliverable) {
            form.transform((data) => ({
                asset_requirements: data.asset_requirements,
            }));
            form.patch(
                updateDeliverable.url({
                    campaign: campaignId,
                    deliverable: deliverable.id,
                }),
                options,
            );

            return;
        }

        form.transform((data) => ({
            ...data,
            campaign_phase_id:
                data.campaign_phase_id === NONE
                    ? null
                    : Number(data.campaign_phase_id),
            assignee_id:
                data.assignee_id === NONE ? null : Number(data.assignee_id),
            pillar: data.pillar === NONE ? null : data.pillar,
            planned_for: data.planned_for || null,
            planned_time: data.planned_time || null,
            format: data.format || null,
            notes: data.notes || null,
        }));

        if (deliverable) {
            form.put(
                updateDeliverable.url({
                    campaign: campaignId,
                    deliverable: deliverable.id,
                }),
                options,
            );
        } else {
            form.post(storeDeliverable.url(campaignId), options);
        }
    };

    const addRequirement = () => {
        const label = newRequirement.trim();

        if (label) {
            form.setData('asset_requirements', [
                ...form.data.asset_requirements,
                { label, met: false },
            ]);
            setNewRequirement('');
        }
    };

    const setRequirement = (
        index: number,
        patch: Partial<AssetRequirement> | null,
    ) => {
        form.setData(
            'asset_requirements',
            patch === null
                ? form.data.asset_requirements.filter((_, i) => i !== index)
                : form.data.asset_requirements.map((requirement, i) =>
                      i === index ? { ...requirement, ...patch } : requirement,
                  ),
        );
    };

    const disabled = checklistOnly;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>
                            {deliverable
                                ? deliverable.title
                                : 'Plan a deliverable'}
                        </DialogTitle>
                        <DialogDescription>
                            {checklistOnly
                                ? 'Tick off the assets as they’re ready.'
                                : 'A planned slot. It becomes a post when someone starts it.'}
                        </DialogDescription>
                    </DialogHeader>

                    <fieldset
                        disabled={disabled}
                        className="grid gap-4 disabled:opacity-70"
                    >
                        <div className="grid gap-1.5">
                            <Label htmlFor="deliverable-title">
                                Working title
                            </Label>
                            <Input
                                id="deliverable-title"
                                value={form.data.title}
                                onChange={(event) =>
                                    form.setData('title', event.target.value)
                                }
                                placeholder="Launch day reel"
                                required
                                autoFocus={!disabled}
                            />
                            <InputError message={form.errors.title} />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-1.5">
                                <Label>Platform</Label>
                                <Select
                                    value={form.data.platform}
                                    onValueChange={(value) =>
                                        form.setData('platform', value)
                                    }
                                    disabled={disabled}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {[
                                            ...plan.platformOptions,
                                            ...(deliverable &&
                                            !plan.platformOptions.some(
                                                (option) =>
                                                    option.value ===
                                                    deliverable.platform,
                                            )
                                                ? [
                                                      {
                                                          value: deliverable.platform,
                                                          label:
                                                              plan
                                                                  .platformLabels[
                                                                  deliverable
                                                                      .platform
                                                              ] ??
                                                              deliverable.platform,
                                                      },
                                                  ]
                                                : []),
                                        ].map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.platform} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label>Phase</Label>
                                <Select
                                    value={form.data.campaign_phase_id}
                                    onValueChange={(value) =>
                                        form.setData('campaign_phase_id', value)
                                    }
                                    disabled={disabled}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            No phase
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
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="deliverable-date">
                                    Goes out
                                </Label>
                                <Input
                                    id="deliverable-date"
                                    type="date"
                                    value={form.data.planned_for}
                                    onChange={(event) =>
                                        form.setData(
                                            'planned_for',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError message={form.errors.planned_for} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="deliverable-time">At</Label>
                                <Input
                                    id="deliverable-time"
                                    type="time"
                                    value={form.data.planned_time}
                                    onChange={(event) =>
                                        form.setData(
                                            'planned_time',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.planned_time}
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="deliverable-format">
                                    Format
                                </Label>
                                <Input
                                    id="deliverable-format"
                                    value={form.data.format}
                                    onChange={(event) =>
                                        form.setData(
                                            'format',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Reel, carousel…"
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label>Pillar</Label>
                                <Select
                                    value={form.data.pillar}
                                    onValueChange={(value) =>
                                        form.setData('pillar', value)
                                    }
                                    disabled={
                                        disabled || plan.pillars.length === 0
                                    }
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="No pillars set" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            No pillar
                                        </SelectItem>
                                        {plan.pillars.map((pillar) => (
                                            <SelectItem
                                                key={pillar}
                                                value={pillar}
                                            >
                                                {pillar}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid gap-1.5">
                            <Label>Assignee</Label>
                            <Select
                                value={form.data.assignee_id}
                                onValueChange={(value) =>
                                    form.setData('assignee_id', value)
                                }
                                disabled={disabled}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        Nobody yet
                                    </SelectItem>
                                    {plan.team.map((member) => (
                                        <SelectItem
                                            key={member.id}
                                            value={String(member.id)}
                                        >
                                            {member.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-muted-foreground text-xs">
                                Due {plan.leadDays} working{' '}
                                {plan.leadDays === 1 ? 'day' : 'days'} before it
                                goes out, so the client has time to approve.
                            </p>
                            <InputError message={form.errors.assignee_id} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="deliverable-notes">Notes</Label>
                            <Textarea
                                id="deliverable-notes"
                                rows={2}
                                value={form.data.notes}
                                onChange={(event) =>
                                    form.setData('notes', event.target.value)
                                }
                            />
                        </div>
                    </fieldset>

                    <fieldset className="grid gap-2">
                        <legend className="text-sm font-medium">
                            Asset requirements
                        </legend>
                        {form.data.asset_requirements.length === 0 && (
                            <p className="text-muted-foreground text-xs">
                                None yet. Add what this post needs, e.g. “1 ×
                                9:16 video”.
                            </p>
                        )}
                        <ul className="flex flex-col gap-1.5">
                            {form.data.asset_requirements.map(
                                (requirement, index) => (
                                    <li
                                        key={index}
                                        className="flex items-center gap-2.5"
                                    >
                                        <Checkbox
                                            id={`requirement-${index}`}
                                            checked={requirement.met}
                                            onCheckedChange={(checked) =>
                                                setRequirement(index, {
                                                    met: checked === true,
                                                })
                                            }
                                        />
                                        <label
                                            htmlFor={`requirement-${index}`}
                                            className="flex-1 text-sm"
                                        >
                                            {requirement.label}
                                        </label>
                                        {!checklistOnly && (
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                className="size-7"
                                                aria-label={`Remove ${requirement.label}`}
                                                onClick={() =>
                                                    setRequirement(index, null)
                                                }
                                            >
                                                <X className="size-3.5" />
                                            </Button>
                                        )}
                                    </li>
                                ),
                            )}
                        </ul>
                        {!checklistOnly && (
                            <div className="flex gap-2">
                                <Input
                                    value={newRequirement}
                                    onChange={(event) =>
                                        setNewRequirement(event.target.value)
                                    }
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            event.preventDefault();
                                            addRequirement();
                                        }
                                    }}
                                    placeholder="1 × 9:16 video"
                                    aria-label="New asset requirement"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={addRequirement}
                                >
                                    <Plus />
                                    Add
                                </Button>
                            </div>
                        )}
                        <InputError message={form.errors.asset_requirements} />
                    </fieldset>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {deliverable ? 'Save' : 'Add to plan'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
