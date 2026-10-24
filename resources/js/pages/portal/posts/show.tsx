import { Form, Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    CalendarClock,
    CheckCircle2,
    MessageSquareWarning,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useRef, useState } from 'react';

import { type AssetPreviewData } from '@/components/asset-preview';
import { FeedbackBoard } from '@/components/feedback/feedback-board';
import type { FeedbackThread } from '@/components/feedback/types';
import InputError from '@/components/input-error';
import { Page, PageHeader, Panel } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { SwipeToApprove } from '@/components/portal/swipe-to-approve';
import { ReviewChanges } from '@/components/posts/review-changes';
import { SocialPostPreview } from '@/components/social-post-preview';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { formatSchedule } from '@/lib/dates';
import type { MediaFormat } from '@/lib/media-format';
import type { RevisionDiff } from '@/lib/post-diff';
import { cn } from '@/lib/utils';
import {
    decide as decideOnPortalPost,
    index as portalPostsIndex,
    show as portalPostShow,
} from '@/routes/portal/posts';
import { resolve as resolveComment } from '@/routes/portal/comments';
import { store as commentOnPortalPost } from '@/routes/portal/posts/comments';

type TargetData = {
    social_account_id: number | null;
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
    mentioned_user_ids: number[];
    created_at: string | null;
};

type PostData = {
    id: number;
    status: string;
    status_label: string;
    master_caption: string | null;
    review_message: string | null;
    /** Step 18.9: the grid whose look the client already approved. */
    grid_name?: string | null;
    /** Step M-5.3: what changed since the client last reviewed it (null on a first review). */
    changes_since_review?: RevisionDiff | null;
    hashtags: string[];
    music: { name?: string } | null;
    location: { id?: string; name?: string } | null;
    media_format: MediaFormat;
    assets: AssetPreviewData[];
    targets: TargetData[];
    comments: CommentData[];
};

/** A plain-English headline for where the post stands, from the client's point of view. */
const headlines: Record<string, ReactNode> = {
    waiting_client: (
        <>
            Ready for your <em>review</em>.
        </>
    ),
    changes_requested: (
        <>
            Back with the <em>studio</em>.
        </>
    ),
    approved: (
        <>
            Approved — <em>thank you</em>.
        </>
    ),
    scheduled: (
        <>
            Scheduled to <em>go out</em>.
        </>
    ),
    publishing: (
        <>
            Going out <em>now</em>.
        </>
    ),
    published: (
        <>
            This one is <em>live</em>.
        </>
    ),
    failed: (
        <>
            The studio is <em>on it</em>.
        </>
    ),
    archived: (
        <>
            An <em>archived</em> post.
        </>
    ),
};

/**
 * Step 6.5 — the client portal's post detail/approval view: the authenticated counterpart to the
 * token-based `/review/:token` page. The post preview leads; the decision (when the post is
 * `waiting_client`) sits beside it with exactly two large, thumb-friendly choices (guidelines §7).
 */
type ApprovalData = {
    policy: string;
    policy_label: string;
    multi: boolean;
    waiting_on: string[];
    steps: Array<{
        approver: string;
        status: string;
        is_turn: boolean;
        decided_at: string | null;
    }>;
};

