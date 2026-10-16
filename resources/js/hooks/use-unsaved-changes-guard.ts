import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { confirmDialog } from '@/components/confirm-dialog';

/**
 * Asks (with the app's styled confirm dialog, never a native browser one) before an Inertia
 * navigation leaves a page with unsaved edits. The visit is held, and re-issued only if the user
 * chooses to leave.
 *
 * Tab close / refresh is deliberately not guarded: browsers only allow their own native
 * `beforeunload` prompt there, which can't be styled.
 */
export function useUnsavedChangesGuard(isDirty: boolean): void {
    useEffect(() => {
        if (!isDirty) {
            return;
        }

        let leaving = false;

        const removeRouterListener = router.on('before', (event) => {
            const visit = event.detail.visit;

            // Only real navigations: skip form submits, hover prefetches and partial reloads.
            if (
                leaving ||
                visit.method !== 'get' ||
                visit.prefetch ||
                visit.async ||
                visit.only.length > 0
            ) {
                return;
            }

            void confirmDialog({
                title: 'Leave without saving?',
                description:
                    "You have unsaved changes on this page. If you leave now, they'll be lost.",
                confirmLabel: 'Leave page',
                cancelLabel: 'Keep editing',
                tone: 'destructive',
            }).then((ok) => {
                if (!ok) {
                    return;
                }

                leaving = true;
                router.visit(visit.url.href, {
                    replace: visit.replace,
                    preserveScroll: visit.preserveScroll,
                    preserveState: visit.preserveState,
                });
            });

            return false;
        });

        return removeRouterListener;
    }, [isDirty]);
}
