import { Form, Head } from '@inertiajs/react';
import { CheckCircle2, MessageSquareWarning } from 'lucide-react';
import { useState } from 'react';

import type { AssetPreviewData } from '@/components/asset-preview';
import InputError from '@/components/input-error';
import { platformIcon } from '@/components/platform-icon';
import { ReviewChanges } from '@/components/posts/review-changes';
import { PublicShell, PublicTitle } from '@/components/public-shell';
import { SocialPostPreview } from '@/components/social-post-preview';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { formatSchedule } from '@/lib/dates';
import type { MediaFormat } from '@/lib/media-format';
import type { RevisionDiff } from '@/lib/post-diff';
import { cn } from '@/lib/utils';
import { decide, previewFeedback } from '@/routes/review';

type AssetData = AssetPreviewData;

type TargetData = {
    platform: string | null;
    handle: string | null;
    scheduled_local_date: string | null;
    scheduled_local_time: string | null;
    /** Step M-2.2 / M-2.3: exactly what publishes on this channel. */
    published_caption?: string;
    first_comment?: string | null;
    format?: string | null;
};

type CommentData = {
    id: number;
    user_name: string | null;
    body: string;
    created_at: string | null;
};

type PostData = {
    id: number;
    status: string;
    status_label: string;
    master_caption: string | null;
    review_message: string | null;
    hashtags: string[];
    music: { name?: string } | null;
    location: { name?: string } | null;
    media_format: MediaFormat;
    assets: AssetData[];
    targets: TargetData[];
    can_decide: boolean;
    /** Step B-3.4: a share-preview viewer may leave a comment. */
    can_comment?: boolean;
    /** Step 18.9: the grid whose look the client already approved. */
    grid_name?: string | null;
    /** Step M-5.3: what changed since the client last reviewed it (null on a first review). */
    changes_since_review?: RevisionDiff | null;
    comments: CommentData[];
};

/**
 * The client's one-link review (no account needed). The post is the hero — rendered as it will
 * actually look on each channel — with the studio's note, the schedule and exactly two decisions
 * beside it (stacked above/below on mobile, note field above the decisions).
 */
