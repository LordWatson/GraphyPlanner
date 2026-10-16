import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * The app's replacement for `window.confirm()` — never use native browser dialogs. Call it like
 * `toast()`, from anywhere (components, hooks, router listeners):
 *
 *   if (await confirmDialog({ title: 'Discard changes?', confirmLabel: 'Discard', tone: 'destructive' })) { … }
 *
 * Resolves `true` on confirm, `false` on cancel/escape/overlay click. Rendered by the single
 * `<ConfirmDialogHost />` mounted in `app.tsx`.
 */
export type ConfirmOptions = {
    title: string;
    description?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    tone?: 'default' | 'destructive';
};

type PendingConfirm = ConfirmOptions & { resolve: (ok: boolean) => void };

let pushConfirm: ((pending: PendingConfirm) => void) | null = null;

export function confirmDialog(options: ConfirmOptions): Promise<boolean> {
    return new Promise((resolve) => {
        if (!pushConfirm) {
            // Host not mounted (shouldn't happen) — fail safe by not proceeding.
            resolve(false);

            return;
        }

        pushConfirm({ ...options, resolve });
    });
}

export function ConfirmDialogHost() {
    const [pending, setPending] = useState<PendingConfirm | null>(null);
    const [open, setOpen] = useState(false);

    useEffect(() => {
        pushConfirm = (next) => {
            setPending((current) => {
                // A new request supersedes an unanswered one.
                current?.resolve(false);

                return next;
            });
            setOpen(true);
        };

        return () => {
            pushConfirm = null;
        };
    }, []);

    const settle = (ok: boolean) => {
        pending?.resolve(ok);
        setPending((current) => (current === pending ? null : current));
        setOpen(false);
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && settle(false)}>
            <DialogContent className="sm:max-w-md">
                {pending && (
                    <>
                        <DialogHeader>
                            <DialogTitle>{pending.title}</DialogTitle>
                            {pending.description && (
                                <DialogDescription>
                                    {pending.description}
                                </DialogDescription>
                            )}
                        </DialogHeader>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => settle(false)}
                            >
                                {pending.cancelLabel ?? 'Cancel'}
                            </Button>
                            <Button
                                type="button"
                                autoFocus
                                variant={
                                    pending.tone === 'destructive'
                                        ? 'destructive'
                                        : 'default'
                                }
                                onClick={() => settle(true)}
                            >
                                {pending.confirmLabel ?? 'Continue'}
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
