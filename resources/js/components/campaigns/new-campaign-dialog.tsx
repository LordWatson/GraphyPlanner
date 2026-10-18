import { useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import {
    CampaignCoreFields,
    NONE,
    toPayload,
} from '@/components/campaigns/campaign-form-fields';
import type {
    CampaignFormData,
    CampaignFormOptions,
} from '@/components/campaigns/campaign-form-fields';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store as storeCampaign } from '@/routes/clients/campaigns';

/**
 * "New campaign" — from a client's Campaigns tab (client fixed) or the hub (pick the client). Lands
 * in the new campaign's workspace, ready for the brief.
 */
export function NewCampaignDialog({
    options,
    clientId,
    clients,
    label = 'New campaign',
}: {
    options: CampaignFormOptions;
    clientId?: number;
    clients?: Option[];
    label?: string;
}) {
    const [open, setOpen] = useState(false);
    const [client, setClient] = useState<string>(
        clientId ? String(clientId) : '',
    );
    const form = useForm<CampaignFormData>({
        name: '',
        code: '',
        owner_id: NONE,
        objective: NONE,
        colour: '',
        platforms: [],
        start_date: '',
        end_date: '',
        goal: '',
        notes: '',
        hashtags: [],
        default_link_url: '',
        utm_campaign: '',
        billing_type: NONE,
        fee_amount: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (!client) {
            form.setError('name', 'Pick a client first.');

            return;
        }

        form.transform((data) => {
            const payload = toPayload(data);
            delete payload.billing_type;
            delete payload.fee_amount;

            return payload;
        });
        form.post(storeCampaign.url(Number(client)), {
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <>
            <Button onClick={() => setOpen(true)}>
                <Plus />
                {label}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <form onSubmit={submit} className="flex flex-col gap-5">
                        <DialogHeader>
                            <DialogTitle>Start a campaign</DialogTitle>
                            <DialogDescription>
                                The basics now. The brief, the plan and the rest
                                come next.
                            </DialogDescription>
                        </DialogHeader>
                        {clients && !clientId && (
                            <div className="grid gap-1.5">
                                <Label>Client</Label>
                                <Select
                                    value={client}
                                    onValueChange={setClient}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="Which client is it for?" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {clients.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                        <CampaignCoreFields form={form} options={options} />
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Create campaign
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
