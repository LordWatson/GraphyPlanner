import { Form, router } from '@inertiajs/react';
import {
    Check,
    Copy,
    Link2,
    Mail,
    RefreshCw,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import type {
    ActivityLogData,
    TargetData,
} from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useClipboard } from '@/hooks/use-clipboard';
import { postJson } from '@/lib/campaigns';
import { retryFailedTargets, reviewLink } from '@/routes/posts';
import postActivityLogs from '@/routes/posts/activity-logs';

/**
 * The client review link for a post waiting on the client: copy a fresh link to share over any
 * channel (no email contact needed), and — when one was emailed — resend it. Either action mints a
 * fresh link and turns the previous one off, so only the newest ever works (Step I-1.6).
 */
export function ReviewLinkRow({
    postId,
    log,
    canResend,
}: {
    postId: number;
    /** The "review email sent" activity log, when the client was emailed; absent otherwise. */
    log?: ActivityLogData;
    canResend: boolean;
}) {
    const [fresh, setFresh] = useState<string | null>(null);
    const [minting, setMinting] = useState(false);
    const [copiedText, copy] = useClipboard();

    const makeLink = async () => {
        setMinting(true);
        const result = await postJson<{ url: string }>(reviewLink.url(postId));
        setMinting(false);

        if (!result.ok) {
            toast.error(result.message);

            return;
        }

        setFresh(result.data.url);

        if (await copy(result.data.url)) {
            toast.success('Review link copied. Paste it to the client.');
        } else {
            toast.message('Review link ready — copy it below.');
        }
    };

    return (
        <div className="border-border bg-muted/40 flex flex-col gap-2 rounded-xl border px-3 py-2.5">
            <div className="flex items-start gap-2">
                <Link2 className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                <span className="text-muted-foreground min-w-0 flex-1 text-xs">
                    Share this post’s review link with the client — over email,
                    or anywhere you like. A fresh link turns the previous one
                    off.
                </span>
            </div>

            {fresh && (
                <div className="flex gap-2">
                    <Input
                        readOnly
                        value={fresh}
                        onFocus={(event) => event.currentTarget.select()}
                        className="h-9 font-mono text-xs"
                        aria-label="Review link"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => void copy(fresh)}
                    >
                        {copiedText === fresh ? <Check /> : <Copy />}
                        {copiedText === fresh ? 'Copied' : 'Copy'}
                    </Button>
                </div>
            )}

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => void makeLink()}
                    disabled={minting}
                >
                    <Link2 />
                    {minting
                        ? 'Making a link…'
                        : fresh
                          ? 'New link'
                          : 'Copy review link'}
                </Button>
                {canResend && log && (
                    <Form
                        {...postActivityLogs.resendReviewEmail.form([
                            postId,
                            log.id,
                        ])}
                        options={{ preserveScroll: true, preserveState: true }}
                        onSuccess={() =>
                            toast.success('Fresh review link sent.')
                        }
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
        </div>
    );
}

/**
 * Step I-1.3 / B-5.3 — shown on a `failed`/`partially_published` post: each failed channel with a
 * plain-language reason and the one thing to do next, a per-channel retry (channels that already
 * went out are never touched), and a single "Retry all" when more than one channel can retry.
 */
export function RetryFailedTargetsRow({
    postId,
    failedTargets,
}: {
    postId: number;
    failedTargets: TargetData[];
}) {
    const [retrying, setRetrying] = useState<number | 'all' | null>(null);

    const retry = (target?: number) => {
        setRetrying(target ?? 'all');
        router.post(retryFailedTargets(postId).url, target ? { target } : {}, {
            preserveScroll: true,
            onFinish: () => setRetrying(null),
        });
    };

    const retryableCount = failedTargets.filter(
        (target) => target.failure?.retryable ?? true,
    ).length;

    return (
        <div className="border-destructive/30 bg-destructive/5 flex flex-col gap-3 rounded-xl border px-3 py-3">
            <div className="flex items-center gap-2">
                <TriangleAlert className="text-destructive size-4 shrink-0" />
                <span className="text-xs font-medium">
                    {failedTargets.length === 1
                        ? "A channel didn't go out."
                        : `${failedTargets.length} channels didn't go out.`}
                </span>
            </div>

            <ul className="flex flex-col gap-2.5">
                {failedTargets.map((target) => {
                    const channel = [target.platform, target.handle]
                        .filter(Boolean)
                        .join(' · ');
                    const retryable = target.failure?.retryable ?? true;
                    const busy = retrying === target.id || retrying === 'all';

                    return (
                        <li
                            key={target.id}
                            className="border-border/70 bg-card flex flex-wrap items-start gap-x-3 gap-y-1.5 rounded-lg border px-3 py-2"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="text-[13px] font-medium">
                                    {channel || 'A channel'}
                                </p>
                                <p className="text-muted-foreground text-xs text-pretty">
                                    {target.failure?.message ??
                                        target.error ??
                                        'This channel failed.'}{' '}
                                    {target.failure?.fix && (
                                        <span className="text-foreground">
                                            {target.failure.fix}
                                        </span>
                                    )}
                                </p>
                            </div>
                            {retryable && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={busy}
                                    onClick={() => retry(target.id)}
                                >
                                    <RefreshCw />
                                    {retrying === target.id
                                        ? 'Retrying…'
                                        : 'Retry'}
                                </Button>
                            )}
                        </li>
                    );
                })}
            </ul>

            {retryableCount > 1 && (
                <div className="flex justify-end">
                    <Button
                        type="button"
                        variant="brand"
                        size="sm"
                        disabled={retrying !== null}
                        onClick={() => retry()}
                    >
                        <RefreshCw />
                        {retrying === 'all'
                            ? 'Retrying…'
                            : 'Retry all failed channels'}
                    </Button>
                </div>
            )}
        </div>
    );
}
