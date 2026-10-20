import { useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
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
import { store as storeGrid } from '@/routes/clients/grids';

export type GridAccountOption = {
    id: number;
    handle: string;
    display_name: string | null;
    connection_status: string;
    /** Set on the org-wide hub, where accounts span clients. */
    client_id?: number;
    client_name?: string;
};

export type GridCampaignOption = {
    value: string;
    label: string;
    client_id?: number;
};

const NONE = 'none';

/**
 * Phase 18 — "New grid": a name, one of the client's Instagram accounts, an optional campaign and
 * the pinned-post count. On a client's Grid tab the client is fixed (`clientId`); on the grids hub
 * the account picked decides the client.
 */
export function NewGridDialog({
    accounts,
    campaignOptions,
    clientId,
}: {
    accounts: GridAccountOption[];
    campaignOptions: GridCampaignOption[];
    clientId?: number;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        name: '',
        social_account_id: accounts.length === 1 ? String(accounts[0].id) : '',
        campaign_id: NONE,
        pinned_count: '0',
    });

    const account = accounts.find(
        (option) => String(option.id) === form.data.social_account_id,
    );
    const targetClientId = clientId ?? account?.client_id;
    const campaigns = campaignOptions.filter(
        (option) =>
            option.client_id === undefined ||
            option.client_id === targetClientId,
    );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (!targetClientId) {
            form.setError('social_account_id', 'Pick an Instagram account.');

            return;
        }

        form.transform((data) => ({
            name: data.name,
            social_account_id: Number(data.social_account_id),
            campaign_id:
                data.campaign_id === NONE ? null : Number(data.campaign_id),
            pinned_count: Number(data.pinned_count),
        }));
        form.post(storeGrid.url(targetClientId), {
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <>
            <Button onClick={() => setOpen(true)}>
                <Plus />
                New grid
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <form onSubmit={submit} className="grid gap-5">
                        <DialogHeader>
                            <DialogTitle>Plan a grid</DialogTitle>
                            <DialogDescription>
                                Lay out the next run of posts and see them on
                                the real profile before anything goes out.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-1.5">
                            <Label htmlFor="new-grid-name">Name</Label>
                            <Input
                                id="new-grid-name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                placeholder="e.g. November launch"
                                autoFocus
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Instagram account</Label>
                            <Select
                                value={form.data.social_account_id}
                                onValueChange={(value) => {
                                    form.setData((data) => ({
                                        ...data,
                                        social_account_id: value,
                                        campaign_id: NONE,
                                    }));
                                }}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Pick the account" />
                                </SelectTrigger>
                                <SelectContent>
                                    {accounts.map((option) => (
                                        <SelectItem
                                            key={option.id}
                                            value={String(option.id)}
                                        >
                                            {option.client_name
                                                ? `${option.client_name} · `
                                                : ''}
                                            {option.handle}
                                            {option.connection_status !==
                                            'connected'
                                                ? ' (not connected)'
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError
                                message={form.errors.social_account_id}
                            />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label>Campaign</Label>
                                <Select
                                    value={form.data.campaign_id}
                                    onValueChange={(value) =>
                                        form.setData('campaign_id', value)
                                    }
                                    disabled={!targetClientId}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            No campaign
                                        </SelectItem>
                                        {campaigns.map((option) => (
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
                            <div className="grid gap-1.5">
                                <Label>Pinned posts</Label>
                                <ToggleGroup
                                    type="single"
                                    variant="outline"
                                    size="sm"
                                    value={form.data.pinned_count}
                                    onValueChange={(value) =>
                                        value &&
                                        form.setData('pinned_count', value)
                                    }
                                    className="justify-start"
                                >
                                    {['0', '1', '2', '3'].map((value) => (
                                        <ToggleGroupItem
                                            key={value}
                                            value={value}
                                        >
                                            {value}
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                            </div>
                        </div>
                        <p className="text-muted-foreground text-xs text-pretty">
                            Pinned posts sit in front of the grid and push it
                            along. Instagram doesn’t share which posts are
                            pinned, so set how many there are.
                        </p>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    form.data.social_account_id === ''
                                }
                            >
                                {form.processing
                                    ? 'Starting…'
                                    : 'Start planning'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
