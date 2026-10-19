import { Form, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Clock,
    Link2,
    Settings2,
    Share2,
    Trash2,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import type { ConnectInviteData } from '@/components/clients/connect-invite-panel';
import { ConnectInvitePanel } from '@/components/clients/connect-invite-panel';
import InputError from '@/components/input-error';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useConnectPopup } from '@/hooks/use-connect-popup';
import { formatDateTime } from '@/lib/dates';
import type { PlatformCapability } from '@/lib/platforms';
import { capabilityOf, usePlatforms } from '@/lib/platforms';
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
    update as updateSocialAccount,
} from '@/routes/social-accounts';
import type { ClientPageProps } from '@/components/clients/client-layout';
import {
    ClientLayout,
    TabHeading,
    TabPanel,
    usePlatformOptions,
} from '@/components/clients/client-layout';
import { platformIcon } from '@/components/platform-icon';

type SocialAccountData = {
    id: number;
    platform: string;
    handle: string;
    display_name: string | null;
    timezone: string;
    language: string | null;
    country: string | null;
    connection_status: string;
    /** Step M-2.6: the registry settings (e.g. Pinterest board) and the required ones missing. */
    platform_settings: Record<string, string>;
    missing_settings: string[];
    /** Step M-2.7: the last health check, its error, and when the stored token expires. */
    last_health_check_at: string | null;
    last_health_error: string | null;
    token_expires_at: string | null;
    token_expiry: string | null;
    can: { update: boolean; delete: boolean; connect: boolean };
};

/** One input per registry setting for a platform (`platform_settings[key]`). */
function SettingsFields({
    capability,
    values,
    errors,
}: {
    capability: PlatformCapability;
    values?: Record<string, string>;
    errors: Record<string, string | undefined>;
}) {
    const settings = Object.entries(capability.settings);

    if (settings.length === 0) {
        return null;
    }

    return (
        <>
            {settings.map(([key, setting]) => (
                <div key={key} className="grid content-start gap-1">
                    <Label htmlFor={`setting-${key}`}>
                        {setting.label}
                        {!setting.required && (
                            <span className="text-muted-foreground font-normal">
                                {' '}
                                (optional)
                            </span>
                        )}
                    </Label>
                    <Input
                        id={`setting-${key}`}
                        name={`platform_settings[${key}]`}
                        defaultValue={values?.[key] ?? ''}
                        required={setting.required}
                    />
                    {setting.help && (
                        <p className="text-muted-foreground text-xs">
                            {setting.help}
                        </p>
                    )}
                    <InputError message={errors[`platform_settings.${key}`]} />
                </div>
            ))}
        </>
    );
}

