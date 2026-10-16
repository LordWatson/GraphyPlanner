import { usePage } from '@inertiajs/react';
import { Eye } from 'lucide-react';

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
        <div className="bg-ink text-ink-foreground flex items-center justify-center gap-2.5 px-4 py-2 text-[13px] md:rounded-t-2xl">
            <span className="eyebrow bg-lime text-ink inline-flex items-center gap-1.5 rounded-[3px] px-1.5 py-0.5 text-[10px]">
                <Eye className="size-3" />
                Preview
            </span>
            <span className="text-ink-foreground/80">
                You&apos;re viewing frozen sample data. Nothing here can be
                edited, saved, or published.
            </span>
        </div>
    );
}
