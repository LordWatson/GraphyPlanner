import { router } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { useState } from 'react';
import type { ConfirmOptions } from '@/components/confirm-dialog';
import { confirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';

type RouteDefinition = {
    url: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete';
};

/**
 * A button that asks for confirmation (via the app's `confirmDialog`, never `window.confirm`)
 * and then visits a Wayfinder route. Inertia's `onBefore` is synchronous, so an async confirm
 * can't gate a `<Form>` — this is the pattern for confirm-then-submit actions.
 */
export function ConfirmActionButton({
    action,
    confirm,
    children,
    ...buttonProps
}: {
    action: RouteDefinition;
    confirm: ConfirmOptions | null;
} & Omit<ComponentProps<typeof Button>, 'onClick' | 'type'>) {
    const [processing, setProcessing] = useState(false);

    const run = async () => {
        if (confirm && !(await confirmDialog(confirm))) {
            return;
        }

        router.visit(action.url, {
            method: action.method,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Button
            type="button"
            {...buttonProps}
            disabled={processing || buttonProps.disabled}
            onClick={run}
        >
            {children}
        </Button>
    );
}
