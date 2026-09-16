import { Eye } from 'lucide-react';
import { usePage } from '@inertiajs/react';

/**
 * Step 0.14 — Preview mode banner. Rendered whenever the `preview` shared Inertia prop is true
 * (set by the `preview` middleware for every `/preview/*` request), so visitors always know
 * they're looking at frozen fixture data and can't accidentally believe they're editing a real
 * client's account.
 */
export function PreviewBanner() {
    const { preview } = usePage().props;

    if (!preview) {
        return null;
    }

    return (
        <div className="flex items-center justify-center gap-2 border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
            <Eye className="size-4 shrink-0" />
            <span>
                Preview mode — you&apos;re viewing frozen sample data. Nothing here can be edited, saved, or published.
            </span>
        </div>
    );
}
