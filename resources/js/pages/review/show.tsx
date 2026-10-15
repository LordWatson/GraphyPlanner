import { Form, Head } from '@inertiajs/react';
import {
    CalendarClock,
    CheckCircle2,
    MessageSquareWarning,
} from 'lucide-react';

import {
    AssetPreview,
    type AssetPreviewData,
} from '@/components/asset-preview';
import InputError from '@/components/input-error';
import { PublicShell, PublicTitle } from '@/components/public-shell';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';

type AssetData = AssetPreviewData;

type TargetData = {
    platform: string | null;
    handle: string | null;
    scheduled_local_date: string | null;
    scheduled_local_time: string | null;
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
    assets: AssetData[];
    targets: TargetData[];
    can_decide: boolean;
    comments: CommentData[];
};

export default function ReviewShow({
    token,
    client,
    post,
}: {
    token: string;
    client: { name: string };
    post: PostData;
}) {
    return (
        <>
            <Head title={`Review a post — ${client.name}`} />
            <PublicShell width="lg" context={client.name}>
                <PublicTitle
                    kicker={`For ${client.name}`}
                    title={
                        post.can_decide ? (
                            <>
                                Ready for your <em>review</em>.
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

                {post.review_message && (
                    <figure className="bg-ink text-ink-foreground relative rounded-2xl p-6">
                        <span
                            aria-hidden
                            className="text-primary absolute -top-3 left-6 font-serif text-6xl leading-none"
                        >
                            “
                        </span>
                        <blockquote className="font-serif text-xl leading-snug whitespace-pre-wrap">
                            {post.review_message}
                        </blockquote>
                        <figcaption className="eyebrow text-ink-foreground/55 mt-3">
                            A note from the studio
                        </figcaption>
                    </figure>
                )}

                <article className="border-border bg-card shadow-paper overflow-hidden rounded-2xl border">
                    {post.assets.length > 0 && (
                        <div className="border-border bg-muted/40 grid grid-cols-2 gap-1.5 border-b p-1.5 sm:grid-cols-3">
                            {post.assets.map((asset) => (
                                <AssetPreview key={asset.id} asset={asset} />
                            ))}
                        </div>
                    )}

                    <div className="flex flex-col gap-4 p-6">
                        {post.master_caption && (
                            <p className="text-[15px] leading-relaxed whitespace-pre-wrap">
                                {post.master_caption}
                            </p>
                        )}

                        {post.hashtags.length > 0 && (
                            <p className="text-primary text-sm">
                                {post.hashtags
                                    .map((tag) => `#${tag}`)
                                    .join(' ')}
                            </p>
                        )}

                        {post.targets.length > 0 && (
                            <ul className="divide-border/70 border-border flex flex-col divide-y border-t pt-2">
                                {post.targets.map((target, i) => (
                                    <li
                                        key={i}
                                        className="flex items-center justify-between gap-3 py-2 text-sm"
                                    >
                                        <span className="flex items-center gap-2">
                                            <span className="eyebrow text-muted-foreground">
                                                {target.platform}
                                            </span>
                                            <span className="font-medium">
                                                {target.handle}
                                            </span>
                                        </span>
                                        <span className="text-muted-foreground inline-flex items-center gap-1.5 font-mono text-xs tabular-nums">
                                            <CalendarClock className="size-3.5" />
                                            {target.scheduled_local_date}{' '}
                                            {target.scheduled_local_time}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </article>

                {post.comments.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <h2 className="eyebrow text-muted-foreground">
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

                {post.can_decide ? (
                    <Form
                        action={`/review/${token}`}
                        method="post"
                        className="border-border bg-card flex flex-col gap-4 rounded-2xl border p-5 sm:p-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <p className="font-serif text-2xl leading-tight">
                                    How does it look?
                                </p>
                                <div className="grid gap-1.5">
                                    <textarea
                                        name="comment"
                                        rows={3}
                                        placeholder="Add a note (optional) — e.g. what you'd like changed"
                                        className="border-input bg-background placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/40 flex w-full rounded-xl border px-3.5 py-2.5 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                                    />
                                    <InputError message={errors.comment} />
                                </div>
                                <InputError message={errors.decision} />
                                <div className="flex flex-col gap-3 sm:flex-row-reverse">
                                    <Button
                                        type="submit"
                                        name="decision"
                                        value="approved"
                                        size="lg"
                                        className="bg-success text-success-foreground hover:bg-success/90 h-14 flex-1 text-base"
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
                                        className="border-destructive/35 text-destructive hover:border-destructive/60 hover:bg-destructive/5 h-14 flex-1 text-base"
                                        disabled={processing}
                                    >
                                        <MessageSquareWarning className="size-5" />
                                        Request changes
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                ) : (
                    <div className="border-border text-muted-foreground flex items-center gap-3 rounded-2xl border border-dashed p-5 text-sm">
                        <CheckCircle2 className="text-success size-5 shrink-0" />
                        This post has already been reviewed — no further action
                        is needed.
                    </div>
                )}
            </PublicShell>
        </>
    );
}
