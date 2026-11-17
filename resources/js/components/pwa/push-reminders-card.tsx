import { Bell, BellOff, Loader2 } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { usePush } from '@/hooks/use-push';
import { useWorkspace } from '@/hooks/use-workspace';

/**
 * Step B-7.4 / S-3.2 — the per-browser push opt-in. A business workspace sees it on Profile
 * settings ("Post reminders"); an agency member sees it on Notification settings, where the only
 * push they get is "a story is ready to post". Each page names the workspace it renders the card
 * for, so nobody sees it twice. It only renders when push is supported and configured, so an
 * unsupported browser never shows a dead control. The subscription lives per browser, and the copy
 * says so.
 */
export function PushRemindersCard({
    workspace,
}: {
    workspace: 'business' | 'agency';
}) {
    const { isBusiness, isAgency } = useWorkspace();
    const {
        supported,
        configured,
        subscribed,
        busy,
        blocked,
        subscribe,
        unsubscribe,
    } = usePush();

    const forThisWorkspace = workspace === 'business' ? isBusiness : isAgency;

    if (!forThisWorkspace || !supported || !configured) {
        return null;
    }

    const copy =
        workspace === 'business'
            ? {
                  title: 'Post reminders',
                  description:
                      'Get a nudge on this device before a post goes out.',
                  on: "You'll be reminded on this device.",
                  off: 'Reminders are per device — turn them on wherever you post from.',
              }
            : {
                  title: 'Story reminders on this device',
                  description:
                      'Get a nudge on this device when a story is ready to post.',
                  on: "You'll get a nudge on this device.",
                  off: 'Reminders are per device. Turn them on from the phone you post with.',
              };

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title={copy.title}
                description={copy.description}
            />

            {blocked ? (
                <p className="text-muted-foreground text-sm">
                    Notifications are blocked for Alima in this browser. Turn
                    them back on in your browser settings to use reminders.
                </p>
            ) : (
                <div className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:gap-4">
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
                        {subscribed ? copy.on : copy.off}
                    </p>
                </div>
            )}
        </div>
    );
}