export default function ReviewShow({
    token,
    client,
    post,
    mode = 'client',
}: {
    token: string;
    client: { name: string };
    post: PostData;
    /** Step B-3.4: 'preview' = a brand/creator "Share preview" link (brand copy, no studio voice). */
    mode?: 'client' | 'preview';
}) {
    const isPreview = mode === 'preview';
    // A preview link's decision/comment goes to the no-status-change endpoint; the agency
    // client-review link keeps running the status machine via `decide`.
    const feedbackForm = isPreview
        ? previewFeedback.form(token)
        : decide.form(token);
    const previewTargets = post.targets
        .filter((target) => target.platform)
        .map((target, index) => ({
            id: index,
            platform: target.platform ?? '',
            handle: target.handle ?? client.name,
            publishedCaption: target.published_caption,
            firstComment: target.first_comment,
            format: target.format,
        }));
    const [activeTargetId, setActiveTargetId] = useState<number>(
        previewTargets[0]?.id ?? 0,
    );
    const activeTarget =
        previewTargets.find((target) => target.id === activeTargetId) ??
        previewTargets[0] ??
        null;

    return (
        <>
            <Head title={`Review a post — ${client.name}`} />
            <PublicShell width="xl" context={client.name}>
                <PublicTitle
                    kicker={isPreview ? client.name : `For ${client.name}`}
                    title={
                        post.can_decide || post.can_comment ? (
                            isPreview ? (
                                <>
                                    A <em>preview</em> for you.
                                </>
                            ) : (
                                <>
                                    Ready for your <em>review</em>.
                                </>
                            )
                        ) : isPreview ? (
                            <>
                                A <em>preview</em>.
                            </>
                        ) : (
                            <>
                                Thanks — it&apos;s <em>reviewed</em>.
                            </>
                        )
                    }
                >
                    <StatusPill
                        kind="post"
                        value={post.status}
                        label={post.status_label}
                    />
                </PublicTitle>

                {post.grid_name && (
                    <p className="border-border bg-card text-muted-foreground -mt-2 rounded-xl border px-4 py-3 text-sm">
                        You approved how this looks in{' '}
                        <span className="text-foreground font-medium">
                            {post.grid_name}
                        </span>
                        . This review is about the words.
                    </p>
                )}

                <ReviewChanges changes={post.changes_since_review ?? null} />

                <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_400px] lg:gap-10">
                    {post.review_message && (
                        <figure className="bg-ink text-ink-foreground relative rounded-2xl p-6 lg:col-start-2 lg:row-start-1">
                            <span
                                aria-hidden
                                className="text-lime font-display absolute -top-3 left-6 text-5xl leading-none font-bold"
                            >
                                “
                            </span>
                            <blockquote className="text-xl leading-snug font-light whitespace-pre-wrap">
                                {post.review_message}
                            </blockquote>
                            <figcaption className="eyebrow text-ink-foreground/55 mt-3">
                                A note from the studio
                            </figcaption>
                        </figure>
                    )}

                    <section
                        aria-label="Post preview"
                        className="border-border bg-card flex flex-col items-center gap-4 rounded-2xl border p-4 shadow-sm sm:p-8 lg:col-start-1 lg:row-span-3 lg:row-start-1"
                    >
                        {previewTargets.length > 1 && (
                            <div
                                role="tablist"
                                aria-label="Preview by channel"
                                className="bg-muted flex w-full max-w-md gap-1 overflow-x-auto rounded-full p-1"
                            >
                                {previewTargets.map((target) => {
                                    const Icon = platformIcon(target.platform);
                                    const selected =
                                        target.id === activeTarget?.id;

                                    return (
                                        <button
                                            key={target.id}
                                            type="button"
                                            role="tab"
                                            aria-selected={selected}
                                            onClick={() =>
                                                setActiveTargetId(target.id)
                                            }
                                            className={cn(
                                                'flex flex-1 items-center justify-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium whitespace-nowrap capitalize transition-colors',
                                                selected
                                                    ? 'bg-card text-foreground shadow-sm'
                                                    : 'text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            <Icon className="size-3.5" />
                                            {target.platform}
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                        {activeTarget ? (
                            <SocialPostPreview
                                target={activeTarget}
                                caption={post.master_caption ?? ''}
                                hashtags={post.hashtags}
                                assets={post.assets}
                                musicName={post.music?.name ?? ''}
                                locationName={post.location?.name ?? ''}
                                mediaFormat={post.media_format}
                                className="w-full max-w-md"
                            />
                        ) : (
                            <p
                                dir="auto"
                                className="text-[15px] leading-relaxed whitespace-pre-wrap"
                            >
                                {post.master_caption}
                            </p>
                        )}
                        <p className="eyebrow text-muted-foreground">
                            A close approximation — not pixel-perfect.
                        </p>
                    </section>

                    <div className="flex flex-col gap-6 lg:sticky lg:top-6 lg:col-start-2">
                        {post.targets.length > 0 && (
                            <section className="flex flex-col gap-2">
                                <h2 className="eyebrow text-muted-foreground border-foreground/85 border-b pb-2.5">
                                    Going out
                                </h2>
                                <ul className="divide-border/70 flex flex-col divide-y">
                                    {post.targets.map((target, i) => {
                                        const Icon = platformIcon(
                                            target.platform,
                                        );

                                        return (
                                            <li
                                                key={i}
                                                className="flex items-center justify-between gap-3 py-2.5 text-sm"
                                            >
                                                <span className="flex min-w-0 items-center gap-2.5">
                                                    <Icon className="text-muted-foreground size-4 shrink-0" />
                                                    <span className="truncate font-medium">
                                                        {target.handle ??
                                                            target.platform}
                                                    </span>
                                                </span>
                                                <span className="text-muted-foreground shrink-0 font-mono text-xs tabular-nums">
                                                    {formatSchedule(
                                                        target.scheduled_local_date,
                                                        target.scheduled_local_time,
                                                        'Not scheduled yet',
                                                    )}
                                                </span>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </section>
                        )}

                        {post.can_decide ? (
                            <Form
                                {...feedbackForm}
                                className="border-border bg-card flex flex-col gap-4 rounded-2xl border p-5 shadow-sm sm:p-6"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <p className="font-display text-xl leading-tight">
                                            How does it look?
                                        </p>
                                        <div className="grid gap-1.5">
                                            <textarea
                                                name="comment"
                                                rows={3}
                                                aria-label="Note (optional)"
                                                placeholder="Add a note (optional) — e.g. what you'd like changed"
                                                className="border-input bg-background placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/40 flex w-full rounded-lg border-2 px-3.5 py-2.5 text-sm outline-none focus-visible:ring-[3px]"
                                            />
                                            <InputError
                                                message={errors.comment}
                                            />
                                        </div>
                                        <InputError message={errors.decision} />
                                        <div className="flex flex-col gap-3">
                                            <Button
                                                type="submit"
                                                name="decision"
                                                value="approved"
                                                size="lg"
                                                className="bg-success text-success-foreground hover:bg-success/90 h-14 text-base"
                                                disabled={processing}
                                            >
                                                <CheckCircle2 className="size-5" />
                                                {isPreview
                                                    ? 'Looks good'
                                                    : 'Approve'}
                                            </Button>
                                            <Button
                                                type="submit"
                                                name="decision"
                                                value="changes_requested"
                                                size="lg"
                                                variant="outline"
                                                className="border-destructive/35 text-destructive hover:border-destructive/60 hover:bg-destructive/5 h-14 text-base"
                                                disabled={processing}
                                            >
                                                <MessageSquareWarning className="size-5" />
                                                Request changes
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        ) : post.can_comment ? (
                            <Form
                                {...feedbackForm}
                                className="border-border bg-card flex flex-col gap-4 rounded-2xl border p-5 shadow-sm sm:p-6"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <p className="font-display text-xl leading-tight">
                                            Any thoughts?
                                        </p>
                                        <div className="grid gap-1.5">
                                            <textarea
                                                name="comment"
                                                rows={3}
                                                aria-label="Your comment"
                                                placeholder="Leave a comment"
                                                className="border-input bg-background placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/40 flex w-full rounded-lg border-2 px-3.5 py-2.5 text-sm outline-none focus-visible:ring-[3px]"
                                            />
                                            <InputError
                                                message={errors.comment}
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            size="lg"
                                            disabled={processing}
                                        >
                                            Send comment
                                        </Button>
                                    </>
                                )}
                            </Form>
                        ) : (
                            <div className="border-border text-muted-foreground flex items-center gap-3 rounded-2xl border border-dashed p-5 text-sm">
                                <CheckCircle2 className="text-success size-5 shrink-0" />
                                {isPreview
                                    ? 'This is a read-only preview — thanks for taking a look.'
                                    : 'This post has already been reviewed — no further action is needed.'}
                            </div>
                        )}

                        {post.comments.length > 0 && (
                            <section className="flex flex-col gap-3">
                                <h2 className="eyebrow text-muted-foreground border-foreground/85 border-b pb-2.5">
                                    Conversation
                                </h2>
                                {post.comments.map((comment) => (
                                    <div
                                        key={comment.id}
                                        className="border-border bg-card rounded-2xl rounded-tl-sm border p-4 text-sm"
                                    >
                                        <p className="whitespace-pre-wrap">
                                            {comment.body}
                                        </p>
                                        <p className="text-muted-foreground mt-2 text-xs">
                                            — {comment.user_name ?? 'Team'}
                                        </p>
                                    </div>
                                ))}
                            </section>
                        )}
                    </div>
                </div>
            </PublicShell>
        </>
    );
}
