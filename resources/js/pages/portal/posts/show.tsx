import { Form } from '@inertiajs/react';
import { CheckCircle2, Facebook, Instagram, Linkedin, MessageSquareWarning, Music2, Share2 } from 'lucide-react';
import { useState } from 'react';

import { type AssetPreviewData } from '@/components/asset-preview';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { MentionTextarea, renderCommentBody, type MentionableUser } from '@/components/mention-textarea';
import { SocialPostPreview } from '@/components/social-post-preview';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ClientPortalLayout from '@/layouts/client-portal/client-portal-layout';
import { cn } from '@/lib/utils';
import { store as commentOnPortalPost } from '@/routes/portal/posts/comments';
import { decide as decideOnPortalPost } from '@/routes/portal/posts';

type AssetData = AssetPreviewData;

type TargetData = {
    social_account_id: number | null;
    platform: string | null;
    handle: string | null;
    scheduled_local_date: string | null;
    scheduled_local_time: string | null;
};

const platformIcon: Record<string, typeof Instagram> = {
    instagram: Instagram,
    tiktok: Music2,
    facebook: Facebook,
    linkedin: Linkedin,
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
    assets: AssetData[];
    targets: TargetData[];
    comments: CommentData[];
};

const postStatusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline' | 'destructive'> = {
    waiting_client: 'warning',
    changes_requested: 'destructive',
    approved: 'success',
    published: 'success',
    failed: 'destructive',
};

/**
 * Step 6.5 — the client portal's post detail/approval view: the authenticated counterpart to the
 * token-based `/review/:token` page, letting a logged-in client contact approve, request changes,
 * and leave (non-internal) comments on their own client's posts.
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
    // Live preview targets: the post's saved targets, each paired with its platform/handle so
    // `SocialPostPreview` can render the right mockup — mirrors the read-only path of the
    // internal editor's live preview (`posts/edit.tsx`).
    const previewTargets = post.targets.map((t, i) => ({
        id: t.social_account_id ?? i,
        platform: t.platform ?? '',
        handle: t.handle ?? '',
    }));

    const [previewTargetId, setPreviewTargetId] = useState<number | string | null>(previewTargets[0]?.id ?? null);
    const activePreviewTarget = previewTargets.find((t) => t.id === previewTargetId) ?? previewTargets[0] ?? null;

    return (
        <ClientPortalLayout title={`Post #${post.id}`}>
            <Heading title={post.master_caption ? 'Post review' : `Post #${post.id}`} />

            <div className="mt-6 rounded-xl border border-border bg-card p-6 shadow-lg shadow-black/[0.03]">
                <div className="mb-4 flex items-center justify-between gap-2">
                    <Badge variant={postStatusVariant[post.status] ?? 'outline'}>{post.status_label}</Badge>
                </div>

                {post.review_message && (
                    <div className="mb-4 rounded-md border border-primary/20 bg-primary/5 p-3 text-sm leading-relaxed whitespace-pre-wrap">
                        {post.review_message}
                    </div>
                )}

                {post.targets.length > 0 && (
                    <div className="mb-4 flex flex-wrap gap-2">
                        {post.targets.map((target, i) => (
                            <span
                                key={i}
                                className="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted/50 px-2.5 py-1 text-xs text-muted-foreground"
                            >
                                {target.handle} · {target.scheduled_local_date} {target.scheduled_local_time}
                            </span>
                        ))}
                    </div>
                )}

                {activePreviewTarget ? (
                    <>
                        {previewTargets.length > 1 && (
                            <div className="mb-3 flex flex-wrap gap-1.5">
                                {previewTargets.map((t) => {
                                    const Icon = platformIcon[t.platform] ?? Share2;
                                    const selected = t.id === activePreviewTarget?.id;

                                    return (
                                        <button
                                            key={t.id}
                                            type="button"
                                            onClick={() => setPreviewTargetId(t.id)}
                                            className={cn(
                                                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors',
                                                selected
                                                    ? 'border-primary bg-primary/10 text-primary'
                                                    : 'border-border bg-muted/50 text-foreground',
                                            )}
                                        >
                                            <Icon className="size-3.5" />
                                            {t.handle}
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                        <div className="flex justify-center">
                            <SocialPostPreview
                                target={activePreviewTarget}
                                caption={post.master_caption ?? ''}
                                hashtags={post.hashtags}
                                assets={post.assets}
                                musicName={post.music?.name ?? ''}
                                locationName={post.location?.name ?? ''}
                            />
                        </div>
                    </>
                ) : (
                    <p className="text-sm text-muted-foreground">No target account is set for this post yet.</p>
                )}
            </div>

            {post.comments.length > 0 && (
                <div className="mt-6 rounded-xl border border-border bg-card p-6 shadow-lg shadow-black/[0.03]">
                    <p className="mb-3 text-sm font-medium">Comments</p>
                    <div className="flex flex-col gap-3">
                        {post.comments.map((comment) => (
                            <div key={comment.id} className="rounded-md border border-border bg-muted/20 p-3 text-sm">
                                <p className="whitespace-pre-wrap">{renderCommentBody(comment.body)}</p>
                                <p className="mt-1 text-xs text-muted-foreground">{comment.user_name ?? 'Team'}</p>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {can.decide && (
                <Form
                    {...decideOnPortalPost.form(post.id)}
                    resetOnSuccess={['comment']}
                    className="mt-6 grid gap-4 rounded-xl border border-border bg-card p-6 shadow-lg shadow-black/[0.03]"
                >
                    {({ processing, errors }) => (
                        <>
                            <p className="text-sm font-medium">What would you like to do with this post?</p>
                            <InputError message={errors.decision} />
                            <div className="flex flex-col gap-3 sm:flex-row">
                                <Button
                                    type="submit"
                                    name="decision"
                                    value="approved"
                                    size="lg"
                                    className="flex-1 shadow-lg shadow-primary/20"
                                    disabled={processing}
                                >
                                    <CheckCircle2 className="size-4" />
                                    Approve
                                </Button>
                                <Button
                                    type="submit"
                                    name="decision"
                                    value="changes_requested"
                                    size="lg"
                                    variant="outline"
                                    className="flex-1 border-destructive/40 text-destructive hover:bg-destructive/10"
                                    disabled={processing}
                                >
                                    <MessageSquareWarning className="size-4" />
                                    Request changes
                                </Button>
                            </div>
                            <div className="grid gap-1">
                                <textarea
                                    name="comment"
                                    rows={3}
                                    placeholder="Add a note (optional) — e.g. what you'd like changed"
                                    className="border-input dark:bg-input/30 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                                />
                                <InputError message={errors.comment} />
                            </div>
                        </>
                    )}
                </Form>
            )}

            {can.comment && (
                <Form
                    {...commentOnPortalPost.form(post.id)}
                    resetOnSuccess={['body']}
                    className="mt-6 grid gap-3 rounded-xl border border-border bg-card p-6 shadow-lg shadow-black/[0.03]"
                >
                    {({ processing, errors }) => (
                        <>
                            <p className="text-sm font-medium">Add a comment</p>
                            <MentionTextarea
                                name="body"
                                rows={3}
                                placeholder="Write a comment for the team… type @ to mention someone"
                                users={mentionableUsers}
                            />
                            <InputError message={errors.body} />
                            <Button type="submit" size="sm" className="self-start" disabled={processing}>
                                Post comment
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </ClientPortalLayout>
    );
}