/** Edit an account's registry settings (keeps every other field as it is). */
function AccountSettingsDialog({
    account,
    capability,
    onClose,
}: {
    account: SocialAccountData;
    capability: PlatformCapability;
    onClose: () => void;
}) {
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <Form
                    {...updateSocialAccount.form(account.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    {capability.label} settings
                                </DialogTitle>
                                <DialogDescription>
                                    What {capability.label} needs to publish for{' '}
                                    {account.handle}.
                                </DialogDescription>
                            </DialogHeader>
                            <input
                                type="hidden"
                                name="platform"
                                value={account.platform}
                            />
                            <input
                                type="hidden"
                                name="handle"
                                value={account.handle}
                            />
                            <input
                                type="hidden"
                                name="display_name"
                                value={account.display_name ?? ''}
                            />
                            <input
                                type="hidden"
                                name="timezone"
                                value={account.timezone}
                            />
                            <SettingsFields
                                capability={capability}
                                values={account.platform_settings}
                                errors={errors}
                            />
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onClose}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Saving…' : 'Save settings'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Step I-2.4 — Social accounts — the client page's default tab (`clients.show`).
 */
export default function ClientAccountsTab({
    socialAccounts,
    timezones,
    connectInvites = null,
    ...page
}: ClientPageProps & {
    socialAccounts: SocialAccountData[];
    timezones: { value: string; label: string }[];
    /** Step M-2.8: live self-connect links (null when the viewer can't create them). */
    connectInvites?: ConnectInviteData[] | null;
}) {
    const platformOptions = usePlatformOptions(true);
    const registry = usePlatforms();
    const { client, can } = page;
    const [newPlatform, setNewPlatform] = useState('instagram');
    const [editing, setEditing] = useState<SocialAccountData | null>(null);
    const refreshAccounts = useCallback(
        () => router.reload({ only: ['socialAccounts', 'stats'] }),
        [],
    );
    const { connectingId, connect } = useConnectPopup(refreshAccounts);
    const accountPlatforms = [
        ...new Set(socialAccounts.map((account) => account.platform)),
    ].map((value) => ({
        value,
        label: capabilityOf(registry, value).label || value,
    }));
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
                                                value={newPlatform}
                                                onValueChange={setNewPlatform}
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
                                        <SettingsFields
                                            capability={capabilityOf(
                                                registry,
                                                newPlatform,
                                            )}
                                            errors={errors}
                                        />
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
                                    const PlatformIcon = platformIcon(
                                        account.platform,
                                    );
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
                                                    {account.missing_settings
                                                        .length > 0 && (
                                                        <span className="text-warning flex items-center gap-1 text-xs">
                                                            <AlertTriangle className="size-3.5" />
                                                            Add the{' '}
                                                            {account.missing_settings
                                                                .map(
                                                                    (key) =>
                                                                        capabilityOf(
                                                                            registry,
                                                                            account.platform,
                                                                        )
                                                                            .settings[
                                                                            key
                                                                        ]
                                                                            ?.label ??
                                                                        key,
                                                                )
                                                                .join(', ')
                                                                .toLowerCase()}{' '}
                                                            before it can
                                                            publish.
                                                        </span>
                                                    )}
                                                    {account.token_expiry && (
                                                        <span className="text-warning flex items-center gap-1 text-xs">
                                                            <Clock className="size-3.5" />
                                                            Connection{' '}
                                                            {
                                                                account.token_expiry
                                                            }{' '}
                                                            — reconnect it.
                                                        </span>
                                                    )}
                                                    {account.last_health_error ? (
                                                        <span className="text-destructive flex items-center gap-1 text-xs">
                                                            <AlertTriangle className="size-3.5" />
                                                            {
                                                                account.last_health_error
                                                            }
                                                        </span>
                                                    ) : (
                                                        account.last_health_check_at && (
                                                            <span className="text-muted-foreground text-[11px]">
                                                                Checked{' '}
                                                                {formatDateTime(
                                                                    account.last_health_check_at,
                                                                )}
                                                            </span>
                                                        )
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-2">
                                                {account.can.update &&
                                                    Object.keys(
                                                        capabilityOf(
                                                            registry,
                                                            account.platform,
                                                        ).settings,
                                                    ).length > 0 && (
                                                        <Button
                                                            type="button"
                                                            size="icon"
                                                            variant="outline"
                                                            title="Settings"
                                                            onClick={() =>
                                                                setEditing(
                                                                    account,
                                                                )
                                                            }
                                                        >
                                                            <Settings2 />
                                                            <span className="sr-only">
                                                                Settings
                                                            </span>
                                                        </Button>
                                                    )}
                                                {account.can.connect &&
                                                    account.connection_status !==
                                                        'connected' && (
                                                        <Button
                                                            type="button"
                                                            size="icon"
                                                            variant="outline"
                                                            disabled={
                                                                connectingId ===
                                                                account.id
                                                            }
                                                            title="Connect"
                                                            onClick={() =>
                                                                void connect(
                                                                    account.id,
                                                                    connectSocialAccount.url(
                                                                        account.id,
                                                                    ),
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

                        {connectInvites !== null &&
                            socialAccounts.length > 0 && (
                                <ConnectInvitePanel
                                    clientId={client.id}
                                    clientName={client.name}
                                    invites={connectInvites}
                                    platforms={accountPlatforms}
                                />
                            )}

                        {editing && (
                            <AccountSettingsDialog
                                account={editing}
                                capability={capabilityOf(
                                    registry,
                                    editing.platform,
                                )}
                                onClose={() => setEditing(null)}
                            />
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
