import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarClock,
    CheckCircle2,
    MessageSquareWarning,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';

import { type AssetPreviewData } from '@/components/asset-preview';
import InputError from '@/components/input-error';
import {
    MentionTextarea,
    renderCommentBody,
    type MentionableUser,
} from '@/components/mention-textarea';
import { Page, PageHeader, Panel } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { SocialPostPreview } from '@/components/social-post-preview';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { formatSchedule } from '@/lib/dates';
import type { MediaFormat } from '@/lib/media-format';
import { cn } from '@/lib/utils';
import {
    decide as decideOnPortalPost,
    index as portalPostsIndex,
} from '@/routes/portal/posts';
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

const commentDateFormatter = new Intl.DateTimeFormat('en-US', {
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
});

/**
 * Step 6.5 — the client portal's post detail/approval view: the authenticated counterpart to the
 * token-based `/review/:token` page. The post preview leads; the decision (when the post is
 * `waiting_client`) sits beside it with exactly two large, thumb-friendly choices (guidelines §7).
 */
export default function PortalPostShow({
    post,
    can,
    mentionableUsers,
}: {
    post: PostData;
    can: { decide: boolean; comment: boolean };
    mentionableUsers: MentionableUser[];
}) {
    const previewTargets = post.targets.map((t, i) => ({
        id: t.social_account_id ?? i,
        platform: t.platform ?? '',
        handle: t.handle ?? '',
        publishedCaption: t.published_caption,
        firstComment: t.first_comment,
        format: t.format,
    }));

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

                        {(post.comments.length > 0 || can.comment) && (
                            <Panel
                                eyebrow="Conversation"
                                title={
                                    post.comments.length > 0
                                        ? `${post.comments.length} comment${post.comments.length === 1 ? '' : 's'}`
                                        : 'Start a conversation'
                                }
                                bodyClassName="flex flex-col gap-4"
                            >
                                {post.comments.length > 0 && (
                                    <ol className="flex flex-col gap-3">
                                        {post.comments.map((comment) => (
                                            <li
                                                key={comment.id}
                                                className="border-border bg-background rounded-2xl rounded-tl-sm border p-3.5 text-sm"
                                            >
                                                <p className="whitespace-pre-wrap">
                                                    {renderCommentBody(
                                                        comment.body,
                                                    )}
                                                </p>
                                                <p className="text-muted-foreground mt-2 flex items-center gap-2 text-xs">
                                                    <span className="text-foreground/80 font-medium">
                                                        {comment.user_name ??
                                                            'Team'}
                                                    </span>
                                                    {comment.created_at && (
                                                        <span className="font-mono text-[11px]">
                                                            {commentDateFormatter.format(
                                                                new Date(
                                                                    comment.created_at,
                                                                ),
                                                            )}
                                                        </span>
                                                    )}
                                                </p>
                                            </li>
                                        ))}
                                    </ol>
                                )}

                                {can.comment && (
                                    <Form
                                        {...commentOnPortalPost.form(post.id)}
                                        resetOnSuccess={['body']}
                                        className="flex flex-col gap-2.5"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <MentionTextarea
                                                    name="body"
                                                    rows={3}
                                                    placeholder="Write to the team… type @ to mention someone"
                                                    users={mentionableUsers}
                                                />
                                                <InputError
                                                    message={errors.body}
                                                />
                                                <Button
                                                    type="submit"
                                                    variant="secondary"
                                                    size="sm"
                                                    className="self-end"
                                                    disabled={processing}
                                                >
                                                    Post comment
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                )}
                            </Panel>
                        )}
                    </div>
                </div>
            </Page>
        </>
    );
}
