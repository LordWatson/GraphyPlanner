import { useForm } from '@inertiajs/react';
import { campaignColourClass } from '@/components/campaigns/campaign-chip';
import type { Phase } from '@/components/campaigns/types';
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
import { cn } from '@/lib/utils';
import {
    store as storePhase,
    update as updatePhase,
} from '@/routes/campaigns/phases';

const COLOURS = Object.keys(campaignColourClass);

/**
 * Step C-3.1 — add or edit a phase. Dates must sit inside the campaign's (the server says so in
 * plain words if not); a phase may stay undated while the campaign is.
 */
export function PhaseDialog({
    campaignId,
    phase,
    open,
    onOpenChange,
    bounds,
}: {
    campaignId: number;
    phase: Phase | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    bounds: { start: string | null; end: string | null };
}) {
    const form = useForm({
        name: phase?.name ?? '',
        starts_on: phase?.starts_on ?? '',
        ends_on: phase?.ends_on ?? '',
        colour: phase?.colour ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            starts_on: data.starts_on || null,
            ends_on: data.ends_on || null,
            colour: data.colour || null,
        }));

        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (phase) {
            form.put(
                updatePhase.url({ campaign: campaignId, phase: phase.id }),
                options,
            );
        } else {
            form.post(storePhase.url(campaignId), options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>
                            {phase ? 'Edit phase' : 'Add a phase'}
                        </DialogTitle>
                        <DialogDescription>
                            Teaser, launch, sustain, wrap… Phases group the plan
                            into chapters.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-1.5">
                        <Label htmlFor="phase-name">Name</Label>
                        <Input
                            id="phase-name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            placeholder="Launch"
                            required
                            autoFocus
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="phase-start">Starts</Label>
                            <Input
                                id="phase-start"
                                type="date"
                                min={bounds.start ?? undefined}
                                max={bounds.end ?? undefined}
                                value={form.data.starts_on}
                                onChange={(event) =>
                                    form.setData(
                                        'starts_on',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.starts_on} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="phase-end">Ends</Label>
                            <Input
                                id="phase-end"
                                type="date"
                                min={
                                    form.data.starts_on ||
                                    bounds.start ||
                                    undefined
                                }
                                max={bounds.end ?? undefined}
                                value={form.data.ends_on}
                                onChange={(event) =>
                                    form.setData('ends_on', event.target.value)
                                }
                            />
                            <InputError message={form.errors.ends_on} />
                        </div>
                    </div>

                    <fieldset className="grid gap-2">
                        <legend className="text-sm font-medium">Colour</legend>
                        <div className="flex flex-wrap gap-2">
                            {COLOURS.map((colour) => (
                                <button
                                    key={colour}
                                    type="button"
                                    aria-label={colour}
                                    aria-pressed={form.data.colour === colour}
                                    onClick={() =>
                                        form.setData(
                                            'colour',
                                            form.data.colour === colour
                                                ? ''
                                                : colour,
                                        )
                                    }
                                    className={cn(
                                        'ring-offset-background focus-visible:ring-ring size-8 rounded-full ring-offset-2 transition-shadow outline-none focus-visible:ring-[3px]',
                                        campaignColourClass[colour],
                                        form.data.colour === colour &&
                                            'ring-foreground ring-2',
                                    )}
                                />
                            ))}
                        </div>
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
                            {phase ? 'Save phase' : 'Add phase'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
