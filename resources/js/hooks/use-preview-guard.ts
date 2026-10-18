import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { decidePreviewVisit } from '@/lib/preview';

/**
 * Step I-1.1 — while the `preview` shared prop is true, every Inertia visit is checked by
 * `decidePreviewVisit()`: mirrored pages are re-routed to `/preview/*`, writes and unmirrored
 * pages are cancelled with a toast. The backend rejects them anyway (403 for preview writes, a
 * login redirect elsewhere); this keeps the visitor from ever leaving the mirror by accident.
 */
export function usePreviewGuard(): void {
    const { preview } = usePage().props;

    useEffect(() => {
        if (!preview) {
            return;
        }

        return router.on('before', (event) => {
            const { visit } = event.detail;
            const decision = decidePreviewVisit(visit.url, visit.method);

            if (decision.action === 'allow') {
                return;
            }

            event.preventDefault();

            if (decision.action === 'redirect') {
                router.visit(decision.url, {
                    preserveScroll: visit.preserveScroll,
                    preserveState: visit.preserveState,
                    replace: visit.replace,
                });

                return;
            }

            toast.info(decision.reason);
        });
    }, [preview]);
}

/** True on every `/preview/*` page — use it to hide write controls in reused components. */
export function useIsPreview(): boolean {
    return Boolean(usePage().props.preview);
}
