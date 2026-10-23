import { router } from '@inertiajs/react';
import { Rocket, Send, Zap } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { SharePreviewDialog } from '@/components/post-editor/share-preview-dialog';
import { quickSchedule } from '@/actions/App/Http/Controllers/PostController';

type Props = {
    postId: number;
    status: string;
    requiresReview: boolean;
    hasTimes: boolean;
    canQuickSchedule: boolean;
    canSharePreview: boolean;
};

const SCHEDULABLE = ['idea', 'draft'];

/**
 * Step B-3.2 / B-3.4 — the business/creator editor's one-tap action bar. For a draft it offers the
 * single next move the owner actually wants — "Send for review" (review on), or "Post now" /
 * "Schedule" (review off) — instead of the full agency transition menu. Separately, "Share preview"
 * mints a read-only link for a partner or sponsor. The granular `PostWorkflow` panel stays below
 * for anything out of the ordinary.
 */
export function BusinessScheduleBar({
    postId,
    status,
    requiresReview,
    hasTimes,
    canQuickSchedule,
    canSharePreview,
}: Props) {
    const [busy, setBusy] = useState(false);
    const canSchedule = SCHEDULABLE.includes(status) && canQuickSchedule;

    const go = (when: 'schedule' | 'now') => {
        router.post(
            quickSchedule.url(postId),
            { when },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );
    };

    if (!canSchedule && !canSharePreview) {
        return null;
    }

    return (
        <div className="border-border bg-card flex flex-wrap items-center gap-3 rounded-2xl border p-4 shadow-sm">
            {canSchedule && (
                <>
                    <span className="text-muted-foreground mr-1 text-sm">
                        Ready to go out?
                    </span>

                    {requiresReview ? (
                        <Button
                            type="button"
                            onClick={() => go('schedule')}
                            disabled={busy}
                        >
                            <Send className="size-4" />
                            Send for review
                        </Button>
                    ) : (
                        <>
                            <Button
                                type="button"
                                onClick={() => go('now')}
                                disabled={busy}
                            >
                                <Zap className="size-4" />
                                Post now
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => go('schedule')}
                                disabled={busy || !hasTimes}
                                title={
                                    hasTimes
                                        ? undefined
                                        : 'Add a time on each channel first'
                                }
                            >
                                <Rocket className="size-4" />
                                Schedule
                            </Button>
                        </>
                    )}
                </>
            )}

            {canSharePreview && (
                <div className="ml-auto">
                    <SharePreviewDialog postId={postId} />
                </div>
            )}
        </div>
    );
}
