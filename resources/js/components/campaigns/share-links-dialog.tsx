import { router } from '@inertiajs/react';
import { Check, Copy, Link2, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { confirmDialog } from '@/components/confirm-dialog';
import type { CampaignShareLink } from '@/components/campaigns/types';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { postJson } from '@/lib/campaigns';
import { formatDate } from '@/lib/dates';
import { destroy, store } from '@/routes/campaigns/share-links';

/**
 * Campaigns enhancement C-5.4 — a read-only preview link for people without portal access (the
 * client's CEO, a franchise owner). The full link is shown once, right after it's made; after
 * that only its expiry and views are listed, each with a switch-off.
 */
export function ShareLinksDialog({
    campaignId,
    links,
    open,
    onOpenChange,
}: {
    campaignId: number;
    links: CampaignShareLink[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [days, setDays] = useState('14');
    const [busy, setBusy] = useState(false);
    const [fresh, setFresh] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    const create = async () => {
        setBusy(true);
        const result = await postJson<{ url: string }>(store.url(campaignId), {
            days: Number(days),
        });
        setBusy(false);

        if (!result.ok) {
            toast.error(result.message);

            return;
        }

        setFresh(result.data.url);
        setCopied(false);
        router.reload({ only: ['shareLinks'] });
    };

    const copy = async () => {
        if (!fresh) {
            return;
        }

        try {
            await navigator.clipboard.writeText(fresh);
            setCopied(true);
        } catch {
            toast.error('Couldn’t copy. Select the link and copy it instead.');
        }
    };

    const revoke = async (link: CampaignShareLink) => {
        if (
            await confirmDialog({
                title: 'Switch this link off?',
                description:
                    'Anyone holding it will see nothing. You can always make a new one.',
                confirmLabel: 'Switch off',
                tone: 'destructive',
            })
        ) {
            router.delete(
                destroy.url({ campaign: campaignId, shareLink: link.id }),
                { preserveScroll: true },
            );
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);

                if (!next) {
                    setFresh(null);
                }
            }}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Share a preview</DialogTitle>
                    <DialogDescription>
                        A read-only link to this campaign’s approved and
                        scheduled posts. No sign-in, no decisions, no comments.
                    </DialogDescription>
                </DialogHeader>

                {fresh ? (
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="share-link">
                            Copy it now. It won’t be shown again.
                        </Label>
                        <div className="flex gap-2">
                            <Input
                                id="share-link"
                                readOnly
                                value={fresh}
                                onFocus={(event) =>
                                    event.currentTarget.select()
                                }
                                className="font-mono text-xs"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                onClick={copy}
                            >
                                {copied ? <Check /> : <Copy />}
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                    </div>
                ) : (
                    <div className="flex items-end gap-2">
                        <div className="grid flex-1 gap-1.5">
                            <Label htmlFor="share-days">Works for (days)</Label>
                            <Input
                                id="share-days"
                                type="number"
                                min={1}
                                max={90}
                                value={days}
                                onChange={(event) =>
                                    setDays(event.target.value)
                                }
                            />
                        </div>
                        <Button
                            type="button"
                            onClick={create}
                            disabled={
                                busy || Number(days) < 1 || Number(days) > 90
                            }
                        >
                            <Link2 />
                            Make a link
                        </Button>
                    </div>
                )}

                {links.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <span className="eyebrow text-muted-foreground">
                            Live links
                        </span>
                        <ul className="divide-border/70 flex flex-col divide-y text-sm">
                            {links.map((link) => (
                                <li
                                    key={link.id}
                                    className="flex items-center justify-between gap-3 py-2"
                                >
                                    <span className="flex flex-col">
                                        <span>
                                            Until {formatDate(link.expires_at)}
                                        </span>
                                        <span className="text-muted-foreground text-xs">
                                            {link.created_by ?? 'Someone'} ·{' '}
                                            {link.view_count}{' '}
                                            {link.view_count === 1
                                                ? 'view'
                                                : 'views'}
                                        </span>
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => revoke(link)}
                                    >
                                        <Trash2 />
                                        Switch off
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