export default function PortalPostShow({
    post,
    can,
    approval,
    queue,
    certificate_available,
    feedback,
}: {
    post: PostData;
    can: { decide: boolean; comment: boolean };
    approval: ApprovalData | null;
    /** UX overhaul (review handoff): the reviewer's remaining approval queue. */
    queue: { remaining: number; next_id: number | null };
    certificate_available: boolean;
    feedback: FeedbackThread[];
}) {
    const previewTargets = post.targets.map((t, i) => ({
        id: t.social_account_id ?? i,
        platform: t.platform ?? '',
        handle: t.handle ?? '',
        publishedCaption: t.published_caption,
        firstComment: t.first_comment,
        format: t.format,
    }));

    const commentRef = useRef<HTMLTextAreaElement>(null);
    const [previewTargetId, setPreviewTargetId] = useState<
        number | string | null
    >(previewTargets[0]?.id ?? null);
    const activePreviewTarget =
        previewTargets.find((t) => t.id === previewTargetId) ??
        previewTargets[0] ??
        null;

    return (
        <>
            <Head title={`Post #${post.id} — Client portal`} />
            <Page>
                <Link
                    href={portalPostsIndex()}
                    className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" />
                    All posts
                </Link>

                <PageHeader
                    eyebrow={`Post #${post.id}${post.targets[0]?.platform ? ` — ${post.targets.map((t) => t.platform).join(' · ')}` : ''}`}
                    title={headlines[post.status] ?? `Post #${post.id}`}
                    meta={
                        <StatusPill
                            kind="post"
                            value={post.status}
                            label={post.status_label}
                        />
                    }
                />

                <div className="animate-rise grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
                    <section
                        aria-label="Post preview"
                        className="border-border bg-muted/40 flex flex-col items-center gap-4 rounded-2xl border p-4 sm:p-8 lg:sticky lg:top-36"
                    >
                        {previewTargets.length > 1 && (
                            <div
                                className="flex flex-wrap justify-center gap-1.5"
                                role="tablist"
                                aria-label="Preview account"
                            >
                                {previewTargets.map((t) => {
                                    const Icon = platformIcon(t.platform);
                                    const selected =
                                        t.id === activePreviewTarget?.id;

                                    return (
                                        <button
                                            key={t.id}
                                            type="button"
                                            role="tab"
                                            aria-selected={selected}
                                            onClick={() =>
                                                setPreviewTargetId(t.id)
                                            }
                                            className={cn(
                                                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs transition-colors',
                                                selected
                                                    ? 'bg-ink text-ink-foreground dark:bg-foreground dark:text-background border-transparent'
                                                    : 'border-border bg-card text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            <Icon className="size-3.5" />
                                            {t.handle}
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                        {activePreviewTarget ? (
                            <SocialPostPreview
                                target={activePreviewTarget}
                                caption={post.master_caption ?? ''}
                                hashtags={post.hashtags}
                                assets={post.assets}
                                musicName={post.music?.name ?? ''}
                                locationName={post.location?.name ?? ''}
                                mediaFormat={post.media_format}
                            />
                        ) : (
                            <p className="text-muted-foreground py-16 text-sm">
                                No account is attached to this post yet.
                            </p>
                        )}
                    </section>

                    <div className="flex flex-col gap-5">
                        {queue.remaining > 0 && (
                            <div className="border-info/25 bg-info/5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border p-4">
                                <div className="flex flex-col gap-0.5">
                                    <span className="eyebrow text-muted-foreground">
                                        Your review queue
                                    </span>
                                    <p className="text-sm">
                                        {queue.remaining} more{' '}
                                        {queue.remaining === 1
                                            ? 'post needs'
                                            : 'posts need'}{' '}
                                        your review.
                                    </p>
                                </div>
                                {queue.next_id !== null && (
                                    <Button asChild size="sm">
                                        <Link
                                            href={portalPostShow(queue.next_id)}
                                        >
                                            Review the next
                                            <ArrowRight className="size-4" />
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        )}
                        <ReviewChanges
                            changes={post.changes_since_review ?? null}
                        />
                        {post.grid_name && (
                            <p className="border-border bg-card text-muted-foreground rounded-xl border px-4 py-3 text-sm">
                                You approved how this looks in{' '}
                                <span className="text-foreground font-medium">
                                    {post.grid_name}
                                </span>
                                . This review is about the words.
                            </p>
                        )}
                        {post.review_message && (
                            <figure className="bg-ink text-ink-foreground relative rounded-2xl p-6">
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

                        {approval && (
                            <div className="border-border bg-card flex flex-col gap-3 rounded-2xl border p-5">
                                <div className="flex flex-col gap-0.5">
                                    <span className="eyebrow text-muted-foreground">
                                        Approvals · {approval.policy_label}
                                    </span>
                                    {approval.waiting_on.length > 0 ? (
                                        <p className="text-sm">
                                            Waiting on{' '}
                                            {approval.waiting_on.join(', ')}.
                                        </p>
                                    ) : (
                                        <p className="text-sm">
                                            Everyone has approved.
                                        </p>
                                    )}
                                </div>
                                <ol className="flex flex-col gap-2">
                                    {approval.steps.map((step, i) => (
                                        <li
                                            key={i}
                                            className="flex items-center gap-2.5 text-sm"
                                        >
                                            <span
                                                className={cn(
                                                    'flex size-5 shrink-0 items-center justify-center rounded-full text-xs',
                                                    step.status === 'approved'
                                                        ? 'bg-success/15 text-success'
                                                        : step.status ===
                                                            'changes_requested'
                                                          ? 'bg-destructive/15 text-destructive'
                                                          : step.is_turn
                                                            ? 'bg-info/15 text-info'
                                                            : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {step.status === 'approved'
                                                    ? '✓'
                                                    : i + 1}
                                            </span>
                                            <span className="font-medium">
                                                {step.approver}
                                            </span>
                                            {step.is_turn &&
                                                step.status === 'pending' && (
                                                    <span className="eyebrow text-info">
                                                        Their turn
                                                    </span>
                                                )}
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        )}

                        {certificate_available && (
                            <a
                                href={`/portal/posts/${post.id}/certificate`}
                                target="_blank"
                                rel="noopener"
                                className="border-border bg-card hover:border-primary/30 flex items-center gap-2 rounded-xl border px-4 py-3 text-sm font-medium transition-colors"
                            >
                                <CheckCircle2 className="text-success size-4" />
                                Download approval certificate
                            </a>
                        )}

                        {can.decide && (
                            <Form
                                {...decideOnPortalPost.form(post.id)}
                                resetOnSuccess={['comment']}
                                className="border-info/25 bg-card ring-info/5 flex flex-col gap-4 rounded-2xl border p-5 ring-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="flex flex-col gap-1">
                                            <span className="eyebrow text-muted-foreground">
                                                Your decision
                                            </span>
                                            <p className="font-display text-xl leading-tight">
                                                How does it look?
                                            </p>
                                        </div>
                                        <div className="grid gap-1.5">
                                            <textarea
                                                ref={commentRef}
                                                name="comment"
                                                rows={3}
                                                aria-label="Note for the studio (optional)"
                                                placeholder="Add a note (optional) — e.g. what you'd like changed"
                                                className="border-input bg-background placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/40 flex w-full rounded-xl border px-3.5 py-2.5 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                                            />
                                            <InputError
                                                message={errors.comment}
                                            />
                                        </div>
                                        <InputError message={errors.decision} />

                                        {/* Step M-6.6: swipe to decide on a phone, additive to the
                                            tap buttons below. */}
                                        <SwipeToApprove
                                            disabled={processing}
                                            onApprove={() =>
                                                router.post(
                                                    decideOnPortalPost.url(
                                                        post.id,
                                                    ),
                                                    {
                                                        decision: 'approved',
                                                        comment:
                                                            commentRef.current
                                                                ?.value ?? '',
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                            onRequestChanges={() =>
                                                router.post(
                                                    decideOnPortalPost.url(
                                                        post.id,
                                                    ),
                                                    {
                                                        decision:
                                                            'changes_requested',
                                                        comment:
                                                            commentRef.current
                                                                ?.value ?? '',
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />

                                        <div className="flex flex-col gap-2.5">
                                            <Button
                                                type="submit"
                                                name="decision"
                                                value="approved"
                                                size="lg"
                                                className="bg-success text-success-foreground hover:bg-success/90 h-13 text-base"
                                                disabled={processing}
                                            >
                                                <CheckCircle2 className="size-5" />
                                                Approve
                                            </Button>
                                            <Button
                                                type="submit"
                                                name="decision"
                                                value="changes_requested"
                                                size="lg"
                                                variant="outline"
                                                className="border-destructive/35 text-destructive hover:border-destructive/60 hover:bg-destructive/5 hover:text-destructive h-13 text-base"
                                                disabled={processing}
                                            >
                                                <MessageSquareWarning className="size-5" />
                                                Request changes
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}

                        {post.targets.length > 0 && (
                            <Panel
                                eyebrow="Schedule"
                                title="Where & when"
                                bodyClassName="p-0"
                            >
                                <ul className="divide-border/70 divide-y">
                                    {post.targets.map((target, i) => {
                                        const Icon = platformIcon(
                                            target.platform,
                                        );

                                        return (
                                            <li
                                                key={i}
                                                className="flex items-center justify-between gap-3 px-5 py-3 text-sm"
                                            >
                                                <span className="flex min-w-0 items-center gap-2">
                                                    <Icon className="text-muted-foreground size-4 shrink-0" />
                                                    <span className="truncate font-medium">
                                                        {target.handle ??
                                                            'Unassigned'}
                                                    </span>
                                                </span>
                                                <span className="text-muted-foreground inline-flex shrink-0 items-center gap-1.5 font-mono text-xs tabular-nums">
                                                    <CalendarClock className="size-3.5" />
                                                    {formatSchedule(
                                                        target.scheduled_local_date,
                                                        target.scheduled_local_time,
                                                    )}
                                                </span>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </Panel>
                        )}

                        <Panel
                            eyebrow="Conversation"
                            title="Feedback & comments"
                            bodyClassName="flex flex-col gap-4"
                        >
                            <FeedbackBoard
                                assets={post.assets}
                                caption={post.master_caption ?? ''}
                                threads={feedback}
                                canComment={can.comment}
                                mode="reviewer"
                                routes={{
                                    create: commentOnPortalPost.url(post.id),
                                    resolve: (id) => resolveComment.url(id),
                                }}
                            />
                        </Panel>
                    </div>
                </div>
            </Page>
        </>
    );
}
