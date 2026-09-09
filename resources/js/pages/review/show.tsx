import { Form, Head } from '@inertiajs/react';
import { CheckCircle2, MessageSquareWarning } from 'lucide-react';

import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type AssetData = {
    id: number;
    url: string | null;
    original_filename: string | null;
    type: string | null;
};

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

const postStatusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline' | 'destructive'> = {
    waiting_client: 'warning',
    changes_requested: 'destructive',
    approved: 'success',
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
            <div className="relative flex min-h-screen flex-col overflow-hidden bg-background text-foreground">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[420px] bg-gradient-brand opacity-[0.10] blur-3xl"
                />

                <header className="mx-auto flex w-full max-w-2xl items-center gap-2 px-6 py-8">
                    <span className="flex size-9 items-center justify-center rounded-lg bg-gradient-brand shadow-lg shadow-primary/20">
                        <AppLogoIcon className="size-5 fill-current text-white" />
                    </span>
                    <span className="text-lg font-semibold">Graphy</span>
                </header>

                <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-6 px-6 pb-16">
                    <div className="flex flex-col gap-2">
                        <p className="text-sm text-muted-foreground">Reviewing for {client.name}</p>
                        <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                            <span className="text-gradient-brand">Content ready for your review</span>
                        </h1>
                    </div>

                    <div className="rounded-xl border border-border bg-card p-6 shadow-lg shadow-black/[0.03]">
                        <div className="mb-4 flex items-center justify-between gap-2">
                            <Badge variant={postStatusVariant[post.status] ?? 'outline'}>{post.status_label}</Badge>
                        </div>

                        {post.review_message && (
                            <div className="mb-4 rounded-md border border-primary/20 bg-primary/5 p-3 text-sm leading-relaxed whitespace-pre-wrap">
                                {post.review_message}
                            </div>
                        )}

                        {post.master_caption && (
                            <p className="mb-4 text-sm leading-relaxed whitespace-pre-wrap">{post.master_caption}</p>
                        )}

                        {post.hashtags.length > 0 && (
                            <p className="mb-4 text-sm text-primary">{post.hashtags.map((tag) => `#${tag}`).join(' ')}</p>
                        )}

                        {post.assets.length > 0 && (
                            <div className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                {post.assets.map((asset) =>
                                    asset.type === 'upload' && asset.url ? (
                                        <img
                                            key={asset.id}
                                            src={asset.url}
                                            alt={asset.original_filename ?? ''}
                                            className="aspect-square w-full rounded-md border border-border object-cover"
                                        />
                                    ) : (
                                        <div
                                            key={asset.id}
                                            className="flex aspect-square w-full items-center justify-center rounded-md border border-border bg-muted/30 p-2 text-center text-xs text-muted-foreground"
                                        >
                                            {asset.original_filename ?? 'Attachment'}
                                        </div>
                                    ),
                                )}
                            </div>
                        )}

                        {post.targets.length > 0 && (
                            <div className="flex flex-wrap gap-2">
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
                    </div>

                    {post.comments.length > 0 && (
                        <div className="rounded-xl border border-border bg-card p-6 shadow-lg shadow-black/[0.03]">
                            <p className="mb-3 text-sm font-medium">Comments</p>
                            <div className="flex flex-col gap-3">
                                {post.comments.map((comment) => (
                                    <div key={comment.id} className="rounded-md border border-border bg-muted/20 p-3 text-sm">
                                        <p className="whitespace-pre-wrap">{comment.body}</p>
                                        <p className="mt-1 text-xs text-muted-foreground">{comment.user_name ?? 'Team'}</p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {post.can_decide ? (
                        <Form
                            action={`/review/${token}`}
                            method="post"
                            className="grid gap-4 rounded-xl border border-border bg-card p-6 shadow-lg shadow-black/[0.03]"
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
                    ) : (
                        <div className="rounded-xl border border-dashed border-border bg-muted/20 p-6 text-center text-sm text-muted-foreground">
                            This post has already been reviewed — no further action is needed.
                        </div>
                    )}
                </main>

                <footer className="mx-auto w-full max-w-2xl px-6 py-6 text-center text-xs text-muted-foreground">
                    &copy; {new Date().getFullYear()} Graphy. All rights reserved.
                </footer>
            </div>
        </>
    );
}
