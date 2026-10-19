import { CheckCircle2, Link2, RefreshCw } from 'lucide-react';
import { PlatformIcon } from '@/components/platform-icon';
import { Button } from '@/components/ui/button';

export type ConnectSlot = {
    id: number;
    platform: string;
    platform_label: string;
    handle: string;
    connected: boolean;
    needs_reconnect: boolean;
};

/**
 * Step M-2.8 — the account slots a client can connect themselves (self-connect page, portal
 * panel). Each row shows its state as an icon and words, never colour alone.
 */
export function ConnectSlotList({
    slots,
    connectingId,
    onConnect,
}: {
    slots: ConnectSlot[];
    connectingId: number | null;
    onConnect: (slot: ConnectSlot) => void;
}) {
    return (
        <ul className="border-border divide-border/70 bg-card divide-y overflow-hidden rounded-2xl border">
            {slots.map((slot) => (
                <li
                    key={slot.id}
                    className="flex items-center gap-3 px-4 py-3.5"
                >
                    <PlatformIcon platform={slot.platform} />
                    <span className="flex min-w-0 flex-1 flex-col">
                        <span className="truncate text-sm font-medium">
                            {slot.handle}
                        </span>
                        <span className="text-muted-foreground truncate text-xs">
                            {slot.platform_label}
                            {slot.connected && ' · Connected'}
                            {slot.needs_reconnect && ' · Needs reconnecting'}
                        </span>
                    </span>
                    {slot.connected ? (
                        <span className="text-success flex items-center gap-1.5 text-sm font-medium">
                            <CheckCircle2 className="size-4" />
                            Connected
                        </span>
                    ) : (
                        <Button
                            type="button"
                            size="sm"
                            variant={
                                slot.needs_reconnect ? 'outline' : 'default'
                            }
                            disabled={connectingId !== null}
                            onClick={() => onConnect(slot)}
                        >
                            {slot.needs_reconnect ? <RefreshCw /> : <Link2 />}
                            {connectingId === slot.id
                                ? 'Opening…'
                                : slot.needs_reconnect
                                  ? 'Reconnect'
                                  : 'Connect'}
                        </Button>
                    )}
                </li>
            ))}
        </ul>
    );
}
