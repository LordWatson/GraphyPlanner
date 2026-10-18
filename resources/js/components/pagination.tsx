import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/** A Laravel `LengthAwarePaginator` as Inertia serialises it (the fields the UI reads). */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

/**
 * Step I-2 — previous/next pager for server-paginated lists. The caller decides how a page is
 * fetched (a visit with `?page=`, or a partial reload), so this stays a plain control.
 */
export function Pagination({
    page,
    onPageChange,
    label = 'items',
    className,
}: {
    page: Pick<
        Paginated<unknown>,
        'current_page' | 'last_page' | 'total' | 'from' | 'to'
    >;
    onPageChange: (page: number) => void;
    label?: string;
    className?: string;
}) {
    if (page.last_page <= 1) {
        return null;
    }

    return (
        <nav
            aria-label="Pagination"
            className={cn(
                'flex flex-wrap items-center justify-between gap-3',
                className,
            )}
        >
            <span className="text-muted-foreground font-mono text-xs tabular-nums">
                {page.from ?? 0}–{page.to ?? 0} of {page.total} {label}
            </span>
            <div className="flex items-center gap-1.5">
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-8"
                    aria-label="Previous page"
                    disabled={page.current_page <= 1}
                    onClick={() => onPageChange(page.current_page - 1)}
                >
                    <ChevronLeft />
                </Button>
                <span className="font-mono text-xs tabular-nums">
                    {page.current_page} / {page.last_page}
                </span>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-8"
                    aria-label="Next page"
                    disabled={page.current_page >= page.last_page}
                    onClick={() => onPageChange(page.current_page + 1)}
                >
                    <ChevronRight />
                </Button>
            </div>
        </nav>
    );
}
