import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

type PendingConfirm = ConfirmOptions & {
    withPassword?: boolean;
    resolve: (ok: boolean, password?: string) => void;
};

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

/**
 * Step I-16.2 / M-1.2 — the same dialog with a password field, for irreversible actions the
 * server re-checks (`current_password`). Resolves the typed password, or `null` on cancel. The
 * password is only held long enough to send with the request.
 */
export function confirmWithPassword(
    options: ConfirmOptions,
): Promise<string | null> {
    return new Promise((resolve) => {
        if (!pushConfirm) {
            resolve(null);

            return;
        }

        pushConfirm({
            ...options,
            withPassword: true,
            resolve: (ok, password) => resolve(ok ? (password ?? '') : null),
        });
    });
}

export function ConfirmDialogHost() {
    const [pending, setPending] = useState<PendingConfirm | null>(null);
    const [open, setOpen] = useState(false);
    const [password, setPassword] = useState('');

    useEffect(() => {
        pushConfirm = (next) => {
            setPending((current) => {
                // A new request supersedes an unanswered one.
                current?.resolve(false);

                return next;
            });
            setPassword('');
            setOpen(true);
        };

        return () => {
            pushConfirm = null;
        };
    }, []);

    const settle = (ok: boolean) => {
        pending?.resolve(ok, ok ? password : undefined);
        setPending((current) => (current === pending ? null : current));
        setPassword('');
        setOpen(false);
    };

    const blocked = pending?.withPassword === true && password === '';

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
                        {pending.withPassword && (
                            <form
                                id="confirm-dialog-password"
                                className="grid gap-2"
                                onSubmit={(event) => {
                                    event.preventDefault();

                                    if (!blocked) {
                                        settle(true);
                                    }
                                }}
                            >
                                <Label htmlFor="confirm-dialog-password-input">
                                    Your password
                                </Label>
                                <Input
                                    id="confirm-dialog-password-input"
                                    type="password"
                                    autoComplete="current-password"
                                    autoFocus
                                    value={password}
                                    onChange={(event) =>
                                        setPassword(event.target.value)
                                    }
                                />
                            </form>
                        )}
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
                                autoFocus={!pending.withPassword}
                                disabled={blocked}
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
