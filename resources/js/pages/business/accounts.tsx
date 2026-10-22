import { Head, router } from '@inertiajs/react';
import { Link2, Loader2, Plus, Unplug } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { confirmDialog } from '@/components/confirm-dialog';
import { EmptyState, Page, PageHeader, Panel } from '@/components/page';
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
    connect as connectAccount,
    destroy as destroyAccount,
    index as accountsIndex,
    store as storeAccount,
} from '@/routes/business/accounts';

type Option = { value: string; label: string };

type Account = {
    id: number;
    platform: string;
    handle: string;
    display_name: string | null;
    connection_status: string;
    token_expiry: string | null;
    can: { update: boolean; delete: boolean; connect: boolean };
};

type Props = {
    accounts: Account[];
    platforms: Option[];
    timezones: Option[];
    defaults: {
        timezone: string;
        language: string | null;
        country: string | null;
    };
    can: { create: boolean };
};

export default function BusinessAccounts({
    accounts,
    platforms,
    timezones,
    defaults,
    can,
}: Props) {
    const [adding, setAdding] = useState(false);
    const [connectingId, setConnectingId] = useState<number | null>(null);

    const connect = async (account: Account) => {
        setConnectingId(account.id);

        const response = await http.post<{ url: string }>(
            connectAccount(account.id).url,
            {},
            { toastOnError: true },
        );

        setConnectingId(null);

        if (response.ok && response.data.url) {
            window.open(response.data.url, '_blank', 'noopener,noreferrer');
            toast.message('Finish connecting in the new tab, then come back.');
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

            <PageHeader
                eyebrow="Where you post"
                title={
                    <>
                        Your <em>accounts</em>.
                    </>
                }
                description="Connect the places you want to post. No API keys — just log in."
                actions={
                    can.create &&
                    platforms.length > 0 && (
                        <Button variant="brand" onClick={() => setAdding(true)}>
                            <Plus />
                            Add an account
                        </Button>
                    )
                }
            />

            {accounts.length === 0 ? (
                <EmptyState
                    icon={Link2}
                    title="No accounts connected."
                    description="Add your first account to start scheduling posts to it."
                    action={
                        can.create &&
                        platforms.length > 0 && (
                            <Button
                                variant="brand"
                                onClick={() => setAdding(true)}
                            >
                                <Plus />
                                Add an account
                            </Button>
                        )
                    }
                />
            ) : (
                <div className="grid gap-4 sm:grid-cols-2">
                    {accounts.map((account) => {
                        const Icon = platformIcon(account.platform);

                        return (
                            <Panel key={account.id} bodyClassName="p-5">
                                <div className="flex items-start gap-4">
                                    <span className="bg-accent text-accent-foreground flex size-11 shrink-0 items-center justify-center rounded-full">
                                        <Icon className="size-5" />
                                    </span>
                                    <div className="flex min-w-0 flex-1 flex-col gap-2">
                                        <div className="flex min-w-0 flex-col gap-1">
                                            <span className="truncate font-medium">
                                                @{account.handle}
                                            </span>
                                            <StatusPill
                                                kind="connection"
                                                value={
                                                    account.connection_status
                                                }
                                                className="self-start"
                                            />
                                        </div>
                                        {account.token_expiry && (
                                            <p className="text-muted-foreground text-xs">
                                                {account.token_expiry}
                                            </p>
                                        )}
                                        <div className="mt-1 flex flex-wrap items-center gap-2">
                                            {account.can.connect && (
                                                <Button
                                                    size="sm"
                                                    variant={
                                                        account.connection_status ===
                                                        'connected'
                                                            ? 'outline'
                                                            : 'brand'
                                                    }
                                                    disabled={
                                                        connectingId ===
                                                        account.id
                                                    }
                                                    onClick={() =>
                                                        void connect(account)
                                                    }
                                                >
                                                    {connectingId ===
                                                    account.id ? (
                                                        <Loader2 className="animate-spin" />
                                                    ) : (
                                                        <Link2 />
                                                    )}
                                                    {account.connection_status ===
                                                    'connected'
                                                        ? 'Reconnect'
                                                        : 'Connect'}
                                                </Button>
                                            )}
                                            {account.can.delete && (
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
            )}

            <AddAccountDialog
                open={adding}
                onOpenChange={setAdding}
                platforms={platforms}
                timezones={timezones}
                defaults={defaults}
            />
        </Page>
    );
}

function AddAccountDialog({
    open,
    onOpenChange,
    platforms,
    timezones,
    defaults,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    platforms: Option[];
    timezones: Option[];
    defaults: Props['defaults'];
}) {
    const [platform, setPlatform] = useState('');
    const [handle, setHandle] = useState('');
    const [timezone, setTimezone] = useState(defaults.timezone);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const submit = () => {
        setSaving(true);

        router.post(
            storeAccount().url,
            { platform, handle, timezone },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    setPlatform('');
                    setHandle('');
                    setErrors({});
                },
                onError: (formErrors) => setErrors(formErrors),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add an account</DialogTitle>
                    <DialogDescription>
                        Pick a platform and your handle. You'll connect it right
                        after.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4 py-2">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="platform">Platform</Label>
                        <Select value={platform} onValueChange={setPlatform}>
                            <SelectTrigger id="platform">
                                <SelectValue placeholder="Choose a platform" />
                            </SelectTrigger>
                            <SelectContent>
                                {platforms.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.platform} />
                    </div>

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

                    <details className="group">
                        <summary className="text-muted-foreground hover:text-foreground cursor-pointer text-sm">
                            Advanced
                        </summary>
                        <div className="mt-3 flex flex-col gap-1.5">
                            <Label htmlFor="timezone">Timezone</Label>
                            <Select
                                value={timezone}
                                onValueChange={setTimezone}
                            >
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
                    </details>
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
                        disabled={saving || !platform || !handle}
                        onClick={submit}
                    >
                        Add account
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

BusinessAccounts.layout = {
    breadcrumbs: [{ title: 'Accounts', href: accountsIndex() }],
};
