import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { platformIcon } from '@/components/platform-icon';
import { DatePicker } from '@/components/date-picker';
import { TimeField } from '@/components/time-field';
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
import { Textarea } from '@/components/ui/textarea';
import { http } from '@/lib/http';
import { newPostOptions } from '@/routes/calendar';
import { store as storePost } from '@/routes/clients/posts';

type NamedOption = { id: number; name: string };
type Option = { value: string; label: string };
type Account = {
    id: number;
    platform: string;
    handle: string;
    timezone: string;
};

/** The day (and, from the time grid, the time) an empty slot was clicked. */
export type NewPostSlot = { date: string; time: string | null };

type OptionsPayload = { accounts: Account[]; campaigns: Option[] };

/**
 * Step M-5.7 / I-5.1 — "click an empty slot to create a post there". A dialog seeded with the
 * clicked date/time: pick the client, pick the accounts the post goes to, and it opens in the
 * editor pre-scheduled. The client's accounts and open campaigns are fetched lazily on selection
 * (`calendar.new-post-options`) so the calendar never ships every client's accounts up front.
 */
export function CalendarNewPostDialog({
    slot,
    clients,
    onClose,
}: {
    slot: NewPostSlot | null;
    clients: NamedOption[];
    onClose: () => void;
}) {
    const [clientId, setClientId] = useState<string>('');
    const [options, setOptions] = useState<OptionsPayload | null>(null);
    const [loading, setLoading] = useState(false);

    const form = useForm({
        master_caption: '',
        campaign_id: '',
        account_ids: [] as number[],
        scheduled_local_date: '',
        scheduled_local_time: '',
    });

    // Re-seed the date/time whenever a fresh slot opens the dialog.
    useEffect(() => {
        if (!slot) return;
        setClientId('');
        setOptions(null);
        form.reset();
        form.setData('scheduled_local_date', slot.date);
        form.setData('scheduled_local_time', slot.time ?? '');
        // Only when a new slot opens the dialog.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [slot?.date, slot?.time]);

    // Lazy-load the chosen client's accounts + open campaigns.
    useEffect(() => {
        if (!clientId) {
            setOptions(null);
            return;
        }

        const controller = new AbortController();
        setLoading(true);
        form.setData('account_ids', []);
        form.setData('campaign_id', '');

        void http
            .get<OptionsPayload>(
                newPostOptions.url({ query: { client: clientId } }),
                { signal: controller.signal, toastOnError: true },
            )
            .then((result) => {
                if (result.ok) {
                    setOptions(result.data);
                    setLoading(false);
                } else if (!result.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [clientId]);

    const toggleAccount = (id: number) =>
        form.setData(
            'account_ids',
            form.data.account_ids.includes(id)
                ? form.data.account_ids.filter((accountId) => accountId !== id)
                : [...form.data.account_ids, id],
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!clientId) return;

        form.transform(
            ({
                account_ids,
                campaign_id,
                scheduled_local_date,
                scheduled_local_time,
                ...data
            }) => ({
                ...data,
                campaign_id: campaign_id ? Number(campaign_id) : null,
                targets: account_ids.map((social_account_id) => ({
                    social_account_id,
                    scheduled_local_date: scheduled_local_date || null,
                    scheduled_local_time: scheduled_local_time || null,
                })),
            }),
        );

        form.post(storePost.url(Number(clientId)), {
            onSuccess: () => onClose(),
        });
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog
            open={slot !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New post</DialogTitle>
                    <DialogDescription>
                        Starts as an idea on this date. Add media, hashtags and
                        per-account times in the editor.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="new-post-client">Client</Label>
                        <Select value={clientId} onValueChange={setClientId}>
                            <SelectTrigger
                                id="new-post-client"
                                className="w-full"
                            >
                                <SelectValue placeholder="Choose a client" />
                            </SelectTrigger>
                            <SelectContent>
                                {clients.map((client) => (
                                    <SelectItem
                                        key={client.id}
                                        value={String(client.id)}
                                    >
                                        {client.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {clientId && (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor="new-post-caption">
                                    Caption
                                </Label>
                                <Textarea
                                    id="new-post-caption"
                                    rows={3}
                                    value={form.data.master_caption}
                                    onChange={(event) =>
                                        form.setData(
                                            'master_caption',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>

                            <div className="grid gap-1.5">
                                <Label>Target accounts</Label>
                                {loading ? (
                                    <p className="text-muted-foreground text-sm">
                                        Loading accounts…
                                    </p>
                                ) : (options?.accounts.length ?? 0) === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        Add a social account for this client
                                        before creating posts.
                                    </p>
                                ) : (
                                    <div className="flex flex-wrap gap-2">
                                        {options?.accounts.map((account) => {
                                            const AccountIcon = platformIcon(
                                                account.platform,
                                            );
                                            const selected =
                                                form.data.account_ids.includes(
                                                    account.id,
                                                );

                                            return (
                                                <button
                                                    key={account.id}
                                                    type="button"
                                                    aria-pressed={selected}
                                                    onClick={() =>
                                                        toggleAccount(
                                                            account.id,
                                                        )
                                                    }
                                                    className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors ${
                                                        selected
                                                            ? 'border-primary bg-primary/10 text-primary'
                                                            : 'border-border bg-muted/50 text-foreground'
                                                    }`}
                                                >
                                                    <AccountIcon className="size-3.5" />
                                                    {account.handle} ·{' '}
                                                    {account.timezone}
                                                </button>
                                            );
                                        })}
                                    </div>
                                )}
                                <InputError
                                    message={
                                        errors['targets.0.social_account_id']
                                    }
                                />
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="new-post-date">
                                        Scheduled date
                                    </Label>
                                    <DatePicker
                                        id="new-post-date"
                                        value={form.data.scheduled_local_date}
                                        onChange={(value) =>
                                            form.setData(
                                                'scheduled_local_date',
                                                value ?? '',
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="new-post-time">
                                        Scheduled time (local)
                                    </Label>
                                    <TimeField
                                        id="new-post-time"
                                        value={form.data.scheduled_local_time}
                                        onChange={(value) =>
                                            form.setData(
                                                'scheduled_local_time',
                                                value,
                                            )
                                        }
                                    />
                                </div>
                            </div>

                            {(options?.campaigns.length ?? 0) > 0 && (
                                <div className="grid gap-1.5">
                                    <Label htmlFor="new-post-campaign">
                                        Campaign{' '}
                                        <span className="text-muted-foreground font-normal">
                                            (optional)
                                        </span>
                                    </Label>
                                    <Select
                                        value={
                                            form.data.campaign_id || '__none'
                                        }
                                        onValueChange={(value) =>
                                            form.setData(
                                                'campaign_id',
                                                value === '__none' ? '' : value,
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id="new-post-campaign"
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="No campaign" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none">
                                                No campaign
                                            </SelectItem>
                                            {options?.campaigns.map(
                                                (campaign) => (
                                                    <SelectItem
                                                        key={campaign.value}
                                                        value={campaign.value}
                                                    >
                                                        {campaign.label}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}
                        </>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                !clientId ||
                                form.data.account_ids.length === 0
                            }
                        >
                            Create post
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
