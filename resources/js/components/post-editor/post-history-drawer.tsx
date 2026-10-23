import { router } from '@inertiajs/react';
import { History, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { confirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { formatDateTime } from '@/lib/dates';
import { http } from '@/lib/http';
import type { RevisionDiff } from '@/lib/post-diff';
import { summarizeDiff } from '@/lib/post-diff';
import {
    index as revisionsIndex,
    restore as restoreRevision,
} from '@/routes/posts/revisions';

type Revision = {
    id: number;
    author: string | null;
    shared: boolean;
    created_at: string | null;
    diff: RevisionDiff;
};

/**
 * Step M-5.3 — the editor's version history. Lists every saved version newest-first with a plain
 * "what changed", and restores one (which is itself a save). Loaded lazily when opened.
 */
export function PostHistoryDrawer({
    postId,
    canRestore,
}: {
    postId: number;
    canRestore: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [revisions, setRevisions] = useState<Revision[] | null>(null);
    const [restoring, setRestoring] = useState<number | null>(null);

    const load = async (next: boolean) => {
        setOpen(next);

        if (next && revisions === null) {
            const result = await http.get<{ revisions: Revision[] }>(
                revisionsIndex(postId).url,
                { toastOnError: true },
            );

            setRevisions(result.ok ? result.data.revisions : []);
        }
    };

    const restore = async (revision: Revision) => {
        const ok = await confirmDialog({
            title: 'Restore this version?',
            description:
                'The post’s caption, hashtags, media and per-channel copy go back to this version. The schedule is kept, and this restore is saved as a new version.',
            confirmLabel: 'Restore',
        });

        if (!ok) {
            return;
        }

        setRestoring(revision.id);
        router.post(
            restoreRevision({ post: postId, revision: revision.id }).url,
            {},
            { onFinish: () => setRestoring(null) },
        );
    };

    return (
        <Sheet open={open} onOpenChange={load}>
            <SheetTrigger asChild>
                <Button variant="outline">
                    <History />
                    History
                </Button>
            </SheetTrigger>
            <SheetContent className="flex w-full flex-col gap-0 sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>Version history</SheetTitle>
                    <SheetDescription>
                        Every saved version of this post, newest first.
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 overflow-y-auto px-4 pb-6">
                    {revisions === null ? (
                        <p className="text-muted-foreground py-8 text-center text-sm">
                            Loading…
                        </p>
                    ) : revisions.length === 0 ? (
                        <p className="text-muted-foreground py-8 text-center text-sm">
                            No saved versions yet. They build up as you edit.
                        </p>
                    ) : (
                        <ol className="flex flex-col gap-3">
                            {revisions.map((revision, index) => {
                                const lines = summarizeDiff(revision.diff);
                                const isCurrent = index === 0;

                                return (
                                    <li
                                        key={revision.id}
                                        className="border-border bg-card rounded-xl border p-3"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="flex items-center gap-2 text-sm font-medium">
                                                {isCurrent
                                                    ? 'Current version'
                                                    : formatDateTime(
                                                          revision.created_at,
                                                      )}
                                                {revision.shared && (
                                                    <Badge variant="electric">
                                                        Shown to client
                                                    </Badge>
                                                )}
                                            </span>
                                            {canRestore && !isCurrent && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    disabled={
                                                        restoring ===
                                                        revision.id
                                                    }
                                                    onClick={() =>
                                                        void restore(revision)
                                                    }
                                                >
                                                    <RotateCcw />
                                                    Restore
                                                </Button>
                                            )}
                                        </div>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            {[
                                                !isCurrent &&
                                                    formatDateTime(
                                                        revision.created_at,
                                                    ),
                                                revision.author,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') || 'Saved'}
                                        </p>
                                        {lines.length > 0 && (
                                            <ul className="mt-2 flex flex-col gap-0.5 text-xs">
                                                {lines.map((line, i) => (
                                                    <li
                                                        key={i}
                                                        className="text-foreground/80"
                                                    >
                                                        {line}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </li>
                                );
                            })}
                        </ol>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}
