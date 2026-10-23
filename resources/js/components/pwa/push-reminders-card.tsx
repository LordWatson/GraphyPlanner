import { Bell, BellOff, Loader2 } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { usePush } from '@/hooks/use-push';
import { useWorkspace } from '@/hooks/use-workspace';

/**
 * Step B-7.4 — the per-browser push opt-in, shown on Profile settings for business workspaces. It
 * only renders when push is supported and configured, so an agency user or an unsupported browser
 * never sees a dead control. The subscription lives per browser, so the copy says so.
 */
export function PushRemindersCard() {
    const { isBusiness } = useWorkspace();
    const {
        supported,
        configured,
        subscribed,
        busy,
        blocked,
        subscribe,
        unsubscribe,
    } = usePush();

    if (!isBusiness || !supported || !configured) {
        return null;
    }

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Post reminders"
                description="Get a nudge on this device before a post goes out."
            />

            {blocked ? (
                <p className="text-muted-foreground text-sm">
                    Notifications are blocked for Alima in this browser. Turn
                    them back on in your browser settings to use reminders.
                </p>
            ) : (
                <div className="flex items-center gap-4">
                    {subscribed ? (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={unsubscribe}
                        >
                            {busy ? (
                                <Loader2 className="animate-spin" />
                            ) : (
                                <BellOff />
                            )}
                            Turn off reminders
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            variant="brand"
                            disabled={busy}
                            onClick={subscribe}
                        >
                            {busy ? (
                                <Loader2 className="animate-spin" />
                            ) : (
                                <Bell />
                            )}
                            Turn on reminders
                        </Button>
                    )}
                    <p className="text-muted-foreground text-sm">
                        {subscribed
                            ? "You'll be reminded on this device."
                            : 'Reminders are per device — turn them on wherever you post from.'}
                    </p>
                </div>
            )}
        </div>
    );
}
