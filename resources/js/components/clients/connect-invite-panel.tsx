import { Form } from '@inertiajs/react';
import { Copy, Link2, Send, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Panel } from '@/components/page';
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
import { useClipboard } from '@/hooks/use-clipboard';
import { formatDate, formatDateTime } from '@/lib/dates';
import { firstErrors, http } from '@/lib/http';
import { store as storeInvite } from '@/routes/clients/connect-invites';
import { destroy as revokeInvite } from '@/routes/connect-invites';

export type ConnectInviteData = {
    id: number;
    email: string | null;
    scope: string;
    expires_at: string;
    used_at: string | null;
    connected_count: number;
    created_by: string | null;
    created_at: string | null;
};

/**
 * Step M-2.8 — "Ask the client to connect": a link the client opens to connect their own accounts,
 * so nobody asks for a password. The link is shown once (and emailed when an address is given);
 * live links can be revoked here.
 */
export function ConnectInvitePanel({
    clientId,
    clientName,
    invites,
    platforms,
}: {
    clientId: number;
    clientName: string;
    invites: ConnectInviteData[];
    /** The client's account platforms, for narrowing a link: `{value, label}`. */
    platforms: { value: string; label: string }[];
}) {
    const [open, setOpen] = useState(false);
    const [email, setEmail] = useState('');
    const [chosen, setChosen] = useState<string[]>([]);
    const [days, setDays] = useState('7');
    const [sending, setSending] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [link, setLink] = useState<string | null>(null);
    const [, copy] = useClipboard();

    const reset = () => {
        setEmail('');
        setChosen([]);
        setDays('7');
        setErrors({});
        setLink(null);
    };

    const create = async () => {
        setSending(true);
        setErrors({});

        const result = await http.post<{ url: string }>(
            storeInvite.url(clientId),
            {
                email: email || null,
                platforms: chosen,
                expires_in_days: Number(days),
            },
        );

        setSending(false);

        if (!result.ok) {
            if (result.status === 422) {
                setErrors(firstErrors(result.errors));
            } else {
                toast.error(result.message);
            }

            return;
        }

        setLink(result.data.url);
        toast.success(
            email ? `Link created and emailed to ${email}.` : 'Link created.',
        );
    };

    return (
        <Panel
            eyebrow="Client self-connect"
            title="Ask the client to connect"
            description={`Send ${clientName} a link to connect their own accounts — they sign in on each network, and never share a password.`}
            icon={Link2}
            actions={
                <Button
                    type="button"
                    size="sm"
                    onClick={() => {
                        reset();
                        setOpen(true);
                    }}
                >
                    <Send />
                    Create link
                </Button>
            }
        >
            {invites.length === 0 ? (
                <p className="text-muted-foreground text-sm">No live links.</p>
            ) : (
                <ul className="border-border divide-border/70 divide-y rounded-xl border">
                    {invites.map((invite) => (
                        <li
                            key={invite.id}
                            className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm"
                        >
                            <span className="flex min-w-0 flex-1 flex-col">
                                <span className="truncate font-medium">
                                    {invite.email ?? 'Copied link'} ·{' '}
                                    {invite.scope}
                                </span>
                                <span className="text-muted-foreground text-xs">
                                    {invite.connected_count > 0
                                        ? `${invite.connected_count} connected · last used ${formatDateTime(invite.used_at)}`
                                        : 'Not used yet'}{' '}
                                    · expires {formatDate(invite.expires_at)}
                                    {invite.created_by &&
                                        ` · by ${invite.created_by}`}
                                </span>
                            </span>
                            <Form
                                {...revokeInvite.form(invite.id)}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="ghost"
                                        disabled={processing}
                                    >
                                        <X />
                                        Revoke
                                    </Button>
                                )}
                            </Form>
                        </li>
                    ))}
                </ul>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {link
                                ? 'Your link is ready.'
                                : 'Create a connect link'}
                        </DialogTitle>
                        <DialogDescription>
                            {link
                                ? 'Copy it now — for security it won’t be shown again. Anyone with it can connect this client’s accounts until it expires.'
                                : `Only ${clientName}’s accounts can be connected with it, and nothing else is shown.`}
                        </DialogDescription>
                    </DialogHeader>

                    {link ? (
                        <div className="flex items-center gap-2">
                            <Input
                                readOnly
                                value={link}
                                className="font-mono text-xs"
                                onFocus={(e) => e.currentTarget.select()}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    void copy(link);
                                    toast.success('Link copied.');
                                }}
                            >
                                <Copy />
                                Copy
                            </Button>
                        </div>
                    ) : (
                        <div className="grid gap-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="invite-email">
                                    Email it to{' '}
                                    <span className="text-muted-foreground font-normal">
                                        (optional)
                                    </span>
                                </Label>
                                <Input
                                    id="invite-email"
                                    type="email"
                                    value={email}
                                    onChange={(e) => setEmail(e.target.value)}
                                    placeholder="owner@client.com"
                                />
                                <InputError message={errors.email} />
                            </div>
                            {platforms.length > 1 && (
                                <fieldset className="grid gap-2">
                                    <legend className="mb-1 text-sm font-medium">
                                        Accounts{' '}
                                        <span className="text-muted-foreground font-normal">
                                            (none ticked = all)
                                        </span>
                                    </legend>
                                    <div className="flex flex-wrap gap-x-4 gap-y-2">
                                        {platforms.map((platform) => (
                                            <label
                                                key={platform.value}
                                                className="flex cursor-pointer items-center gap-2 text-sm"
                                            >
                                                <Checkbox
                                                    checked={chosen.includes(
                                                        platform.value,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        setChosen((current) =>
                                                            checked
                                                                ? [
                                                                      ...current,
                                                                      platform.value,
                                                                  ]
                                                                : current.filter(
                                                                      (value) =>
                                                                          value !==
                                                                          platform.value,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                {platform.label}
                                            </label>
                                        ))}
                                    </div>
                                </fieldset>
                            )}
                            <div className="grid gap-1.5 sm:max-w-48">
                                <Label htmlFor="invite-days">
                                    Expires after
                                </Label>
                                <Select value={days} onValueChange={setDays}>
                                    <SelectTrigger id="invite-days">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {['1', '3', '7', '14', '30'].map(
                                            (value) => (
                                                <SelectItem
                                                    key={value}
                                                    value={value}
                                                >
                                                    {value === '1'
                                                        ? '1 day'
                                                        : `${value} days`}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    )}

                    <DialogFooter>
                        {link ? (
                            <Button
                                type="button"
                                onClick={() => setOpen(false)}
                            >
                                Done
                            </Button>
                        ) : (
                            <>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="button"
                                    disabled={sending}
                                    onClick={() => void create()}
                                >
                                    {sending
                                        ? 'Creating…'
                                        : email
                                          ? 'Create and email'
                                          : 'Create link'}
                                </Button>
                            </>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Panel>
    );
}
