import { useForm } from '@inertiajs/react';
import { Plus, Share2 } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { platformIcon } from '@/components/clients/client-layout';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
import { store as storePost } from '@/routes/clients/posts';

export type PostTargetAccount = {
    id: number;
    platform: string;
    handle: string;
    timezone: string;
};

type Option = { value: string; label: string };

/**
 * Start a post for a client: caption, the accounts it goes to, and an optional schedule (the date
 * and time are each account's local time) and campaign. Everything else — media, hashtags, per-
 * target times — is done in the post editor afterwards.
 */
export function NewPostDialog({
    clientId,
    targetAccounts,
    campaigns,
    trigger,
}: {
    clientId: number;
    targetAccounts: PostTargetAccount[];
    campaigns: Option[];
    trigger?: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        master_caption: '',
        campaign_id: '',
        account_ids: [] as number[],
        scheduled_local_date: '',
        scheduled_local_time: '',
    });

    const toggleAccount = (id: number) =>
        form.setData(
            'account_ids',
            form.data.account_ids.includes(id)
                ? form.data.account_ids.filter((accountId) => accountId !== id)
                : [...form.data.account_ids, id],
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();

        // Every selected account gets the same local date/time; they can diverge in the editor.
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
        form.post(storePost.url(clientId), {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger ?? (
                    <Button>
                        <Plus />
                        New post
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New post</DialogTitle>
                    <DialogDescription>
                        Starts as an idea. Add media, hashtags and per-account
                        times in the editor.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="new-post-caption">Caption</Label>
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
                        <InputError message={form.errors.master_caption} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Target accounts</Label>
                        {targetAccounts.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                Add a social account before creating posts.
                            </p>
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                {targetAccounts.map((account) => {
                                    const AccountIcon =
                                        platformIcon[account.platform] ??
                                        Share2;
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
                                                toggleAccount(account.id)
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
                            message={errors['targets.0.social_account_id']}
                        />
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="new-post-date">
                                Scheduled date
                            </Label>
                            <Input
                                id="new-post-date"
                                type="date"
                                value={form.data.scheduled_local_date}
                                onChange={(event) =>
                                    form.setData(
                                        'scheduled_local_date',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={
                                    errors['targets.0.scheduled_local_date']
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="new-post-time">
                                Scheduled time (local)
                            </Label>
                            <Input
                                id="new-post-time"
                                type="time"
                                value={form.data.scheduled_local_time}
                                onChange={(event) =>
                                    form.setData(
                                        'scheduled_local_time',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={
                                    errors['targets.0.scheduled_local_time']
                                }
                            />
                        </div>
                    </div>
                    {campaigns.length > 0 && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="new-post-campaign">
                                Campaign{' '}
                                <span className="text-muted-foreground font-normal">
                                    (optional)
                                </span>
                            </Label>
                            <Select
                                value={form.data.campaign_id || '__none'}
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
                                    {campaigns.map((campaign) => (
                                        <SelectItem
                                            key={campaign.value}
                                            value={campaign.value}
                                        >
                                            {campaign.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.campaign_id} />
                        </div>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
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
