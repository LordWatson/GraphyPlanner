import { Head, router } from '@inertiajs/react';
import { LinkIcon, LockKeyhole, ShieldCheck } from 'lucide-react';
import { useCallback } from 'react';
import type { ConnectSlot } from '@/components/connect-slots';
import { ConnectSlotList } from '@/components/connect-slots';
import { EmptyState } from '@/components/page';
import { PublicShell, PublicTitle } from '@/components/public-shell';
import { useConnectPopup } from '@/hooks/use-connect-popup';
import { formatDate } from '@/lib/dates';
import { account as connectAccount } from '@/routes/connect';

/**
 * Step M-2.8 — the page a client opens from their self-connect link. No login, no password: they
 * sign in to each network on the network's own page (in a popup), and the agency can publish.
 * An expired or revoked link shows a calm dead-end instead of anything about the client.
 */
export default function ConnectShow({
    token,
    client,
    agency,
    accounts,
    expires_at,
}: {
    token: string | null;
    client: { name: string } | null;
    agency: string | null;
    accounts: ConnectSlot[];
    expires_at: string | null;
}) {
    const refresh = useCallback(
        () => router.reload({ only: ['accounts'] }),
        [],
    );
    const { connectingId, connect } = useConnectPopup(refresh);

    if (token === null || client === null) {
        return (
            <PublicShell>
                <Head title="Link expired" />
                <EmptyState
                    icon={LinkIcon}
                    title="This link has expired."
                    description="Self-connect links only work for a short while. Ask your agency to send you a fresh one."
                />
            </PublicShell>
        );
    }

    const remaining = accounts.filter((slot) => !slot.connected).length;

    return (
        <PublicShell width="lg" context={client.name}>
            <Head title={`Connect ${client.name}`} />
            <div className="grid gap-8">
                <PublicTitle
                    kicker="Connect your accounts"
                    title={
                        <>
                            Let {agency ?? 'your agency'} <em>post for you.</em>
                        </>
                    }
                >
                    Sign in to each network on its own page. You never share a
                    password with anyone, and you can disconnect any time from
                    the network’s settings.
                </PublicTitle>

                {accounts.length > 0 ? (
                    <>
                        <ConnectSlotList
                            slots={accounts}
                            connectingId={connectingId}
                            onConnect={(slot) =>
                                void connect(
                                    slot.id,
                                    connectAccount.url([token, slot.id]),
                                )
                            }
                        />
                        <p className="text-muted-foreground text-sm">
                            {remaining === 0
                                ? 'All set — every account is connected. You can close this page.'
                                : `${remaining} ${remaining === 1 ? 'account' : 'accounts'} left to connect.`}
                        </p>
                    </>
                ) : (
                    <EmptyState
                        icon={LinkIcon}
                        title="Nothing to connect yet."
                        description={`${agency ?? 'Your agency'} hasn’t added any accounts to this link. Ask them to check.`}
                    />
                )}

                <ul className="text-muted-foreground grid gap-2 text-xs">
                    <li className="flex items-start gap-2">
                        <LockKeyhole className="mt-0.5 size-3.5 shrink-0" />
                        This link only connects {client.name}’s accounts and
                        shows nothing else.
                    </li>
                    <li className="flex items-start gap-2">
                        <ShieldCheck className="mt-0.5 size-3.5 shrink-0" />
                        {expires_at
                            ? `It stops working on ${formatDate(expires_at)}.`
                            : 'It stops working after a few days.'}
                    </li>
                </ul>
            </div>
        </PublicShell>
    );
}
