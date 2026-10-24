import { Head, router } from '@inertiajs/react';
import { Link2, Loader2, Settings2, Unplug } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { confirmDialog } from '@/components/confirm-dialog';
import { ChapterHero, HeroStat, Page, Panel } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { StatusPill } from '@/components/status-pill';
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
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { http } from '@/lib/http';
import {
    connectPlatform,
    destroy as destroyAccount,
    index as accountsIndex,
    update as updateAccount,
} from '@/routes/business/accounts';

type Option = { value: string; label: string };

type Account = {
    id: number;
    platform: string;
    handle: string;
    display_name: string | null;
    timezone: string;
    language: string | null;
    country: string | null;
    connection_status: string;
    token_expiry: string | null;
    last_health_error: string | null;
    can: { update: boolean; delete: boolean; connect: boolean };
};

type PlatformCard = {
    value: string;
    label: string;
    hosted_connect: boolean;
    account: Account | null;
};

type Props = {
    platforms: PlatformCard[];
    timezones: Option[];
    defaults: {
        timezone: string;
        language: string | null;
        country: string | null;
    };
    can: { create: boolean };
};

export default function BusinessAccounts({
    platforms,
    timezones,
    defaults,
    can,
}: Props) {
    const [connecting, setConnecting] = useState<string | null>(null);
    const [editing, setEditing] = useState<Account | null>(null);

    const connect = async (platform: string) => {
        setConnecting(platform);

        const response = await http.post<{ url: string }>(
            connectPlatform(platform).url,
            {},
            { toastOnError: true },
        );

        setConnecting(null);

        if (response.ok && response.data.url) {
            window.open(response.data.url, '_blank', 'noopener,noreferrer');
            toast.message('Finish logging in on the new tab, then come back.');
        }
    };

    const remove = async (account: Account) => {
        const confirmed = await confirmDialog({
            title: `Remove @${account.handle}?`,
            description:
                'Scheduled posts to this account will stop until you reconnect it.',
            confirmLabel: 'Remove',
            tone: 'destructive',
        });

        if (confirmed) {
            router.delete(destroyAccount(account.id).url, {
                preserveScroll: true,
            });
        }
    };

    return (
        <Page>
            <Head title="Accounts" />

            <ChapterHero
                eyebrow="Where you post"
                title={
                    <>
                        Your <em>accounts</em>.
                    </>
                }
                description="Connect the places you want to post. No API keys — just log in, and we handle the rest."
                aside={
                    <HeroStat
                        icon={Link2}
                        eyebrow="Connected"
                        value={String(
                            platforms.filter((card) => card.account).length,
                        ).padStart(2, '0')}
                        caption={`of ${platforms.length} channels`}
                    />
                }
            />

            <div className="grid gap-4 sm:grid-cols-2">
                {platforms.map((card) => {
                    const Icon = platformIcon(card.value);
                    const account = card.account;
                    const isConnected =
                        account?.connection_status === 'connected';
                    const busy = connecting === card.value;

                    return (
                        <Panel key={card.value} bodyClassName="p-5">
                            <div className="flex items-start gap-4">
                                <span className="bg-accent text-accent-foreground flex size-11 shrink-0 items-center justify-center rounded-full">
                                    <Icon className="size-5" />
                                </span>
                                <div className="flex min-w-0 flex-1 flex-col gap-2">
                                    <div className="flex min-w-0 flex-col gap-1">
                                        <span className="font-semibold">
                                            {card.label}
                                        </span>
                                        {account ? (
                                            <>
                                                <span className="text-muted-foreground truncate text-sm">
                                                    @{account.handle}
                                                </span>
                                                <StatusPill
                                                    kind="connection"
                                                    value={
                                                        account.connection_status
                                                    }
                                                    className="self-start"
                                                />
                                            </>
                                        ) : (
                                            <span className="text-muted-foreground text-sm">
                                                Not connected yet.
                                            </span>
                                        )}
                                    </div>

                                    {account?.last_health_error && (
                                        <p className="text-destructive text-xs">
                                            {account.last_health_error}
                                        </p>
                                    )}
                                    {account?.token_expiry && (
                                        <p className="text-muted-foreground text-xs">
                                            {account.token_expiry}
                                        </p>
                                    )}

                                    <div className="mt-1 flex flex-wrap items-center gap-2">
                                        {can.create && (
                                            <Button
                                                size="sm"
                                                variant={
                                                    isConnected
                                                        ? 'outline'
                                                        : 'brand'
                                                }
                                                disabled={busy}
                                                onClick={() =>
                                                    void connect(card.value)
                                                }
                                            >
                                                {busy ? (
                                                    <Loader2 className="animate-spin" />
                                                ) : (
                                                    <Link2 />
                                                )}
                                                {isConnected
                                                    ? 'Reconnect'
                                                    : 'Connect'}
                                            </Button>
                                        )}
                                        {account?.can.update && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setEditing(account)
                                                }
                                            >
                                                <Settings2 />
                                                Advanced
                                            </Button>
                                        )}
                                        {account?.can.delete && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    void remove(account)
                                                }
                                            >
                                                <Unplug />
                                                Remove
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </Panel>
                    );
                })}
            </div>

            <EditAccountDialog
                account={editing}
                onOpenChange={(open) => !open && setEditing(null)}
                timezones={timezones}
                defaults={defaults}
            />
        </Page>
    );
}

function EditAccountDialog({
    account,
    onOpenChange,
    timezones,
    defaults,
}: {
    account: Account | null;
    onOpenChange: (open: boolean) => void;
    timezones: Option[];
    defaults: Props['defaults'];
}) {
    const [handle, setHandle] = useState('');
    const [timezone, setTimezone] = useState(defaults.timezone);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    // Seed the form each time a different account opens the dialog.
    const [seededFor, setSeededFor] = useState<number | null>(null);
    if (account && account.id !== seededFor) {
        setSeededFor(account.id);
        setHandle(account.handle);
        setTimezone(account.timezone ?? defaults.timezone);
        setErrors({});
    }

    const submit = () => {
        if (!account) {
            return;
        }

        setSaving(true);

        router.put(
            updateAccount(account.id).url,
            { platform: account.platform, handle, timezone },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: (formErrors) => setErrors(formErrors),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={account !== null} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Advanced settings</DialogTitle>
                    <DialogDescription>
                        Fine-tune how this account posts. We've filled these in
                        from your brand.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4 py-2">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="handle">Handle</Label>
                        <Input
                            id="handle"
                            value={handle}
                            onChange={(event) => setHandle(event.target.value)}
                            placeholder="yourbrand"
                        />
                        <InputError message={errors.handle} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="timezone">Timezone</Label>
                        <Select value={timezone} onValueChange={setTimezone}>
                            <SelectTrigger id="timezone">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {timezones.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.timezone} />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="brand"
                        disabled={saving || !handle}
                        onClick={submit}
                    >
                        Save
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

BusinessAccounts.layout = {
    breadcrumbs: [{ title: 'Accounts', href: accountsIndex() }],
};
