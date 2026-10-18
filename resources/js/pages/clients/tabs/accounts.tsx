import { Form, router } from '@inertiajs/react';
import { Link2, Share2, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/clients';
import { store as storeSocialAccount } from '@/routes/clients/social-accounts';
import {
    connect as connectSocialAccount,
    destroy as destroySocialAccount,
} from '@/routes/social-accounts';
import type { ClientPageProps } from '@/components/clients/client-layout';
import {
    ClientLayout,
    TabHeading,
    TabPanel,
    getXsrfTokenFromCookie,
    platformIcon,
    platformOptions,
} from '@/components/clients/client-layout';

type SocialAccountData = {
    id: number;
    platform: string;
    handle: string;
    display_name: string | null;
    timezone: string;
    language: string | null;
    country: string | null;
    connection_status: string;
    can: { update: boolean; delete: boolean; connect: boolean };
};

/**
 * Step I-2.4 — Social accounts — the client page's default tab (`clients.show`).
 */
export default function ClientAccountsTab({
    socialAccounts,
    timezones,
    ...page
}: ClientPageProps & {
    socialAccounts: SocialAccountData[];
    timezones: { value: string; label: string }[];
}) {
    const { client, can } = page;
    const [connectingAccountId, setConnectingAccountId] = useState<
        number | null
    >(null);

    // The vendor's hosted connect flow finishes in the popup opened by `handleConnect` below, not
    // in this tab, so we listen for the confirmation `postMessage` it sends (see
    // `resources/views/social-accounts/connected.blade.php`) and refresh the accounts list here.
    useEffect(() => {
        const handleMessage = (event: MessageEvent) => {
            if (event.origin !== window.location.origin) {
                return;
            }

            if (event.data?.type === 'graphy:social-account-connected') {
                router.reload({ only: ['socialAccounts', 'stats'] });
            }
        };

        window.addEventListener('message', handleMessage);

        return () => window.removeEventListener('message', handleMessage);
    }, []);

    // Opens the vendor's hosted connect page in a separate popup/tab (instead of taking over this
    // one), and refreshes the social accounts list once that popup is closed as a fallback in case
    // the `postMessage` confirmation above didn't arrive.
    const handleConnect = async (accountId: number) => {
        setConnectingAccountId(accountId);

        const popup = window.open('about:blank', `graphy-connect-${accountId}`);

        try {
            const response = await fetch(connectSocialAccount.url(accountId), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': getXsrfTokenFromCookie(),
                },
            });

            const data = await response.json();

            if (!response.ok || !data.url) {
                popup?.close();
                toast.error(
                    data.message ??
                        'Unable to start the Upload-Post connect flow.',
                );
                return;
            }

            if (popup) {
                popup.location.href = data.url;

                const interval = window.setInterval(() => {
                    if (popup.closed) {
                        window.clearInterval(interval);
                        router.reload({ only: ['socialAccounts', 'stats'] });
                    }
                }, 1000);
            } else {
                // Popup was blocked — fall back to a plain new tab.
                window.open(data.url, '_blank');
            }
        } catch {
            popup?.close();
            toast.error('Unable to start the Upload-Post connect flow.');
        } finally {
            setConnectingAccountId(null);
        }
    };
    return (
        <ClientLayout {...page}>
            <TabPanel>
                <TabHeading
                    icon={Share2}
                    title="Social accounts"
                    description="Platforms this client posts to"
                />

                <>
                    <>
                        {can.createSocialAccount && (
                            <Form
                                {...storeSocialAccount.form(client.id)}
                                resetOnSuccess
                                className="border-border bg-card/50 grid grid-cols-2 gap-3 rounded-2xl border border-dashed p-4 shadow-sm sm:grid-cols-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-1">
                                            <Label htmlFor="platform">
                                                Platform
                                            </Label>
                                            <Select
                                                name="platform"
                                                defaultValue="instagram"
                                            >
                                                <SelectTrigger
                                                    id="platform"
                                                    className="w-full"
                                                >
                                                    <SelectValue placeholder="Select a platform" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {platformOptions.map(
                                                        (platform) => (
                                                            <SelectItem
                                                                key={
                                                                    platform.value
                                                                }
                                                                value={
                                                                    platform.value
                                                                }
                                                            >
                                                                {platform.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={errors.platform}
                                            />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="handle">
                                                Handle
                                            </Label>
                                            <Input
                                                id="handle"
                                                name="handle"
                                                required
                                            />
                                            <InputError
                                                message={errors.handle}
                                            />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="display_name">
                                                Display name
                                            </Label>
                                            <Input
                                                id="display_name"
                                                name="display_name"
                                            />
                                            <InputError
                                                message={errors.display_name}
                                            />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="timezone">
                                                Timezone (IANA)
                                            </Label>
                                            <Select name="timezone">
                                                <SelectTrigger
                                                    id="timezone"
                                                    className="w-full"
                                                >
                                                    <SelectValue placeholder="Select a timezone" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {timezones.map(
                                                        (timezone) => (
                                                            <SelectItem
                                                                key={
                                                                    timezone.value
                                                                }
                                                                value={
                                                                    timezone.value
                                                                }
                                                            >
                                                                {timezone.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={errors.timezone}
                                            />
                                        </div>
                                        <div className="col-span-full">
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                Add social account
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}

                        {socialAccounts.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No social accounts yet.
                            </p>
                        ) : (
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                {socialAccounts.map((account) => {
                                    const PlatformIcon =
                                        platformIcon[account.platform] ??
                                        Share2;
                                    return (
                                        <div
                                            key={account.id}
                                            className="border-border bg-card hover:border-primary/30 hover:bg-muted/40 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3 shadow-sm transition-colors"
                                        >
                                            <div className="flex min-w-0 items-center gap-3">
                                                <span className="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-full">
                                                    <PlatformIcon className="size-4" />
                                                </span>
                                                <div className="flex min-w-0 flex-col gap-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="text-sm font-medium">
                                                            {account.handle}
                                                        </span>
                                                        <StatusPill
                                                            kind="connection"
                                                            value={
                                                                account.connection_status
                                                            }
                                                        />
                                                    </div>
                                                    <span className="text-muted-foreground text-xs">
                                                        {account.display_name ??
                                                            account.handle}{' '}
                                                        · {account.timezone}
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-2">
                                                {account.can.connect &&
                                                    account.connection_status !==
                                                        'connected' && (
                                                        <Button
                                                            type="button"
                                                            size="icon"
                                                            variant="outline"
                                                            disabled={
                                                                connectingAccountId ===
                                                                account.id
                                                            }
                                                            title="Connect"
                                                            onClick={() =>
                                                                handleConnect(
                                                                    account.id,
                                                                )
                                                            }
                                                        >
                                                            <Link2 />
                                                            <span className="sr-only">
                                                                Connect
                                                            </span>
                                                        </Button>
                                                    )}
                                                {account.can.delete && (
                                                    <Form
                                                        {...destroySocialAccount.form(
                                                            account.id,
                                                        )}
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                type="submit"
                                                                size="icon"
                                                                variant="outline"
                                                                disabled={
                                                                    processing
                                                                }
                                                                title="Remove"
                                                            >
                                                                <Trash2 />
                                                                <span className="sr-only">
                                                                    Remove
                                                                </span>
                                                            </Button>
                                                        )}
                                                    </Form>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </>
                </>
            </TabPanel>
        </ClientLayout>
    );
}

ClientAccountsTab.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
