import { Link, router } from '@inertiajs/react';
import { CalendarClock, CalendarOff } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/page';
import type { Paginated } from '@/components/pagination';
import { Pagination } from '@/components/pagination';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { edit as editPost } from '@/routes/posts';

export type UnscheduledPost = {
    id: number;
    client_name: string | null;
    status: string;
    status_label: string;
    master_caption: string | null;
    updated_at: string | null;
};

/**
 * Step I-2.3 — posts with no dated target can't appear on the grid, so they live in this drawer.
 * The list is an optional Inertia prop: nothing loads until the drawer opens.
 */
export function UnscheduledPostsSheet({
    count,
    page,
}: {
    count: number;
    page?: Paginated<UnscheduledPost>;
}) {
    const [loading, setLoading] = useState(false);

    const load = (pageNumber: number) =>
        router.reload({
            only: ['unscheduled'],
            data: { unscheduled_page: pageNumber },
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });

    return (
        <Sheet
            onOpenChange={(open) => {
                if (open && !page) load(1);
            }}
        >
            <SheetTrigger asChild>
                <Button variant="outline" size="sm">
                    <CalendarClock />
                    Unscheduled
                    <span className="font-mono text-xs tabular-nums">
                        {count}
                    </span>
                </Button>
            </SheetTrigger>
            <SheetContent className="flex w-full flex-col gap-0 sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>Unscheduled posts</SheetTitle>
                    <SheetDescription>
                        Posts without a date yet. Give them one in the editor
                        and they’ll show up on the calendar.
                    </SheetDescription>
                </SheetHeader>
                <div className="flex flex-1 flex-col gap-3 overflow-y-auto px-4 pb-4">
                    {loading && !page ? (
                        Array.from({ length: 5 }, (_, i) => (
                            <Skeleton key={i} className="h-14 rounded-xl" />
                        ))
                    ) : page && page.data.length > 0 ? (
                        <>
                            <ul className="divide-border/70 bg-card flex flex-col divide-y overflow-hidden rounded-2xl border">
                                {page.data.map((post) => (
                                    <li key={post.id}>
                                        <Link
                                            href={editPost(post.id)}
                                            className="group hover:bg-muted/50 flex items-center justify-between gap-3 px-4 py-3 transition-colors"
                                        >
                                            <span className="flex min-w-0 flex-col">
                                                <span className="group-hover:text-primary truncate text-sm font-medium">
                                                    {post.client_name ??
                                                        'Unknown client'}
                                                </span>
                                                <span className="text-muted-foreground truncate text-xs">
                                                    {post.master_caption ??
                                                        'Untitled post'}
                                                </span>
                                            </span>
                                            <StatusPill
                                                kind="post"
                                                value={post.status}
                                                label={post.status_label}
                                            />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                            <Pagination
                                page={page}
                                label="posts"
                                onPageChange={load}
                            />
                        </>
                    ) : (
                        <EmptyState
                            compact
                            icon={CalendarOff}
                            title="All dated."
                            description="Every post matching these filters has a date."
                        />
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}
