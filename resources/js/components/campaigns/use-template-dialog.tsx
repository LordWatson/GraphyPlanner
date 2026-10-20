import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import type { Option } from '@/components/campaigns/types';
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
import { use as useTemplate } from '@/routes/campaign-templates';
import { DatePicker } from '@/components/date-picker';

export type TemplateSummary = {
    id: number;
    name: string;
    description: string | null;
    duration_days: number;
    platforms: string[];
};

/**
 * Step C-3.4 — start a campaign from a template: client, start date, name and which platforms to
 * keep. The plan, phases and a draft brief are laid out from the start date.
 */
export function UseTemplateDialog({
    template,
    clients,
    platformOptions,
    open,
    onOpenChange,
    clientId,
}: {
    template: TemplateSummary;
    clients: Option[];
    platformOptions: Option[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    clientId?: number;
}) {
    const form = useForm({
        client_id: clientId ? String(clientId) : '',
        name: template.name,
        start_date: '',
        platforms: template.platforms,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            client_id: Number(data.client_id),
        }));
        form.post(useTemplate.url(template.id), {
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>Start from “{template.name}”</DialogTitle>
                        <DialogDescription>
                            {template.duration_days} days of phases and planned
                            posts, laid out from your start date.
                        </DialogDescription>
                    </DialogHeader>
                    {!clientId && (
                        <div className="grid gap-1.5">
                            <Label>Client</Label>
                            <Select
                                value={form.data.client_id}
                                onValueChange={(value) =>
                                    form.setData('client_id', value)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Which client?" />
                                </SelectTrigger>
                                <SelectContent>
                                    {clients.map((client) => (
                                        <SelectItem
                                            key={client.value}
                                            value={client.value}
                                        >
                                            {client.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.client_id} />
                        </div>
                    )}
                    <div className="grid grid-cols-2 gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="template-name">Campaign name</Label>
                            <Input
                                id="template-name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="template-start">Starts</Label>
                            <DatePicker
                                id="template-start"
                                value={form.data.start_date}
                                onChange={(value) =>
                                    form.setData('start_date', value ?? '')
                                }
                            />
                            <InputError message={form.errors.start_date} />
                        </div>
                    </div>
                    {template.platforms.length > 1 && (
                        <div className="grid gap-1.5">
                            <Label>Platforms to include</Label>
                            <ToggleGroup
                                type="multiple"
                                value={form.data.platforms}
                                onValueChange={(value) =>
                                    form.setData('platforms', value)
                                }
                                className="flex-wrap justify-start"
                            >
                                {platformOptions
                                    .filter((option) =>
                                        template.platforms.includes(
                                            option.value,
                                        ),
                                    )
                                    .map((option) => (
                                        <ToggleGroupItem
                                            key={option.value}
                                            value={option.value}
                                            className="px-3"
                                        >
                                            {option.label}
                                        </ToggleGroupItem>
                                    ))}
                            </ToggleGroup>
                        </div>
                    )}
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
                                !form.data.client_id ||
                                !form.data.start_date
                            }
                        >
                            Create campaign
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
