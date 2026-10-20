import { Form } from '@inertiajs/react';
import { Mail, RefreshCw, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import type {
    ActivityLogData,
    TargetData,
} from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';
import { retryFailedTargets } from '@/routes/posts';
import postActivityLogs from '@/routes/posts/activity-logs';

/** The review link the client was emailed, with a resend. */
export function ReviewLinkRow({
    postId,
    log,
    canResend,
}: {
    postId: number;
    log: ActivityLogData;
    canResend: boolean;
}) {
    return (
        <div className="border-border bg-muted/40 flex flex-wrap items-center gap-2 rounded-xl border px-3 py-2">
            <Mail className="text-muted-foreground size-4 shrink-0" />
            <span className="text-muted-foreground min-w-0 flex-1 text-xs">
                Review link emailed to the client. Re-sending issues a fresh
                link and turns off the old one.
            </span>
            {canResend && (
                <Form
                    {...postActivityLogs.resendReviewEmail.form([
                        postId,
                        log.id,
                    ])}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={() => toast.success('Fresh review link sent.')}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                        >
                            <Mail />
                            {processing ? 'Sending…' : 'Resend email'}
                        </Button>
                    )}
                </Form>
            )}
        </div>
    );
}

/**
 * Step I-1.3 — shown on a `failed`/`partially_published` post: which channels failed, and one
 * button that resends only those (channels that already went out are never touched).
 */
export function RetryFailedTargetsRow({
    postId,
    failedTargets,
}: {
    postId: number;
    failedTargets: TargetData[];
}) {
    return (
        <div className="border-destructive/30 bg-destructive/5 flex flex-wrap items-center gap-2 rounded-xl border px-3 py-2">
            <TriangleAlert className="text-destructive size-4 shrink-0" />
            <span className="min-w-0 flex-1 text-xs">
                {failedTargets.length === 1
                    ? '1 channel failed: '
                    : `${failedTargets.length} channels failed: `}
                <span className="text-muted-foreground">
                    {failedTargets
                        .map((target) =>
                            [target.platform, target.handle]
                                .filter(Boolean)
                                .join(' · '),
                        )
                        .join(', ')}
                </span>
            </span>
            <Form
                {...retryFailedTargets.form(postId)}
                options={{ preserveScroll: true }}
            >
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        disabled={processing}
                    >
                        <RefreshCw />
                        {processing ? 'Retrying…' : 'Retry failed channels'}
                    </Button>
                )}
            </Form>
        </div>
    );
}
